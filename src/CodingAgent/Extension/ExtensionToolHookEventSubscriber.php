<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Entity\ToolQuestion;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface;
use Ineersa\Hatfield\ExtensionApi\ApprovalAnswerContextDTO;
use Ineersa\Hatfield\ExtensionApi\ApprovalAnswerHookInterface;
use Ineersa\Hatfield\ExtensionApi\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\ToolCallDecisionKindEnum;
use Ineersa\Hatfield\ExtensionApi\ToolResultContextDTO;
use Ineersa\Hatfield\ExtensionApi\ToolResultDecisionKindEnum;
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
 * ToolQuestionStoreInterface) until the human answers via the TUI. This
 * avoids committing an "interrupt" tool result that would require the
 * model to retry (the soft-interrupt approach). The blocking poll holds
 * the tool worker until the answer arrives, then either allows execution
 * (falls through to the real tool handler) or denies it (setResult denied).
 *
 * The ToolQuestion is created with a deterministic requestId (runId + toolCallId)
 * so that message redelivery or crash recovery re-attaches to the existing
 * pending question instead of creating a duplicate — ensuring idempotency.
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
                        'reason' => 'safeguard_approval_handler_failed',
                        'message' => \sprintf(
                            'Tool "%s" was denied by SafeGuard: the approval handler failed (%s).',
                            $toolCall->getName(),
                            $exception->getMessage(),
                        ),
                        'error_type' => $exception::class,
                    ]));

                    $this->logger?->error('safeguard.approval_handler_failed', [
                        'component' => 'extension.tool_hook_subscriber',
                        'event_type' => 'safeguard.approval_handler_failed',
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
                name: $event->getMetadata()->getName(),
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
     * Handle a RequireApproval decision by blocking-polling the ToolQuestion DB table
     * for a human answer. The tool-worker thread is suspended here until the answer
     * arrives via answer_tool_question from the controller/TUI.
     *
     * On approval (Allow once / Always allow): falls through to the real tool handler.
     * On Deny: sets a denied result.
     * On message redelivery/crash recovery: re-attaches to existing pending ToolQuestion.
     */
    private function handleRequireApproval(
        ToolCallRequested $event,
        ToolCall $toolCall,
        \Ineersa\Hatfield\ExtensionApi\ToolCallHookInterface $hook,
        \Ineersa\Hatfield\ExtensionApi\ToolCallDecisionDTO $decision,
        ToolCallContextDTO $context,
    ): void {
        $runId = $context->runId ?? '';
        $toolCallId = $toolCall->getId();
        $details = $decision->details;
        $questionId = (string) ($details['question_id'] ?? '');
        $prompt = (string) ($details['prompt'] ?? 'Approval required.');
        $schema = $details['schema'] ?? ['type' => 'string'];

        // Deterministic requestId for idempotent re-attach on crash recovery
        // or message redelivery. Same runId + toolCallId always produces the
        // same requestId, so a retry finds the existing pending question.
        $requestId = \sprintf('sg_%s_%s', $runId, $toolCallId);

        // Look for an existing pending ToolQuestion (crash recovery / redelivery).
        $question = $this->toolQuestionStore->findByRequestId($requestId);

        if (null === $question) {
            // Create a new pending tool question for this approval.
            $kind = 'safeguard_approval';

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

            $this->logger?->info('safeguard.approval_question_created', [
                'run_id' => $runId,
                'component' => 'extension.tool_hook_subscriber',
                'event_type' => 'safeguard.approval_question_created',
                'request_id' => $requestId,
                'tool_call_id' => $toolCallId,
            ]);
        } else {
            // Re-attach to existing pending question (crash recovery / redelivery).
            if ($question->isResolved()) {
                // The question was already answered in a previous delivery.
                // Process the existing answer directly.
                $this->processApprovalAnswer($event, $toolCall, $hook, $question, $details);

                return;
            }

            $this->logger?->info('safeguard.approval_question_reused', [
                'run_id' => $runId,
                'component' => 'extension.tool_hook_subscriber',
                'event_type' => 'safeguard.approval_question_reused',
                'request_id' => $requestId,
                'tool_call_id' => $toolCallId,
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
        $this->logger?->info('safeguard.approval_polling_start', [
            'run_id' => $runId,
            'component' => 'extension.tool_hook_subscriber',
            'event_type' => 'safeguard.approval_polling_start',
            'request_id' => $requestId,
            'tool_call_id' => $toolCallId,
        ]);

        do {
            usleep(self::POLL_INTERVAL_MICROS);
            $answerText = $this->toolQuestionStore->pollAnswerText($requestId);
        } while (null === $answerText);

        $this->logger?->info('safeguard.approval_polling_complete', [
            'run_id' => $runId,
            'component' => 'extension.tool_hook_subscriber',
            'event_type' => 'safeguard.approval_polling_complete',
            'request_id' => $requestId,
            'answer' => $answerText,
        ]);

        // ── ANSWER ROUTING ──

        // Route the answer to the hook's onApprovalAnswered for side-effects
        // (e.g., SafeGuardPolicyWriter always-allow persistence).
        if ($hook instanceof ApprovalAnswerHookInterface && '' !== $questionId) {
            $hook->onApprovalAnswered(new ApprovalAnswerContextDTO(
                questionId: $questionId,
                answer: $answerText,
                toolName: $toolCall->getName(),
                approvalContext: $details,
            ));
        }

        // Determine tool execution decision from the answer.
        match ($answerText) {
            'Allow once', 'Always allow' => null, // Fall through → tool handler runs
            'Deny' => $event->setResult(new ToolResult($toolCall, [
                'denied' => true,
                'reason' => 'safeguard_denied',
                'message' => \sprintf('Tool "%s" was denied by SafeGuard: the human denied the operation.', $toolCall->getName()),
            ])),
            default => $event->setResult(new ToolResult($toolCall, [
                'denied' => true,
                'reason' => 'safeguard_unknown_answer',
                'message' => \sprintf('Tool "%s" was denied by SafeGuard: unknown answer "%s".', $toolCall->getName(), $answerText),
            ])),
        };
    }

    /**
     * Process an already-resolved ToolQuestion (from crash recovery / redelivery).
     * Routes the existing answer without blocking.
     */
    /**
     * @param array<string, mixed> $details
     */
    private function processApprovalAnswer(
        ToolCallRequested $event,
        ToolCall $toolCall,
        \Ineersa\Hatfield\ExtensionApi\ToolCallHookInterface $hook,
        ToolQuestion $question,
        array $details,
    ): void {
        $answerText = $question->answerText;
        $questionId = (string) ($details['question_id'] ?? '');

        if (null === $answerText || '' === $answerText) {
            // Question was cancelled or has no text answer — treat as Deny.
            $event->setResult(new ToolResult($toolCall, [
                'denied' => true,
                'reason' => 'safeguard_cancelled',
                'message' => \sprintf('Tool "%s" was denied by SafeGuard: the pending approval was cancelled.', $toolCall->getName()),
            ]));

            return;
        }

        // Route to onApprovalAnswered for side-effects.
        if ($hook instanceof ApprovalAnswerHookInterface && '' !== $questionId) {
            $hook->onApprovalAnswered(new ApprovalAnswerContextDTO(
                questionId: $questionId,
                answer: $answerText,
                toolName: $toolCall->getName(),
                approvalContext: $details,
            ));
        }

        match ($answerText) {
            'Allow once', 'Always allow' => null, // Fall through → tool handler runs
            default => $event->setResult(new ToolResult($toolCall, [
                'denied' => true,
                'reason' => 'safeguard_denied',
                'message' => \sprintf('Tool "%s" was denied by SafeGuard.', $toolCall->getName()),
            ])),
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
