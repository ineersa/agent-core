<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\ToolQuestion;

use Ineersa\CodingAgent\Entity\ToolQuestion;

/**
 * Interface for the cross-process tool question store.
 *
 * Abstracts DB-backed persistence so the AnswerToolQuestionHandler
 * and RuntimeBashBackgroundPromptAdapter can be tested with fakes.
 */
interface ToolQuestionStoreInterface
{
    public function create(ToolQuestion $question): ToolQuestion;

    public function findByRequestId(string $requestId): ?ToolQuestion;

    /**
     * Find all un-emitted pending questions across all runs, ordered by
     * created_at ascending. Used by ToolQuestionPoller to emit runtime events.
     *
     * @return list<ToolQuestion>
     */
    public function findUnemittedPendingQuestions(): array;

    public function markEmitted(string $requestId): void;

    /**
     * Answer a pending question with a boolean answer (Confirm-kind).
     * Returns true if the question was found and updated, false if not found or already resolved.
     */
    public function answer(string $requestId, bool $answer): bool;

    /**
     * Answer a pending question with a string answer (Approval-kind).
     * Used by SafeGuard approvals — values: 'Allow once', 'Always allow', 'Deny'.
     * Returns true if the question was found and updated, false if not found or already resolved.
     */
    public function answerWithText(string $requestId, string $answer): bool;

    /**
     * Poll for an answer with a fresh DB read.
     *
     * Returns null if the question is still pending (no user decision yet).
     * Returns true if the user answered yes/accept.
     * Returns false if the user answered no, cancelled, or timed out — because
     * the bash prompt semantics treat both a explicit-no and cancellation/timeout
     * as a decline to move the command to the background.
     *
     * @return ?bool null if pending, true if accepted, false if declined/cancelled/timed-out
     */
    public function pollAnswer(string $requestId): ?bool;

    /**
     * Poll for a string answer (Approval-kind) with a fresh DB read.
     *
     * Returns null if the question is still pending (no user decision yet).
     * Returns the string answer (e.g. 'Allow once', 'Always allow', 'Deny') if answered.
     * Returns null if cancelled (null means unresolved for caller).
     *
     * @return string|null null if pending/cancelled, or the answer string
     */
    public function pollAnswerText(string $requestId): ?string;

    /**
     * Cancel a pending question. Safe no-op if already resolved.
     */
    public function cancel(string $requestId): void;

    /**
     * Cancel all pending questions created before the given cutoff.
     * Intended for startup cleanup after a controller crash/restart
     * where no blocked tool worker remains to receive late answers.
     *
     * @return int number of questions cancelled
     */
    public function cancelPendingQuestionsCreatedBefore(\DateTimeImmutable $cutoff): int;
}
