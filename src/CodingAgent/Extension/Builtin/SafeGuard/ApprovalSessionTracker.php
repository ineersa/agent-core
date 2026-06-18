<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard;

/**
 * In-memory tracker for approved tool call operations within a session.
 *
 * Tracks operations that were approved via the HITL approval flow.
 * Keys are normalized operation identifiers (e.g., "destructive:rm -rf /tmp/build").
 *
 * Lifecycle:
 *   1. markPending(questionId, key) — called when RequireApproval is returned
 *   2. approve(key) — called when the human answers "Allow once" or "Always allow"
 *   3. consumeApproval(key) — called on the next onToolCall, returns true and removes
 *   4. remove(key) — called when the human answers "Deny" or on cleanup
 *
 * This tracker is NOT persisted across processes. Cross-process approval
 * state is handled by the blocking-poll mechanism in
 * ExtensionToolHookEventSubscriber (creates ToolQuestion, blocks, and
 * resumes the same tool worker on answer) — no cross-process coordination
 * needed because the answer is read from the shared DB in the same process.
 *
 * @internal SafeGuard internal, not part of the public ExtensionApi
 */
final class ApprovalSessionTracker
{
    /**
     * Approved keys: key => true.
     *
     * @var array<string, bool>
     */
    private array $approved = [];

    /**
     * Pending question_id => key mappings.
     *
     * @var array<string, string>
     */
    private array $pendingByQuestionId = [];

    /**
     * Mark an operation as pending approval.
     *
     * Stores the question_id → key mapping so that when the answer
     * arrives, the correct key can be approved.
     */
    public function markPending(string $questionId, string $key): void
    {
        $this->pendingByQuestionId[$questionId] = $key;
    }

    /**
     * Approve an operation by its question_id.
     *
     * Called by SafeGuardToolCallHook::onApprovalAnswered() when the
     * human answers "Allow once" or "Always allow".
     *
     * The operation is marked as approved and will be allowed on the
     * next onToolCall() invocation with the same key.
     */
    public function approveByQuestionId(string $questionId): void
    {
        $key = $this->pendingByQuestionId[$questionId] ?? null;

        if (null === $key) {
            return;
        }

        $this->approved[$key] = true;
        unset($this->pendingByQuestionId[$questionId]);
    }

    /**
     * Approve an operation by its key directly.
     */
    public function approve(string $key): void
    {
        $this->approved[$key] = true;
    }

    /**
     * Consume and remove an approved operation.
     *
     * Returns true if the key was approved and has been consumed (one-time use).
     * Returns false if the key is not approved.
     */
    public function consumeApproval(string $key): bool
    {
        if (!isset($this->approved[$key])) {
            return false;
        }

        unset($this->approved[$key]);

        return true;
    }

    /**
     * Check if a key is approved without consuming it.
     */
    public function isApproved(string $key): bool
    {
        return isset($this->approved[$key]);
    }

    /**
     * Remove an operation from pending and approved state.
     *
     * Called when the human answers "Deny" or on cleanup.
     */
    public function remove(string $key): void
    {
        unset($this->approved[$key]);

        // Also clean up any question_id referencing this key
        foreach ($this->pendingByQuestionId as $qId => $k) {
            if ($k === $key) {
                unset($this->pendingByQuestionId[$qId]);
            }
        }
    }

    /**
     * Remove a pending operation by its question_id.
     *
     * Called when the human answers "Deny".
     */
    public function removeByQuestionId(string $questionId): void
    {
        $key = $this->pendingByQuestionId[$questionId] ?? null;

        if (null !== $key) {
            $this->remove($key);
        }
    }
}
