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
     * Answer a pending question. Returns true if the question was found
     * and updated, false if not found or already resolved.
     */
    public function answer(string $requestId, bool $answer): bool;

    /**
     * Poll for an answer with a fresh DB read.
     *
     * @return ?bool true/false if answered/cancelled, null if still pending
     */
    public function pollAnswer(string $requestId): ?bool;

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
