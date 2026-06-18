<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\Hatfield\ExtensionApi\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\ToolResultHookInterface;

/**
 * Internal registry for tool call/result hooks registered by extensions.
 *
 * Hooks are stored in registration order and indexed by class name for
 * lookup by getHook().
 *
 * Pending/approved decision tracking is handled by the blocking-poll
 * mechanism in ExtensionToolHookEventSubscriber (for RequireApproval)
 * and by SafeGuard's in-memory ApprovalSessionTracker (for same-process
 * approve-once within a single tool worker invocation).
 *
 * Cross-process approval state is NOT needed here because the blocking
 * poll holds the tool-worker thread until the answer is written to the
 * shared ToolQuestion DB table by AnswerToolQuestionHandler in the
 * controller process — all in the same process, no cache ledger needed.
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
     * Hooks indexed by their class name (hookId) for lookup.
     *
     * @var array<string, ToolCallHookInterface>
     */
    private array $hooksById = [];

    public function addToolCallHook(ToolCallHookInterface $hook): void
    {
        $this->toolCallHooks[] = $hook;
        $this->hooksById[$hook::class] = $hook;
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
     * Look up a registered hook by its class name.
     *
     * @return ToolCallHookInterface|null null if no hook registered under this class name
     */
    public function getHook(string $hookId): ?ToolCallHookInterface
    {
        return $this->hooksById[$hookId] ?? null;
    }
}
