<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard;

/**
 * Two-tier approval tracker for SafeGuard's HITL approval flow.
 *
 * Tier 1: In-memory approved keys — operations that were already approved
 *         and can be consumed on the next tool call retry (one-time use).
 *
 * Tier 2: Pending operations — operations that are waiting for the human
 *         to answer. The answer is resolved from the shared events.jsonl
 *         file via SessionEventReader, avoiding cross-process callback
 *         routing that would fail in async (multi-process) mode.
 *
 * Lifecycle:
 *   1. markPending(key, questionId, sessionId) — called when RequireApproval is returned
 *   2. resolveAnswer(key) — reads events.jsonl for the pending answer
 *   3. approve(key) — called when the answer is "Allow once" or "Always allow"
 *   4. consumeApproval(key) — called on the next onToolCall, returns true and removes
 *   5. remove(key) — called when the answer is "Deny"
 *   6. forceAnswer(key, answer) — testing-only in-memory override
 *
 * This tracker is NOT persisted across runs. All tool execution within a
 * session runs in the same Messenger consumer process, so a singleton-scoped
 * service works correctly.
 *
 * @internal SafeGuard internal, not part of the public ExtensionApi
 */
final class ApprovalSessionTracker
{
    /**
     * Approved keys: key => true (one-time use, consumed on next tool call).
     *
     * @var array<string, bool>
     */
    private array $approved = [];

    /**
     * Pending operations: key => {questionId, sessionId}.
     *
     * @var array<string, array{questionId: string, sessionId: string}>
     */
    private array $pending = [];

    /**
     * Forced answers for testing: key => answer.
     *
     * @var array<string, string>
     */
    private array $forcedAnswers = [];

    public function __construct(
        private readonly ?SessionEventReader $eventReader = null,
    ) {
    }

    /**
     * Mark an operation as pending approval.
     *
     * Stores the key → (questionId, sessionId) mapping so that
     * resolveAnswer() can find the human's response in events.jsonl.
     */
    public function markPending(string $key, string $questionId, string $sessionId): void
    {
        $this->pending[$key] = ['questionId' => $questionId, 'sessionId' => $sessionId];
    }

    /**
     * Check whether an operation key has a pending approval.
     */
    public function hasPending(string $key): bool
    {
        return isset($this->pending[$key]);
    }

    /**
     * Resolve the human's answer for a pending operation.
     *
     * Checks forced answers first (testing), then queries the
     * SessionEventReader to find the answer in events.jsonl.
     *
     * After resolving, the pending entry is removed regardless of
     * whether an answer was found.
     *
     * @return string|null the answer text, or null if no answer found yet
     */
    public function resolveAnswer(string $key): ?string
    {
        // Check forced answers first (testing override)
        if (isset($this->forcedAnswers[$key])) {
            $answer = $this->forcedAnswers[$key];
            unset($this->forcedAnswers[$key]);
            unset($this->pending[$key]);

            return $answer;
        }

        $entry = $this->pending[$key] ?? null;

        if (null === $entry) {
            return null;
        }

        // Remove pending entry — answer resolution is one-shot
        unset($this->pending[$key]);

        if (null === $this->eventReader) {
            return null;
        }

        return $this->eventReader->findAnswer($entry['sessionId'], $entry['questionId']);
    }

    /**
     * Force an answer for a key (testing only).
     *
     * The forced answer takes precedence over events.jsonl and is
     * consumed on the next resolveAnswer() call.
     */
    public function forceAnswer(string $key, string $answer): void
    {
        $this->forcedAnswers[$key] = $answer;
    }

    /**
     * Approve an operation by its key.
     *
     * The operation will be allowed on the next onToolCall() invocation
     * with the same key (one-time use).
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
     * Remove an operation from both pending and approved state.
     *
     * Called when the human answers "Deny" or on cleanup.
     */
    public function remove(string $key): void
    {
        unset($this->approved[$key], $this->pending[$key], $this->forcedAnswers[$key]);
    }
}
