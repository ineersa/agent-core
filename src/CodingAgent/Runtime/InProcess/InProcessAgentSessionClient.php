<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\InProcess;

use Ineersa\AgentCore\Application\Handler\RunRewindService;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\CursorAwareEventStoreInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Mcp\McpSessionLifecycleDispatcher;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateService;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\CursorAwareAgentSessionClientInterface;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextDiscovery;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextRenderer;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionAnswerResolver;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface;

/**
 * In-process implementation of AgentSessionClient.
 *
 * Calls agent-core services directly within the same process.
 * All data is still mapped through RuntimeEvent protocol DTOs so that
 * TUI code never sees agent-core domain objects.
 *
 * This is the default transport during development. It must stay
 * behaviorally equivalent to JsonlProcessAgentSessionClient.
 */
final class InProcessAgentSessionClient implements AgentSessionClient, CursorAwareAgentSessionClientInterface
{
    public function __construct(
        private readonly AgentRunnerInterface $runner,
        private readonly EventStoreInterface $eventStore,
        private readonly RuntimeEventMapper $mapper,
        private readonly RunRewindService $runRewindService,
        private readonly SystemPromptBuilder $systemPromptBuilder,
        private readonly AgentsContextDiscovery $agentsContextDiscovery,
        private readonly AgentsContextRenderer $agentsContextRenderer,
        private readonly SkillsContextBuilder $skillsContextBuilder,
        private readonly AgentsContextBuilder $agentsContextBuilder,
        private readonly PromptTemplateService $promptTemplateService,
        private readonly SessionMetadataStore $sessionMetaStore,
        private readonly ModelResolver $modelResolver,
        private readonly ?RuntimeEventSinkInterface $transientSink = null,
        private readonly ?ToolQuestionStoreInterface $toolQuestionStore = null,
        private readonly ToolQuestionAnswerResolver $answerResolver = new ToolQuestionAnswerResolver(),
        private readonly ?ToolExecutorInterface $toolExecutor = null,
        private readonly ?McpSessionLifecycleDispatcher $mcpDispatcher = null,
    ) {
    }

    public function start(StartRunRequest $request): RunHandle
    {
        $metadata = null !== $request->model || null !== $request->reasoning
            ? new RunMetadata(model: $request->model, reasoning: $request->reasoning)
            : null;

        $messages = [];

        // Build and prepend the system prompt as the first message.
        // This ensures the model receives system instructions before user input.
        // CWD is sourced from AppConfig (bootstrap-resolved working directory).
        $systemPromptText = $this->systemPromptBuilder->build();
        if ('' !== $systemPromptText) {
            $messages[] = new AgentMessage(
                role: 'system',
                content: [['type' => 'text', 'text' => $systemPromptText]],
            );
        }

        // Discover and inject AGENTS.md project context as a synthetic user-context
        // message (between system prompt and real user message). Only on new sessions.
        // Note: Both the InProcess and JsonlProcess (controller subprocess) session
        // client paths flow through this method — the controller's StartRunHandler
        // delegates directly to this client. So a single injection point covers both.
        $agentsContext = $this->agentsContextDiscovery->discover();
        if ([] !== $agentsContext) {
            $contextText = $this->agentsContextRenderer->render($agentsContext);
            $messages[] = new AgentMessage(
                role: 'user-context',
                content: [['type' => 'text', 'text' => $contextText]],
                metadata: ['source' => 'agents_context', 'files' => array_column($agentsContext, 'path')],
            );
        }

        // Discover and inject skills context as a synthetic user-context message.
        // Skills are discovered from configured paths, rendered into
        // <skills_instructions> and <available_skills> blocks, and added
        // between the AGENTS.md context and the user message. Only on new sessions.
        $skillsContext = $this->skillsContextBuilder->build();
        if ('' !== $skillsContext) {
            $messages[] = new AgentMessage(
                role: 'user-context',
                content: [['type' => 'text', 'text' => $skillsContext]],
                metadata: ['source' => 'skills_context'],
            );
        }

        // Discover and inject available agent definitions for the parent model.
        // Rendered into <agents_instructions> and <available_agents> blocks between
        // skills context and the user message. Only on new sessions.
        $availableAgentsContext = $this->agentsContextBuilder->build();
        if ('' !== $availableAgentsContext) {
            $messages[] = new AgentMessage(
                role: 'user-context',
                content: [['type' => 'text', 'text' => $availableAgentsContext]],
                metadata: ['source' => 'agents_definitions_context'],
            );
        }

        // Expand prompt templates in the user input before passing to the model.
        // Single-pass expansion: if a template body starts with "/other", it
        // is NOT expanded again — the model receives the literal text.
        $prompt = $this->promptTemplateService->expandPromptTemplate($request->prompt);

        if ('' !== $prompt) {
            $messages[] = new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => $prompt]],
            );
        }

        // Persist session-scoped model/reasoning selection so resume restores it.
        // Session-start is NOT a global-default change (unlike /model mid-session,
        // which intentionally also updates the home default via ModelSettingsPersister).
        // Guard: only persist when a session row already exists ($request->runId !== ''),
        // since HatfieldSessionStore::updateMetadata() is a no-op for unknown sessions
        // and sessions are created lazily by createSession()/SessionInitializer before start().
        //
        // Two-tier resolution mirrors what the LLM worker uses on turn 1 via
        // SessionAwareModelResolver → ModelResolver.  Explicit request values
        // (--model / /new --model) take the fast catalog-free parse path; when
        // absent, the effective model/reasoning is resolved via ModelResolver so
        // the session is pinned to what the first turn actually used.  This makes
        // resume stable even if the global default later changes.
        $sessionId = $request->runId;
        if ('' !== $sessionId) {
            $modelRef = null !== $request->model
                ? AiModelReference::tryParse($request->model)
                : $this->modelResolver->resolveInitialModel(null, $sessionId);

            $reasoning = null;
            if (null !== $request->reasoning && \in_array($request->reasoning, ModelResolver::LEVELS, true)) {
                $reasoning = $request->reasoning;
            } else {
                $reasoning = $this->modelResolver->resolveInitialReasoning(null, $sessionId);
            }

            $metaFields = [];
            if (null !== $modelRef) {
                $metaFields['model'] = $modelRef->toString();
                $metaFields['model_provider'] = $modelRef->providerId;
                $metaFields['model_name'] = $modelRef->modelName;
            }
            if ('' !== $reasoning) {
                $metaFields['reasoning'] = $reasoning;
            }
            if ([] !== $metaFields) {
                $this->sessionMetaStore->writeSessionMetadata($sessionId, $metaFields);
            }
        }

        $input = new StartRunInput(
            systemPrompt: '',
            messages: $messages,
            runId: '' !== $request->runId ? $request->runId : null,
            metadata: $metadata,
        );

        $runId = $this->runner->start($input);

        // Dispatch MCP session initialize after the run has started.
        // Failure is non-fatal — MCP is optional infrastructure.
        $this->mcpDispatcher?->dispatchInitialize($runId, 'start_run');

        return new RunHandle(runId: $runId, status: 'running');
    }

    public function attach(string $runId): RunHandle
    {
        // Passive attach only — do not call runner->continue(); opening a session
        // must not reanimate or advance AgentCore state.

        // Dispatch MCP session initialize on attach so reopened sessions
        // also benefit from MCP tools (Phase 3+).
        $this->mcpDispatcher?->dispatchInitialize($runId, 'attach');

        return new RunHandle(runId: $runId, status: 'attached');
    }

    public function send(string $runId, UserCommand $command): void
    {
        $text = $command->text ?? '';

        // Expand prompt templates for user-initiated interactive commands.
        // answer_human, answer_tool_question, and shell_command carry
        // machine/payload answers — they are NOT expanded.
        if (\in_array($command->type, ['message', 'steer', 'follow_up'], true)) {
            $text = $this->promptTemplateService->expandPromptTemplate($text);
        }

        match ($command->type) {
            'steer', 'message' => $this->runner->steer(
                $runId,
                new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => $text]],
                ),
            ),
            'follow_up' => $this->runner->followUp(
                $runId,
                new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => $text]],
                ),
            ),
            'append_message' => $this->runner->appendMessage(
                $runId,
                new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => $text]],
                ),
            ),
            'answer_human' => $this->runner->answerHuman(
                $runId,
                (string) ($command->payload['question_id'] ?? ''),
                $command->payload['answer'] ?? null,
            ),
            'answer_tool_question' => $this->handleAnswerToolQuestion($runId, $command),
            'shell_command' => $this->handleShellCommandSend($runId, $command),
            'rewind_to_turn' => $this->handleInProcessRewind($runId, $command),
            default => throw new \InvalidArgumentException(\sprintf('Unknown UserCommand type: "%s"', $command->type)),
        };
    }

    public function eventsAfter(string $runId, int $afterSeq): iterable
    {
        if (null !== $this->transientSink && $this->transientSink instanceof InMemoryRuntimeEventSink) {
            yield from $this->transientSink->drain($runId);
        }

        if ($this->eventStore instanceof CursorAwareEventStoreInterface) {
            foreach ($this->eventStore->allForAfter($runId, $afterSeq) as $runEvent) {
                $runtimeEvent = $this->mapper->toRuntimeEvent($runEvent);
                if (null !== $runtimeEvent) {
                    yield $runtimeEvent;
                }
            }

            return;
        }

        foreach ($this->eventStore->allFor($runId) as $runEvent) {
            if ($runEvent->seq <= $afterSeq) {
                continue;
            }

            $runtimeEvent = $this->mapper->toRuntimeEvent($runEvent);
            if (null !== $runtimeEvent) {
                yield $runtimeEvent;
            }
        }
    }

    public function events(string $runId): iterable
    {
        yield from $this->eventsAfter($runId, 0);
    }

    public function cancel(string $runId): void
    {
        $this->runner->cancel($runId);
    }

    public function shellExecute(string $command, string $sessionId, string $cwd): RunHandle
    {
        $runId = $sessionId;

        // Shell commands write tool_execution events directly to the
        // event store. Execute bash now so the events are available
        // when the TUI polls events().
        $this->executeShellCommand($runId, new UserCommand(
            type: 'shell_command',
            text: $command,
        ));

        // Emit a terminal AgentEnd event so the TUI poller transitions
        // from Running to Completed and clears the working indicator.
        // Without this, the ActivityStateMachine never leaves Running
        // because standalone shell commands only emit tool_execution
        // events (no RunCompleted / AgentEnd).
        $this->completeRun($runId);

        return new RunHandle(runId: $runId, status: 'completed');
    }

    public function completeRun(string $runId): void
    {
        $existingEvents = $this->eventStore->allFor($runId);
        $nextSeq = [] !== $existingEvents
            ? max(array_map(static fn (RunEvent $e): int => $e->seq, $existingEvents)) + 1
            : 1;

        $this->eventStore->append(new RunEvent(
            runId: $runId,
            seq: $nextSeq,
            turnNo: 0,
            type: RunEventTypeEnum::AgentEnd->value,
            payload: ['reason' => 'completed'],
        ));
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
        $this->runner->compact($runId, $customInstructions);
    }

    /**
     * Handle an answer_tool_question command by writing the answer
     * to the ToolQuestionStore. The blocked tool worker polls the store
     * and will pick up the answer.
     */
    private function handleAnswerToolQuestion(string $runId, UserCommand $command): void
    {
        if (null === $this->toolQuestionStore) {
            throw new \RuntimeException('ToolQuestionStore not configured; cannot handle answer_tool_question command.');
        }

        $requestId = (string) ($command->payload['request_id'] ?? '');

        if ('' === $requestId) {
            throw new \InvalidArgumentException('answer_tool_question requires request_id in payload');
        }

        $answer = $this->answerResolver->resolve($command->payload['answer'] ?? null);
        $this->toolQuestionStore->answer($requestId, $answer);
    }

    private function handleInProcessRewind(string $runId, UserCommand $command): void
    {
        $targetTurnNo = (int) ($command->payload['turn_no'] ?? 0);

        $result = $this->runRewindService->rewind($runId, $targetTurnNo);

        // Emit RunLeafChanged RuntimeEvent so the TUI observes the leaf change
        // and rebuilds the transcript.  This mirrors the RewindToTurnHandler
        // emission in process-mode; both must stay in sync.
        if ($this->transientSink instanceof InMemoryRuntimeEventSink) {
            $this->transientSink->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::RunLeafChanged->value,
                runId: $runId,
                seq: $result['leafSetSeq'],
                payload: [
                    'turn_no' => $targetTurnNo,
                    'leaf_set_seq' => $result['leafSetSeq'],
                ],
            ));
        }
    }

    /**
     * Execute a shell command submitted via the ! prefix.
     *
     * Executes bash through the shared tool executor path, persists
     * tool_execution_start / tool_execution_end events into the canonical
     * event store, and does NOT add output to model context or trigger
     * an LLM turn.
     *
     * When no ToolExecutor is configured (null), a diagnostic error
     * event is emitted instead so the user sees a clear message.
     */
    private function handleShellCommandSend(string $runId, UserCommand $command): void
    {
        $this->executeShellCommand($runId, $command);

        if ((bool) ($command->payload['standalone'] ?? false)) {
            $this->completeRun($runId);
        }
    }

    private function executeShellCommand(string $runId, UserCommand $command): void
    {
        $commandText = $command->text ?? '';

        if ('' === $commandText) {
            return;
        }

        $toolCallId = uniqid('sh_', true);

        // Compute next sequence numbers for this run by inspecting existing
        // events in the store.
        $existingEvents = $this->eventStore->allFor($runId);
        $nextSeq = [] !== $existingEvents
            ? max(array_map(static fn (RunEvent $e): int => $e->seq, $existingEvents)) + 1
            : 1;

        // Emit tool_execution_start event.
        $this->eventStore->append(new RunEvent(
            runId: $runId,
            seq: $nextSeq,
            turnNo: 0,
            type: RunEventTypeEnum::ToolExecutionStart->value,
            payload: [
                'tool_call_id' => $toolCallId,
                'tool_name' => 'bash',
                'order_index' => 0,
            ],
        ));

        if (null === $this->toolExecutor) {
            // No ToolExecutor configured — emit a diagnostic error event.
            $this->eventStore->append(new RunEvent(
                runId: $runId,
                seq: $nextSeq + 1,
                turnNo: 0,
                type: RunEventTypeEnum::ToolExecutionEnd->value,
                payload: [
                    'tool_call_id' => $toolCallId,
                    'is_error' => true,
                    'result' => 'Shell command execution unavailable: ToolExecutor not configured.',
                ],
            ));

            return;
        }

        // Execute bash through the shared tool executor.
        // Uses a synthetic ToolCall so cancellation/timeout/approval hooks
        // from the existing tool infrastructure apply.
        $result = $this->toolExecutor->execute(new ToolCall(
            toolCallId: $toolCallId,
            toolName: 'bash',
            arguments: ['command' => $commandText],
            orderIndex: 0,
            runId: $runId,
        ));

        // Extract the result text from the ToolResult's content blocks.
        $resultText = '';
        foreach ($result->content as $contentBlock) {
            if (\is_array($contentBlock) && 'text' === ($contentBlock['type'] ?? '')) {
                $resultText .= (string) ($contentBlock['text'] ?? '');
            } elseif (\is_string($contentBlock)) {
                $resultText .= $contentBlock;
            }
        }

        // Emit tool_execution_end event with result text.
        $this->eventStore->append(new RunEvent(
            runId: $runId,
            seq: $nextSeq + 1,
            turnNo: 0,
            type: RunEventTypeEnum::ToolExecutionEnd->value,
            payload: [
                'tool_call_id' => $toolCallId,
                'is_error' => $result->isError,
                'result' => $resultText,
            ],
        ));
    }
}
