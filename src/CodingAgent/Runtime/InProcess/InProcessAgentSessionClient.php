<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\InProcess;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunMetadata;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextDiscovery;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextRenderer;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
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
final class InProcessAgentSessionClient implements AgentSessionClient
{
    public function __construct(
        private readonly AgentRunnerInterface $runner,
        private readonly EventStoreInterface $eventStore,
        private readonly RuntimeEventMapper $mapper,
        private readonly SystemPromptBuilder $systemPromptBuilder,
        private readonly AgentsContextDiscovery $agentsContextDiscovery,
        private readonly AgentsContextRenderer $agentsContextRenderer,
        private readonly SkillsContextBuilder $skillsContextBuilder,
        private readonly ?RuntimeEventSinkInterface $transientSink = null,
        private readonly ?ToolQuestionStoreInterface $toolQuestionStore = null,
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

        if ('' !== $request->prompt) {
            $messages[] = new AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => $request->prompt]],
            );
        }

        $input = new StartRunInput(
            systemPrompt: '',
            messages: $messages,
            runId: '' !== $request->runId ? $request->runId : null,
            metadata: $metadata,
        );

        $runId = $this->runner->start($input);

        return new RunHandle(runId: $runId, status: 'running');
    }

    public function resume(string $runId): RunHandle
    {
        $this->runner->continue($runId);

        return new RunHandle(runId: $runId, status: 'running');
    }

    public function send(string $runId, UserCommand $command): void
    {
        match ($command->type) {
            'steer', 'message' => $this->runner->steer(
                $runId,
                new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => $command->text ?? '']],
                ),
            ),
            'follow_up' => $this->runner->followUp(
                $runId,
                new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => $command->text ?? '']],
                ),
            ),
            'answer_human' => $this->runner->answerHuman(
                $runId,
                (string) ($command->payload['question_id'] ?? ''),
                $command->payload['answer'] ?? null,
            ),
            'answer_tool_question' => $this->handleAnswerToolQuestion($runId, $command),
            default => throw new \InvalidArgumentException(\sprintf('Unknown UserCommand type: "%s"', $command->type)),
        };
    }

    public function events(string $runId): iterable
    {
        // Yield transient streaming events BEFORE canonical events.
        // During the LLM stream, the RuntimeEventStreamObserver emits
        // thinking/text deltas into the in-memory sink. These arrive
        // before coarse completion events (llm_step_completed) that
        // finalize the message. Yielding transients first ensures the
        // projector sees streaming block creation/updates before the
        // completion handler tries to finalize them, preventing:
        //   - Duplicate assistant blocks (one from completion, one
        //     from later streaming deltas)
        //   - Thinking blocks appearing after the main response
        //   - Empty thinking blocks when deltas arrive too late
        if (null !== $this->transientSink && $this->transientSink instanceof InMemoryRuntimeEventSink) {
            yield from $this->transientSink->drain($runId);
        }

        $runEvents = $this->eventStore->allFor($runId);

        foreach ($runEvents as $runEvent) {
            $runtimeEvent = $this->mapper->toRuntimeEvent($runEvent);
            if (null !== $runtimeEvent) {
                yield $runtimeEvent;
            }
        }
    }

    public function cancel(string $runId): void
    {
        $this->runner->cancel($runId);
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

        $answer = $this->resolveAnswer($command->payload['answer'] ?? null);
        $this->toolQuestionStore->answer($requestId, $answer);
    }

    /**
     * Resolve a boolean answer from various input formats.
     *
     * Accepts: true/false (bool), 'yes'/'no'/'true'/'false'/'1'/'0' (string),
     * 1/0 (int). Everything else is treated as false.
     */
    private function resolveAnswer(mixed $answer): bool
    {
        if (\is_bool($answer)) {
            return $answer;
        }

        if (\is_string($answer)) {
            $lower = strtolower(trim($answer));

            return \in_array($lower, ['yes', 'true', '1'], true);
        }

        if (\is_int($answer)) {
            return 1 === $answer;
        }

        return false;
    }
}
