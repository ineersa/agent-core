<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Entity\ToolQuestion;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface;
use Ineersa\Hatfield\ExtensionApi\Approval\ApprovalAnswerContextDTO;
use Ineersa\Hatfield\ExtensionApi\Approval\ApprovalAnswerHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallDecisionKindEnum;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultDecisionKindEnum;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adapts Hatfield ExtensionApi tool hooks to Symfony AI toolbox lifecycle events.
 *
 * Tool-call hooks are wired to ToolCallRequested, whose native deny/setResult
 * controls can stop registry-backed tool execution before the handler runs.
 *
 * For RequireApproval decisions, this subscriber BLOCKS the tool-worker
 * thread in a polling loop against the ToolQuestion DB table (via
 * ToolQuestionStoreInterface) until the human answers via the TUI. No
 * interrupt result is committed, no WaitingHuman, no extra LLM turn.
 *
 * The blocking poll is generic — ANY extension hook returning RequireApproval
 * triggers this pause. The extension owns its vocabulary, schema, answer
 * outcome mapping, and side-effects via ApprovalAnswerHookInterface:
 * onApprovalAnswered() (side-effects) and resolveApprovalAnswer() (outcome).
 *
 * The ToolQuestion is created with a deterministic requestId derived from
 * the hook identity (class name), runId, and toolCallId — no extension-
 * specific namespace. Message redelivery or crash recovery re-attaches to
 * the existing pending question for idempotency.
 *
 * This subscriber contains ZERO extension-specific knowledge. Adding a new
 * approval-granting extension requires only implementing the ExtensionApi
 * contracts — no changes to this subscriber, the handler, or the TUI.
 *
 * Tool-result hooks are currently observational because Symfony AI's
 * ToolCallSucceeded/ToolCallFailed events expose readonly result/exception data.
 * Replacement decisions are folded into the local context seen by later hooks,
 * but cannot mutate the already-created Symfony AI event result.
 */
final readonly class ExtensionToolHookEventSubscriber implements EventSubscriberInterface
{
    /**
     * Poll interval in microseconds (200ms).
     * Chosen as a balance between prompt responsiveness and DB query load.
     * 200ms is fast enough that the TUI feels responsive when the user answers,
     * while keeping DB polling low enough for a single-controller SQLite setup.
     */
    private const int POLL_INTERVAL_MICROS = 200_000;

    public function __construct(
        private ExtensionHookRegistry $hookRegistry,
        private ToolQuestionStoreInterface $toolQuestionStore,
        private string $cwd,
        private ?StackToolExecutionContextAccessor $contextAccessor = null,
        private ?LoggerInterface $logger = null,
        private ?NoninteractiveChildRunProbe $noninteractiveChildProbe = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ToolCallRequested::class => 'onToolCallRequested',
            ToolCallSucceeded::class => 'onToolCallSucceeded',
            ToolCallFailed::class => 'onToolCallFailed',
        ];
    }

    public function onToolCallRequested(ToolCallRequested $event): void
    {
        $toolCall = $event->getToolCall();
        $context = $this->toolCallContext($toolCall);

        foreach ($this->hookRegistry->toolCallHooks() as $hook) {
            try {
                $decision = $hook->onToolCall($context);
            } catch (\Throwable $exception) {
                $event->setResult(new ToolResult($toolCall, [
                    'denied' => true,
                    'reason' => 'extension_tool_call_hook_failed',
                    'message' => \sprintf('Extension tool-call hook failed: %s', $exception->getMessage()),
                    'error_type' => $exception::class,
                ]));

                return;
            }

            if (ToolCallDecisionKindEnum::Allow === $decision->kind) {
                continue;
            }

            if (ToolCallDecisionKindEnum::Block === $decision->kind) {
                $reason = $decision->reason ?? 'blocked_by_extension_hook';
                $event->setResult(new ToolResult($toolCall, array_replace(
                    [
                        'denied' => true,
                        'reason' => $reason,
                        'message' => \sprintf('Tool "%s" was blocked by extension hook: %s', $toolCall->getName(), $reason),
                    ],
                    $decision->details,
                )));

                return;
            }

            if (ToolCallDecisionKindEnum::ReplaceResult === $decision->kind) {
                $event->setResult(new ToolResult($toolCall, $decision->result));

                return;
            }

            if (ToolCallDecisionKindEnum::RequireApproval === $decision->kind) {
                try {
                    $this->handleRequireApproval($event, $toolCall, $hook, $decision, $context);

                    return;
                } catch (\Throwable $exception) {
                    // If the blocking-poll handler fails (e.g., missing context
                    // in tests, DB error), produce a denied result instead of
                    // letting the exception crash the tool execution pipeline.
                    // In production, this should never happen — runId and
                    // toolCallId are always set by the ToolExecutor.
                    $event->setResult(new ToolResult($toolCall, [
                        'denied' => true,
                        'reason' => 'approval_handler_failed',
                        'message' => \sprintf(
                            'Tool "%s" was denied: the approval handler failed (%s).',
                            $toolCall->getName(),
                            $exception->getMessage(),
                        ),
                        'error_type' => $exception::class,
                    ]));

                    $this->logger?->error('tool.approval_handler_failed', [
                        'component' => 'extension.tool_hook_subscriber',
                        'event_type' => 'tool.approval_handler_failed',
                        'tool_name' => $toolCall->getName(),
                        'tool_call_id' => $toolCall->getId(),
                        'error' => $exception->getMessage(),
                    ]);

                    return;
                }
            }
        }
    }

    public function onToolCallSucceeded(ToolCallSucceeded $event): void
    {
        $this->runResultHooks(
            toolCall: $event->getResult()->getToolCall(),
            isError: false,
            rawResult: $event->getResult()->getResult(),
            details: ['raw_result' => $event->getResult()->getResult()],
        );
    }

    public function onToolCallFailed(ToolCallFailed $event): void
    {
        $this->runResultHooks(
            toolCall: new ToolCall(
                id: '',
                name: $event->getDefinition()->getName(),
                arguments: $event->getArguments(),
            ),
            isError: true,
            rawResult: $event->getException()->getMessage(),
            details: [
                'error_type' => $event->getException()::class,
                'message' => $event->getException()->getMessage(),
            ],
        );
    }

    /**
     * Handle a RequireApproval decision by blocking-polling the ToolQuestion DB
     * for a human answer. The tool-worker thread suspends here until the answer
     * arrives via answer_tool_question from the controller/TUI.
     *
     * The poll creates or re-attaches to a ToolQuestion keyed by a deterministic
     * requestId derived from the hook identity, runId, and toolCallId — no
     * extension-specific namespace. The hook's onApprovalAnswered (side-effects)
     * and resolveApprovalAnswer (outcome) are called with the raw answer text.
     * The returned ToolCallDecisionDTO is applied generically: Allow (handler
     * runs), Block (denied result), or ReplaceResult (supplied result).
     *
     * This is fully OCP — any extension implementing ApprovalAnswerHookInterface
     * can drive its own approval vocabulary and outcome mapping with zero changes
     * to this subscriber.
     */
    private function handleRequireApproval(
        ToolCallRequested $event,
        ToolCall $toolCall,
        \Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface $hook,
        ToolCallDecisionDTO $decision,
        ToolCallContextDTO $context,
    ): void {
        if (true === ($context->metadata['noninteractive_child_run'] ?? false)) {
            $reason = $decision->reason ?? 'approval_denied_noninteractive_child';
            $event->setResult(new ToolResult($toolCall, array_replace(
                [
                    'denied' => true,
                    'reason' => $reason,
                    'message' => \sprintf(
                        'Tool "%s" was denied: human approval is not available for noninteractive child subagent runs.',
                        $toolCall->getName(),
                    ),
                    'auto_denied' => true,
                    'noninteractive_child_run' => true,
                ],
                $decision->details,
            )));

            $this->logger?->info('tool.approval_denied_noninteractive_child', [
                'run_id' => $context->runId ?? '',
                'component' => 'extension.tool_hook_subscriber',
                'event_type' => 'tool.approval_denied_noninteractive_child',
                'tool_call_id' => $toolCall->getId(),
                'tool_name' => $toolCall->getName(),
                'hook_class' => $hook::class,
            ]);

            return;
        }

        $runId = $context->runId ?? '';
        $toolCallId = $toolCall->getId();
        $details = $decision->details;
        $questionId = (string) ($details['question_id'] ?? '');
        $prompt = (string) ($details['prompt'] ?? 'Approval required.');
        $schema = $details['schema'] ?? ['type' => 'string'];

        // Deterministic requestId for idempotent re-attach on crash recovery
        // or message redelivery. Uses a hash of the hook identity + runId +
        // toolCallId so every hook gets its own namespace without extension-
        // specific literals.
        $hookId = hash('crc32b', $hook::class);
        $requestId = \sprintf('%s_%s_%s', $hookId, $runId, $toolCallId);

        // Look for an existing pending ToolQuestion (crash recovery / redelivery).
        $question = $this->toolQuestionStore->findByRequestId($requestId);

        if (null === $question) {
            // Create a new pending tool question for this approval.
            // The kind is generic ('approval') — no extension is named.
            $kind = 'approval';

            $question = ToolQuestion::create(
                requestId: $requestId,
                runId: $runId,
                toolCallId: $toolCallId,
                toolName: $toolCall->getName(),
                pid: 0,
                logPath: '',
                commandPreview: '',
                prompt: $prompt,
                kind: $kind,
                schema: \is_array($schema) ? json_encode($schema, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) : null,
            );

            $this->toolQuestionStore->create($question);

            $this->logger?->info('tool.approval_question_created', [
                'run_id' => $runId,
                'component' => 'extension.tool_hook_subscriber',
                'event_type' => 'tool.approval_question_created',
                'request_id' => $requestId,
                'tool_call_id' => $toolCallId,
                'hook_class' => $hook::class,
            ]);
        } else {
            // Re-attach to existing pending question (crash recovery / redelivery).
            if ($question->isResolved()) {
                // The question was already answered in a previous delivery.
                // Process the existing answer directly, no blocking.
                $this->processResolvedAnswer($event, $toolCall, $hook, $question, $details);

                return;
            }

            $this->logger?->info('tool.approval_question_reused', [
                'run_id' => $runId,
                'component' => 'extension.tool_hook_subscriber',
                'event_type' => 'tool.approval_question_reused',
                'request_id' => $requestId,
                'tool_call_id' => $toolCallId,
                'hook_class' => $hook::class,
            ]);
        }

        // ── BLOCKING POLL ──
        // The tool-worker thread blocks here until the TUI user answers
        // via the answer_tool_question command (written by AnswerToolQuestionHandler
        // in the controller process). The run lock (RunState CAS) serializes
        // per-run, so no other tool worker is racing on the same question.
        //
        // No timeout, no TTL. The run stays halted at the tool-execution stage
        // for as long as the poll blocks. The idempotent re-attach above handles
        // crash recovery if this process is killed.
        //
        // With schema-driven routing in AnswerToolQuestionHandler, a string/enum-
        // schema question is always answered via answerWithText, so answer_text
        // can never be null for such questions. The old Layer-C shape-mismatch
        // branch (answered-but-textless -> Deny) is therefore unreachable and
        // removed.
        $this->logger?->info('tool.approval_polling_start', [
            'run_id' => $runId,
            'component' => 'extension.tool_hook_subscriber',
            'event_type' => 'tool.approval_polling_start',
            'request_id' => $requestId,
            'tool_call_id' => $toolCallId,
        ]);

        do {
            usleep(self::POLL_INTERVAL_MICROS);
            $answerText = $this->toolQuestionStore->pollAnswerText($requestId);
        } while (null === $answerText);

        $this->logger?->info('tool.approval_polling_complete', [
            'run_id' => $runId,
            'component' => 'extension.tool_hook_subscriber',
            'event_type' => 'tool.approval_polling_complete',
            'request_id' => $requestId,
            'answer' => $answerText,
        ]);

        // ── ANSWER ROUTING ──
        // onApprovalAnswered for side-effects (e.g. Always-allow policy persistence).
        if ($hook instanceof ApprovalAnswerHookInterface && '' !== $questionId) {
            $hook->onApprovalAnswered(new ApprovalAnswerContextDTO(
                questionId: $questionId,
                answer: $answerText,
                toolName: $toolCall->getName(),
                approvalContext: $details,
                runId: $context->runId,
                toolCallId: $toolCall->getId(),
            ));
        }

        // Let the extension resolve the answer into a tool-execution decision.
        // The extension owns the complete answer vocabulary and outcome mapping
        // (Allow → handler runs, Block → denied reason, ReplaceResult → supplied).
        $this->applyApprovalOutcome($event, $toolCall, $hook, $answerText, $questionId, $details, $context->runId, $toolCall->getId());
    }

    /**
     * Process an already-resolved ToolQuestion (from crash recovery / redelivery).
     * Calls onApprovalAnswered + resolveApprovalAnswer to determine the outcome
     * without blocking.
     *
     * @param array<string, mixed> $details
     */
    private function processResolvedAnswer(
        ToolCallRequested $event,
        ToolCall $toolCall,
        \Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface $hook,
        ToolQuestion $question,
        array $details,
    ): void {
        $answerText = $question->answerText;
        $questionId = (string) ($details['question_id'] ?? '');

        if (null === $answerText || '' === $answerText) {
            // Question was cancelled or has no text answer — treat as denied.
            $event->setResult(new ToolResult($toolCall, [
                'denied' => true,
                'reason' => 'approval_cancelled',
                'message' => \sprintf('Tool "%s" was denied: the pending approval was cancelled.', $toolCall->getName()),
            ]));

            return;
        }

        // Route to onApprovalAnswered for side-effects, then resolveApprovalAnswer for outcome.
        $runId = '' !== $question->runId ? $question->runId : null;
        $toolCallId = '' !== $question->toolCallId ? $question->toolCallId : null;

        if ($hook instanceof ApprovalAnswerHookInterface && '' !== $questionId) {
            $hook->onApprovalAnswered(new ApprovalAnswerContextDTO(
                questionId: $questionId,
                answer: $answerText,
                toolName: $toolCall->getName(),
                approvalContext: $details,
                runId: $runId,
                toolCallId: $toolCallId,
            ));
        }

        $this->applyApprovalOutcome($event, $toolCall, $hook, $answerText, $questionId, $details, $runId, $toolCallId);
    }

    /**
     * Call the hook's resolveApprovalAnswer and apply the returned decision
     * generically to the tool call.
     *
     * @param array<string, mixed> $details
     */
    private function applyApprovalOutcome(
        ToolCallRequested $event,
        ToolCall $toolCall,
        \Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface $hook,
        string $answerText,
        string $questionId,
        array $details,
        ?string $runId = null,
        ?string $toolCallId = null,
    ): void {
        if (!$hook instanceof ApprovalAnswerHookInterface) {
            // No ApprovalAnswerHookInterface — no outcome mapping exists.
            // Default: fall through so the real tool handler can run.
            return;
        }

        $context = new ApprovalAnswerContextDTO(
            questionId: $questionId,
            answer: $answerText,
            toolName: $toolCall->getName(),
            approvalContext: $details,
            runId: $runId,
            toolCallId: $toolCallId,
        );

        $outcome = $hook->resolveApprovalAnswer($context);

        match ($outcome->kind) {
            ToolCallDecisionKindEnum::Allow => null, // Fall through → handler runs
            ToolCallDecisionKindEnum::Block => $event->setResult(new ToolResult($toolCall, [
                'denied' => true,
                'reason' => $outcome->reason ?? 'denied',
                'message' => $outcome->details['message'] ?? \sprintf('Tool "%s" was denied.', $toolCall->getName()),
            ])),
            ToolCallDecisionKindEnum::ReplaceResult => $event->setResult(new ToolResult($toolCall, $outcome->result)),
            ToolCallDecisionKindEnum::RequireApproval => null, // Silently ignore — already answered.
        };
    }

    /**
     * @param array<string, mixed> $details
     */
    private function runResultHooks(ToolCall $toolCall, bool $isError, mixed $rawResult, array $details): void
    {
        $content = $this->contentBlocks($rawResult);
        $currentIsError = $isError;
        $currentDetails = $details;

        foreach ($this->hookRegistry->toolResultHooks() as $hook) {
            try {
                $decision = $hook->onToolResult($this->toolResultContext(
                    toolCall: $toolCall,
                    isError: $currentIsError,
                    content: $content,
                    details: $currentDetails,
                ));
            } catch (\Throwable) {
                continue;
            }

            if (ToolResultDecisionKindEnum::Keep === $decision->kind) {
                continue;
            }

            if (ToolResultDecisionKindEnum::Replace !== $decision->kind) {
                continue;
            }

            $currentIsError = $decision->isError ?? $currentIsError;
            $content = $decision->content ?? $content;
            $currentDetails = $decision->details ?? $currentDetails;
        }
    }

    private function toolCallContext(ToolCall $toolCall): ToolCallContextDTO
    {
        $current = $this->contextAccessor?->current();

        return new ToolCallContextDTO(
            toolCallId: $toolCall->getId(),
            toolName: $toolCall->getName(),
            arguments: $toolCall->getArguments(),
            orderIndex: $current?->orderIndex() ?? 0,
            runId: $current?->runId(),
            turnNo: $current?->turnNo(),
            cwd: $this->cwd,
            metadata: [
                'signature' => $toolCall->getSignature(),
                'timeout_seconds' => $current?->timeoutSeconds(),
                'noninteractive_child_run' => null !== $this->noninteractiveChildProbe
                    && $this->noninteractiveChildProbe->isNoninteractiveChildRun($current?->runId()),
            ],
        );
    }

    /**
     * @param array<int, array<string, mixed>> $content
     * @param array<string, mixed>             $details
     */
    private function toolResultContext(ToolCall $toolCall, bool $isError, array $content, array $details): ToolResultContextDTO
    {
        $current = $this->contextAccessor?->current();

        return new ToolResultContextDTO(
            toolCallId: '' !== $toolCall->getId() ? $toolCall->getId() : ($current?->toolCallId() ?? ''),
            toolName: $toolCall->getName(),
            arguments: $toolCall->getArguments(),
            isError: $isError,
            content: $content,
            details: $details,
            runId: $current?->runId(),
            turnNo: $current?->turnNo(),
            cwd: $this->cwd,
            metadata: [
                'signature' => $toolCall->getSignature(),
                'timeout_seconds' => $current?->timeoutSeconds(),
                'result_replacement_mutable' => false,
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contentBlocks(mixed $result): array
    {
        return [[
            'type' => 'text',
            'text' => $this->normalizeResultText($result),
        ]];
    }

    private function normalizeResultText(mixed $result): string
    {
        if (null === $result) {
            return '';
        }

        if (\is_string($result)) {
            return $result;
        }

        if (\is_scalar($result)) {
            return (string) $result;
        }

        if ($result instanceof \Stringable) {
            return (string) $result;
        }

        $encoded = json_encode($result, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return false === $encoded ? '{}' : $encoded;
    }
}
