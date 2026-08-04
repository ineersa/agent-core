<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolResultProcessorInterface;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;

/**
 * Promotes extension-handler cancelled/timed_out control flags into ToolResult details.
 *
 * Extension tools return structured maps via raw_result. Generic ToolExecutor only
 * promotes kind=interrupt into details, so post-invoke cancel arbitration cannot see
 * handler-owned cancelled/timed_out outcomes and rewrites them as stale_due_to_cancel.
 *
 * This processor is extension-only (extensionOwnerClass non-null). It copies only the
 * control flags needed by that arbitration, leaves content/raw_result/isError intact,
 * and never maps outcomes to kind=interrupt (HITL/WaitingHuman).
 */
final readonly class ExtensionToolControlFlagToolResultProcessor implements ToolResultProcessorInterface
{
    public function __construct(
        private ToolRegistryInterface $toolRegistry,
    ) {
    }

    public function process(ToolResult $result, ToolCall $toolCall): ToolResult
    {
        $definition = $this->toolRegistry->toolDefinition($toolCall->toolName);
        if (null === $definition || null === $definition->extensionOwnerClass) {
            return $result;
        }

        $details = $result->details;
        if (!\is_array($details)) {
            return $result;
        }

        $rawResult = $details['raw_result'] ?? null;
        if (!\is_array($rawResult)) {
            return $result;
        }

        $cancelled = true === ($rawResult['cancelled'] ?? false);
        $timedOut = true === ($rawResult['timed_out'] ?? false);
        if (!$cancelled && !$timedOut) {
            return $result;
        }

        // When deadline and run-cancel race, also surface cancelled so pre-existing
        // ToolExecutor post-invoke arbitration preserves the handler-owned timed_out map.
        if ($timedOut && !$cancelled && $this->isCancellationRequested($toolCall)) {
            $cancelled = true;
        }

        $promoted = $details;
        if ($cancelled) {
            $promoted['cancelled'] = true;
        }
        if ($timedOut) {
            $promoted['timed_out'] = true;
        }

        // Do not promote timeout_seconds or message — raw_result already preserves
        // the documented handler outcome; ToolExecutor owns ambient timeout metadata.

        return new ToolResult(
            toolCallId: $result->toolCallId,
            toolName: $result->toolName,
            content: $result->content,
            details: $promoted,
            isError: $result->isError,
        );
    }

    private function isCancellationRequested(ToolCall $toolCall): bool
    {
        $token = $toolCall->context['cancel_token'] ?? null;

        return $token instanceof CancellationTokenInterface && $token->isCancellationRequested();
    }
}
