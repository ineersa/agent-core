<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\Hatfield\ExtensionApi\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\ToolCallDecisionKindEnum;
use Ineersa\Hatfield\ExtensionApi\ToolResultContextDTO;
use Ineersa\Hatfield\ExtensionApi\ToolResultDecisionKindEnum;
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
 * Tool-result hooks are currently observational because Symfony AI's
 * ToolCallSucceeded/ToolCallFailed events expose readonly result/exception data.
 * Replacement decisions are folded into the local context seen by later hooks,
 * but cannot mutate the already-created Symfony AI event result.
 */
final readonly class ExtensionToolHookEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ExtensionHookRegistry $hookRegistry,
        private string $cwd,
        private ?StackToolExecutionContextAccessor $contextAccessor = null,
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
                $questionId = $decision->details['question_id']
                    ?? hash('sha256', \sprintf('%s|%s|%s', $toolCall->getName(), $toolCall->getId(), (string) microtime(true)));
                $prompt = $decision->details['prompt'] ?? 'Approval required.';
                $schema = $decision->details['schema'] ?? ['type' => 'string'];

                // Register pending approval so the answer can be routed back
                // to the originating hook via ApprovalAnswerHookInterface.
                $this->hookRegistry->registerPendingApproval(
                    questionId: $questionId,
                    hook: $hook,
                    details: $decision->details,
                );

                $event->setResult(new ToolResult($toolCall, [
                    'kind' => 'interrupt',
                    'question_id' => $questionId,
                    'prompt' => $prompt,
                    'schema' => $schema,
                    'tool_name' => $toolCall->getName(),
                    'tool_call_id' => $toolCall->getId(),
                    'approval_context' => $decision->details,
                ]));

                return;
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
