<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Ineersa\Hatfield\ExtensionApi\ApprovalAnswerHookInterface;
use Ineersa\Hatfield\ExtensionApi\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\ToolResultHookInterface;
use Psr\Log\LoggerInterface;

/**
 * Internal registry for tool call/result hooks registered by extensions.
 *
 * Hooks are stored in registration order + indexed by class name for
 * cross-process resolution via CachedApprovalLedger.
 *
 * Pending approvals and approved decisions are backed by the shared
 * cache.approvals pool (via CachedApprovalLedger) so they survive the
 * process boundary between the tool consumer (where SafeGuard intercepts
 * tool calls) and the run_control consumer (where approval answers are
 * committed). This is required for the default 'process' TUI transport.
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
     * Hooks indexed by their class name (hookId) for cross-process resolution.
     *
     * Populated automatically by addToolCallHook(). Allows resolveApproval
     * (running in run_control consumer) to look up the local hook instance
     * by the class name stored in the shared cache by the tool consumer.
     *
     * @var array<string, ToolCallHookInterface>
     */
    private array $hooksById = [];

    /**
     * Cross-process approval ledger backed by the cache.approvals pool.
     * Injected by DI; null in unit tests (in-memory fallback).
     */
    private ?CachedApprovalLedger $ledger = null;

    /**
     * In-memory pending approvals fallback (when no ledger is available).
     *
     * @var array<string, ApprovalPendingEntry>
     */
    private array $pendingApprovals = [];

    /**
     * Optional logger for diagnostic warnings (e.g., ledger misconfiguration).
     */
    private ?LoggerInterface $logger = null;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    public function addToolCallHook(ToolCallHookInterface $hook): void
    {
        $this->toolCallHooks[] = $hook;

        // Index by class name so the hook can be looked up by hookId
        // from another consumer process via the shared cache.
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

    // ── Cross-process approval ledger injection ──

    /**
     * Inject the cross-process approval ledger.
     *
     * Called by the DI container (services.yaml) after construction.
     */
    public function setLedger(?CachedApprovalLedger $ledger): void
    {
        $this->ledger = $ledger;
    }

    // ── Pending approval registration (cross-process cache) ──

    /**
     * Register a pending approval for cross-process answer routing.
     *
     * Called by ExtensionToolHookEventSubscriber when a tool call hook
     * returns a RequireApproval decision. Writes to the shared cache
     * (via CachedApprovalLedger) so the answer can be routed back to
     * the originating hook in a different consumer process.
     *
     * When runId is empty or no ledger is available (test/unit context),
     * falls back to in-memory registration for backward compat.
     *
     * When runId is set but no ledger is available (DI misconfiguration),
     * emits a structured warning log so the operator can detect the wiring
     * gap that would cause issue #130 to reappear.
     *
     * @param array<string, mixed> $details the approval context from the RequireApproval decision
     */
    public function registerPendingApproval(
        string $questionId,
        ToolCallHookInterface $hook,
        array $details,
        string $runId = '',
    ): void {
        // Attempt cross-process write via shared cache.
        if ('' !== $runId && null !== $this->ledger) {
            $operationKey = (string) ($details['operation_key'] ?? '');
            $this->ledger->registerPending(
                runId: $runId,
                questionId: $questionId,
                hookId: $hook::class,
                operationKey: $operationKey,
                details: $details,
            );

            return;
        }

        // Warn when runId is present but ledger is missing
        // (DI misconfiguration — approvals will be silently ignored).
        if ('' !== $runId) {
            $this->warnLedgerMisconfigured($runId, 'registerPendingApproval');
        }

        // Fallback to in-memory (no run context — e.g., unit tests).
        $this->pendingApprovals[$questionId] = new ApprovalPendingEntry(
            hook: $hook,
            details: $details,
        );
    }

    /**
     * Resolve a pending approval from the shared cache or in-memory store.
     *
     * In cross-process mode (runId provided and ledger available):
     * reads {hookId, operationKey, details} from the shared cache,
     * looks up the live hook instance by hookId from the local
     * hooksById map, and returns it wrapped in an ApprovalPendingEntry.
     *
     * In same-process mode: delegates to the in-memory pendingApprovals array.
     *
     * Returns null if no pending approval is found for the given
     * question_id (already resolved, expired, or never registered).
     */
    public function resolveApproval(string $questionId, string $runId = ''): ?ApprovalPendingEntry
    {
        // Cross-process resolve via shared cache.
        if ('' !== $runId && null !== $this->ledger) {
            $data = $this->ledger->resolvePending($runId, $questionId);

            if (null === $data) {
                return null;
            }

            $hookId = (string) ($data['hookId'] ?? '');
            $hook = $this->hooksById[$hookId] ?? null;

            if (null === $hook) {
                return null;
            }

            if (!$hook instanceof ApprovalAnswerHookInterface) {
                return null;
            }

            return new ApprovalPendingEntry(
                hook: $hook,
                details: $data['details'] ?? [],
            );
        }

        // Warn when runId is present but ledger is missing
        // (DI misconfiguration — cross-process resolve will fail).
        if ('' !== $runId) {
            $this->warnLedgerMisconfigured($runId, 'resolveApproval');
        }

        // Same-process fallback (in-memory).
        $entry = $this->pendingApprovals[$questionId] ?? null;

        if (null !== $entry) {
            unset($this->pendingApprovals[$questionId]);
        }

        return $entry;
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

    // ── Approved decision management (cross-process cache) ──

    /**
     * Write an approved decision to the shared cache.
     *
     * Called by SafeGuardApprovalCommitSubscriber after resolving the
     * pending entry and calling onApprovalAnswered(). The cached decision
     * is consumed by ExtensionToolHookEventSubscriber on the retry.
     */
    public function markApproved(string $runId, string $operationKey): void
    {
        if (null === $this->ledger) {
            return;
        }

        $this->ledger->markApproved($runId, $operationKey);
    }

    /**
     * Check and consume an approved decision from the shared cache.
     *
     * Called by ExtensionToolHookEventSubscriber when a tool call hook
     * returns RequireApproval. If the cache has an approved entry for
     * this operation key, the subscriber skips the RequireApproval and
     * allows the tool execution (the decision was made in a different
     * process via SafeGuardApprovalCommitSubscriber).
     *
     * Returns true if the decision was consumed (one-time Allow once).
     */
    public function consumeApproval(string $runId, string $operationKey): bool
    {
        if (null === $this->ledger) {
            return false;
        }

        return $this->ledger->consumeApproval($runId, $operationKey);
    }

    // ── Diagnostic logging ──

    /**
     * Log a structured warning when the cross-process approval ledger is
     * missing but a runId is provided (DI misconfiguration signal).
     *
     * This is exactly the failure mode of issue #130: approvals silently
     * fall back to in-memory storage and are IGNORED in the process transport.
     * The log provides an operator-visible signal that would otherwise be
     * invisible.
     *
     * Logs only when a logger is configured (it is in production via DI).
     * No-op in unit tests that construct ExtensionHookRegistry without one.
     */
    private function warnLedgerMisconfigured(string $runId, string $operation): void
    {
        if (null === $this->logger) {
            return;
        }

        $this->logger->warning(
            'Cross-process approval ledger (CachedApprovalLedger) is not configured. '
            .'Approvals will fall back to the in-memory store and will be IGNORED '
            .'in the multi-process transport — this is the exact failure mode of issue #130. '
            .'Ensure ExtensionHookRegistry.setLedger() is called with a CachedApprovalLedger service.',
            [
                'run_id' => $runId,
                'component' => 'extension.hook_registry',
                'event_type' => 'safeguard_approval_misconfig',
                'operation' => $operation,
            ],
        );
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
