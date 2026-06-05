<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\Hatfield\ExtensionApi\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\ToolResultHookInterface;

/**
 * Internal registry for tool call/result hooks registered by extensions.
 *
 * Hooks are stored in registration order. This registry is used by:
 * - ExtensionToolRegistryBridge to receive hooks from the public ExtensionApi
 * - Future ToolHookDispatcher (EXT-HOOK-04) to iterate and dispatch hooks
 *   during tool execution.
 *
 * Also tracks pending approvals: when a tool call hook returns RequireApproval,
 * the question_id is mapped back to the originating hook and its context,
 * so the answer can be routed back to onApprovalAnswered().
 *
 * @internal this is app-internal wiring, not part of the public ExtensionApi
 */
final class ExtensionHookRegistry
{
    /**
     * Registered tool call hooks, in registration order.
     *
     * @var list<ToolCallHookInterface>
     */
    private array $toolCallHooks = [];

    /**
     * Registered tool result hooks, in registration order.
     *
     * @var list<ToolResultHookInterface>
     */
    private array $toolResultHooks = [];

    /**
     * Pending approvals: question_id => ApprovalPendingEntry.
     *
     * Populated when ExtensionToolHookEventSubscriber processes a
     * RequireApproval decision. Consumed when the human answer is
     * routed back to the originating hook.
     *
     * @var array<string, ApprovalPendingEntry>
     */
    private array $pendingApprovals = [];

    public function addToolCallHook(ToolCallHookInterface $hook): void
    {
        $this->toolCallHooks[] = $hook;
    }

    /**
     * @return list<ToolCallHookInterface>
     */
    public function toolCallHooks(): array
    {
        return $this->toolCallHooks;
    }

    public function addToolResultHook(ToolResultHookInterface $hook): void
    {
        $this->toolResultHooks[] = $hook;
    }

    /**
     * @return list<ToolResultHookInterface>
     */
    public function toolResultHooks(): array
    {
        return $this->toolResultHooks;
    }

    /**
     * Register a pending approval for answer routing.
     *
     * Called by ExtensionToolHookEventSubscriber when a tool call hook
     * returns a RequireApproval decision. Stores the question_id → hook
     * mapping so the answer can be routed back to the originating hook
     * via ApprovalAnswerHookInterface::onApprovalAnswered().
     *
     * @param array<string, mixed> $details the approval context from the RequireApproval decision
     */
    public function registerPendingApproval(
        string $questionId,
        ToolCallHookInterface $hook,
        array $details,
    ): void {
        $this->pendingApprovals[$questionId] = new ApprovalPendingEntry(
            hook: $hook,
            details: $details,
        );
    }

    /**
     * Resolve a pending approval and remove it from the registry.
     *
     * Returns null if no pending approval is found for the given question_id
     * (e.g., already resolved, expired, or never registered).
     */
    public function resolveApproval(string $questionId): ?ApprovalPendingEntry
    {
        $entry = $this->pendingApprovals[$questionId] ?? null;

        if (null !== $entry) {
            unset($this->pendingApprovals[$questionId]);
        }

        return $entry;
    }
}

/**
 * Internal value object for pending approval routing.
 *
 * Pairs the originating hook with the approval context details so
 * the answer can be delivered to the correct hook with full context.
 *
 * @internal
 */
final readonly class ApprovalPendingEntry
{
    /**
     * @param array<string, mixed> $details the approval context from the RequireApproval decision
     */
    public function __construct(
        public ToolCallHookInterface $hook,
        public array $details,
    ) {
    }
}
