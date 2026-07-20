<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionHumanInputSuspension;
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
 * For RequireApproval decisions this subscriber returns a typed
 * {@see ToolExecutionHumanInputSuspension} (non-terminal). The tool worker exits;
 * AgentCore admits WaitingHuman + human_input.requested. When the human answers via
 * answer_human, ApplyCommandHandler requeues the exact ExecuteToolCall with a typed
 * internal answer. On resume, this subscriber validates correlation, invokes
 * ApprovalAnswerHookInterface for ONLY the originating hook, then continues remaining
 * hooks. Allow means the real handler runs for the original call — no extra LLM turn.
 *
 * This subscriber contains ZERO SafeGuard-specific knowledge. Any extension implementing
 * ApprovalAnswerHookInterface can drive its own vocabulary/outcome mapping.
 *
 * Tool-result hooks are currently observational because Symfony AI's
 * ToolCallSucceeded/ToolCallFailed events expose readonly result/exception data.
 */
final readonly class ExtensionToolHookEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ExtensionHookRegistry $hookRegistry,
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
        $humanInputAnswer = $this->contextAccessor?->current()?->humanInputAnswer();

        foreach ($this->hookRegistry->toolCallHooks() as $hook) {
            // Resumed exact-call path: consume the typed answer for the originating hook only.
            if ($humanInputAnswer instanceof ToolCallHumanInputAnswerDTO
                && $this->isAnswerForHook($humanInputAnswer, $hook::class, $context)
            ) {
                try {
                    $this->applyResumedAnswer($event, $toolCall, $hook, $humanInputAnswer, $context);
                } catch (\Throwable $exception) {
                    $event->setResult(new ToolResult($toolCall, [
                        'denied' => true,
                        'reason' => 'approval_resume_failed',
                        'message' => \sprintf(
                            'Tool "%s" was denied: approval resume failed.',
                            $toolCall->getName(),
                        ),
                        'error_type' => $exception::class,
                    ]));

                    $this->logger?->error('tool.approval_resume_failed', [
                        'component' => 'extension.tool_hook_subscriber',
                        'event_type' => 'tool.approval_resume_failed',
                        'tool_name' => $toolCall->getName(),
                        'tool_call_id' => $toolCall->getId(),
                        'run_id' => $context->runId ?? '',
                        'error_type' => $exception::class,
                    ]);
                }

                // Allow falls through so remaining hooks (and the real handler) run.
                // Block/ReplaceResult already set a result — stop.
                if (null !== $event->getResult()) {
                    return;
                }

                // Consumed answer applies to only this originating hook.
                $humanInputAnswer = null;

                continue;
            }

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
                    $event->setResult(new ToolResult($toolCall, [
                        'denied' => true,
                        'reason' => 'approval_handler_failed',
                        'message' => \sprintf(
                            'Tool "%s" was denied: the approval handler failed.',
                            $toolCall->getName(),
                        ),
                        'error_type' => $exception::class,
                    ]));

                    $this->logger?->error('tool.approval_handler_failed', [
                        'component' => 'extension.tool_hook_subscriber',
                        'event_type' => 'tool.approval_handler_failed',
                        'tool_name' => $toolCall->getName(),
                        'tool_call_id' => $toolCall->getId(),
                        'run_id' => $context->runId ?? '',
                        'error_type' => $exception::class,
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
     * Emit a typed non-terminal human-input suspension. No ToolQuestion row, no poll.
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
        if (!\is_array($schema)) {
            $schema = ['type' => 'string'];
        }

        if ('' === $runId || '' === $toolCallId || '' === $questionId) {
            throw new \LogicException('RequireApproval suspension requires runId, toolCallId, and question_id.');
        }

        $hookId = $this->hookRegistryId($hook::class);
        $turnNo = $context->turnNo ?? 0;
        $stepId = $this->ambientStepId();
        if (null === $stepId || '' === $stepId) {
            // step_id is required for batch resume; ExecuteToolCallWorker always sets it.
            throw new \LogicException('RequireApproval suspension requires step_id in tool execution context.');
        }

        $payload = array_replace($details, [
            'kind' => 'interrupt',
            'question_id' => $questionId,
            'prompt' => $prompt,
            'schema' => $schema,
            'tool_call_id' => $toolCallId,
            'tool_name' => $toolCall->getName(),
            'ui_kind' => 'choice',
            'hook_id' => $hookId,
            'hook_class' => $hook::class,
            'approval_context' => $details,
        ]);

        $continuationRef = [
            'run_id' => $runId,
            'turn_no' => $turnNo,
            'step_id' => $stepId,
            'tool_call_id' => $toolCallId,
        ];

        $request = PendingHumanInputRequestDTO::toolCallFromPayload($payload, $continuationRef);

        $this->logger?->info('tool.approval_suspension_created', [
            'run_id' => $runId,
            'component' => 'extension.tool_hook_subscriber',
            'event_type' => 'tool.approval_suspension_created',
            'question_id' => $questionId,
            'tool_call_id' => $toolCallId,
            'hook_class' => $hook::class,
        ]);

        $event->setResult(new ToolResult($toolCall, new ToolExecutionHumanInputSuspension($request)));
    }

    /**
     * @param \Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface $hook
     */
    private function applyResumedAnswer(
        ToolCallRequested $event,
        ToolCall $toolCall,
        object $hook,
        ToolCallHumanInputAnswerDTO $answer,
        ToolCallContextDTO $context,
    ): void {
        if (!$hook instanceof ApprovalAnswerHookInterface) {
            throw new \LogicException(\sprintf('Resumed approval answer targets hook "%s" which does not implement ApprovalAnswerHookInterface.', $hook::class));
        }

        $answerText = \is_string($answer->answer) ? $answer->answer : (string) json_encode($answer->answer);
        $approvalContext = $answer->requestPayload['approval_context'] ?? $answer->requestPayload;
        if (!\is_array($approvalContext)) {
            $approvalContext = [];
        }

        $approvalAnswerContext = new ApprovalAnswerContextDTO(
            questionId: $answer->questionId,
            answer: $answerText,
            toolName: $toolCall->getName(),
            approvalContext: $approvalContext,
            runId: $context->runId,
            toolCallId: $toolCall->getId(),
        );

        $hook->onApprovalAnswered($approvalAnswerContext);
        $outcome = $hook->resolveApprovalAnswer($approvalAnswerContext);

        match ($outcome->kind) {
            ToolCallDecisionKindEnum::Allow => null,
            ToolCallDecisionKindEnum::Block => $event->setResult(new ToolResult($toolCall, [
                'denied' => true,
                'reason' => $outcome->reason ?? 'denied',
                'message' => $outcome->details['message'] ?? \sprintf('Tool "%s" was denied.', $toolCall->getName()),
            ])),
            ToolCallDecisionKindEnum::ReplaceResult => $event->setResult(new ToolResult($toolCall, $outcome->result)),
            ToolCallDecisionKindEnum::RequireApproval => throw new \LogicException('resolveApprovalAnswer must not return RequireApproval.'),
        };
    }

    private function isAnswerForHook(
        ToolCallHumanInputAnswerDTO $answer,
        string $hookClass,
        ToolCallContextDTO $context,
    ): bool {
        $payloadHookId = $answer->requestPayload['hook_id'] ?? null;
        $payloadHookClass = $answer->requestPayload['hook_class'] ?? null;
        $expectedHookId = $this->hookRegistryId($hookClass);

        // Both identities are required when the suspension payload includes them
        // (canonical Path A always embeds both). Fail closed if either is missing
        // or mismatched so a forged answer cannot target another hook.
        if (!\is_string($payloadHookClass) || '' === $payloadHookClass
            || !\is_string($payloadHookId) || '' === $payloadHookId
        ) {
            return false;
        }
        if ($payloadHookClass !== $hookClass || $payloadHookId !== $expectedHookId) {
            return false;
        }

        $refToolCallId = $answer->continuationRef['tool_call_id'] ?? null;
        if (!\is_string($refToolCallId) || $refToolCallId !== $context->toolCallId) {
            return false;
        }

        $refRunId = $answer->continuationRef['run_id'] ?? null;
        if (\is_string($refRunId) && '' !== $refRunId && null !== $context->runId && $refRunId !== $context->runId) {
            return false;
        }

        if ($answer->questionId !== ($answer->requestPayload['question_id'] ?? null)) {
            return false;
        }

        return true;
    }

    private function hookRegistryId(string $hookClass): string
    {
        // Stable registry identity (not process-local). crc32b matches prior
        // deterministic ToolQuestion requestId namespace style.
        return hash('crc32b', $hookClass);
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
                'step_id' => $this->ambientStepId(),
            ],
        );
    }

    private function ambientStepId(): ?string
    {
        $current = $this->contextAccessor?->current();
        if (null === $current) {
            return null;
        }

        $stepId = $current->stepId();
        if (null !== $stepId && '' !== $stepId) {
            return $stepId;
        }

        $answer = $current->humanInputAnswer();
        if ($answer instanceof ToolCallHumanInputAnswerDTO) {
            $fromAnswer = $answer->continuationRef['step_id'] ?? null;
            if (\is_string($fromAnswer) && '' !== $fromAnswer) {
                return $fromAnswer;
            }
        }

        return null;
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
