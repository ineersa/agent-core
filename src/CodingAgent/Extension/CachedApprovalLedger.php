<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Shared, cross-process approval ledger backed by the cache.approvals
 * Doctrine DBAL cache pool.
 *
 * Bridges the in-process ExtensionHookRegistry/ApprovalSessionTracker
 * across separate messenger consumer processes via the shared .hatfield
 * SQLite database. "Allow once" approvals are one-time (deleted on consume);
 * "Always allow" is persisted independently via SafeGuardPolicyWriter
 * (settings.yaml) and does not use this ledger.
 *
 * All keys are prefixed and namespaced by run_id to prevent cross-run
 * interference. Keys self-expire via the pool's default_lifetime (86400s =
 * 1 day), so stale pending entries are automatically cleaned up.
 *
 * Key structure:
 *   sg.pending.<run_id>.<question_id>  → {hookId, operationKey, details}
 *   sg.approved.<run_id>.<operation_key>  → true
 *
 * @internal SafeGuard internal, not part of the public ExtensionApi
 */
final readonly class CachedApprovalLedger
{
    private const string KEY_PREFIX = 'sg';

    public function __construct(
        private CacheItemPoolInterface $cache,
    ) {
    }

    // ── Pending approval (TOOL consumer writes, RUN_CONTROL consumer reads) ──

    /**
     * Register a pending approval for cross-process resolution.
     *
     * Called by ExtensionHookRegistry in the TOOL consumer when a
     * RequireApproval decision is returned. The entry includes the
     * hook ID (class name) so the resolving process can look up the
     * local hook instance.
     *
     * @param string               $runId        current run ID
     * @param string               $questionId   unique question identifier
     * @param string               $hookId       hook class name (e.g. SafeGuardToolCallHook::class)
     * @param string               $operationKey tracker key for the operation
     * @param array<string, mixed> $details      approval context (includes operation_key, run_id, etc.)
     */
    public function registerPending(string $runId, string $questionId, string $hookId, string $operationKey, array $details): void
    {
        $this->save(self::key('pending', $runId, $questionId), [
            'hookId' => $hookId,
            'operationKey' => $operationKey,
            'details' => $details,
        ]);
    }

    /**
     * Resolve a pending approval from the RUN_CONTROL consumer.
     *
     * Returns the stored data or null if not found/already resolved.
     *
     * @return array{hookId: string, operationKey: string, details: array<string, mixed>}|null
     */
    public function resolvePending(string $runId, string $questionId): ?array
    {
        $key = self::key('pending', $runId, $questionId);
        $item = $this->cache->getItem($key);

        if (!$item->isHit()) {
            return null;
        }

        $data = $item->get();
        if (!\is_array($data)) {
            return null;
        }

        // One-time read — delete after resolve so the same question_id
        // cannot be approved twice.
        $this->cache->deleteItem($key);

        return $data;
    }

    /**
     * Remove a pending entry without approving it (e.g. on Deny).
     */
    public function removePending(string $runId, string $questionId): void
    {
        $this->cache->deleteItem(self::key('pending', $runId, $questionId));
    }

    // ── Approved decision (RUN_CONTROL consumer writes, TOOL consumer reads) ──

    /**
     * Mark an operation as approved in the shared cache.
     *
     * Called from onApprovalAnswered when the user answers "Allow once"
     * or "Always allow". The retry in the TOOL consumer reads this via
     * consumeApproval().
     */
    public function markApproved(string $runId, string $operationKey): void
    {
        $this->save(self::key('approved', $runId, $operationKey), true);
    }

    /**
     * Consume (read-and-delete) an approved operation.
     *
     * Called from ExtensionToolHookEventSubscriber::onToolCallRequested()
     * via ExtensionHookRegistry::consumeApproval() — the cache pre-check
     * that runs when a tool-call hook returns RequireApproval. The TOOL
     * consumer subscriber reads the shared cache for an approved decision
     * (previously written by SafeGuardApprovalCommitSubscriber in the
     * RUN_CONTROL consumer) and, on hit, skips the RequireApproval so
     * the tool executes.
     *
     * Returns true if the operation was approved and now consumed
     * (one-time Allow once semantics).
     *
     * "Always allow" decisions are persisted in settings.yaml by
     * SafeGuardPolicyWriter and checked by SafeGuardClassifier;
     * they do NOT use this cache path after initial approval.
     */
    public function consumeApproval(string $runId, string $operationKey): bool
    {
        $key = self::key('approved', $runId, $operationKey);
        $item = $this->cache->getItem($key);

        if (!$item->isHit()) {
            return false;
        }

        $this->cache->deleteItem($key);

        return true === $item->get();
    }

    /**
     * Remove an approved entry without consuming it (e.g. on cleanup).
     */
    public function removeApproved(string $runId, string $operationKey): void
    {
        $this->cache->deleteItem(self::key('approved', $runId, $operationKey));
    }

    // ── Helpers ──

    /**
     * Build a namespaced cache key: sg.{type}.{runId}.{unique}.
     */
    private static function key(string $type, string $runId, string $unique): string
    {
        return \sprintf('%s.%s.%s.%s', self::KEY_PREFIX, $type, $runId, $unique);
    }

    /**
     * Write a value to the cache pool.
     */
    private function save(string $key, mixed $value): void
    {
        $item = $this->cache->getItem($key);
        $item->set($value);
        $this->cache->save($item);
    }
}
