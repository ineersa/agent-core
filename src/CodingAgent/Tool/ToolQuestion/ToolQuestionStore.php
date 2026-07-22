<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\ToolQuestion;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Entity\ToolQuestion;
use Ineersa\CodingAgent\Entity\ToolQuestionStatusEnum;
use Psr\Log\LoggerInterface;

/**
 * Runtime-safe store for tool-local questions.
 *
 * Provides create, poll, answer, and emit-tracking operations through
 * Doctrine DBAL/ORM. Each read operation fetches fresh from the database
 * to avoid stale identity-map state when another process writes the
 * answer (e.g. controller writes answer while tool worker polls).
 *
 * All mutations use explicit flush() to commit changes immediately,
 * since the caller may be in a different process.
 */
final class ToolQuestionStore implements ToolQuestionStoreInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Create a new pending tool question.
     *
     * Defensive idempotency: if a question with the same requestId already
     * exists, returns the existing entity without creating a duplicate.
     * A race fallback catches UniqueConstraintViolationException around the
     * flush and re-fetches the existing entity.
     *
     * @return ToolQuestion the persisted (or existing) entity
     */
    public function create(ToolQuestion $question): ToolQuestion
    {
        // Check-before-persist for the common case.
        $existing = $this->findByRequestId($question->requestId);
        if (null !== $existing) {
            $this->logger->info('tool_question.create_duplicate_reused', [
                'component' => 'tool_question.store',
                'event_type' => 'tool_question.create_duplicate_reused',
                'request_id' => $question->requestId,
                'run_id' => $question->runId,
                'tool_name' => $question->toolName,
                'pid' => $question->pid,
            ]);

            return $existing;
        }

        try {
            $this->entityManager->persist($question);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Race fallback: another process persisted the same requestId
            // between our check and flush.
            $this->entityManager->clear();

            $existing = $this->findByRequestId($question->requestId);
            if (null !== $existing) {
                $this->logger->info('tool_question.create_duplicate_race_reused', [
                    'component' => 'tool_question.store',
                    'event_type' => 'tool_question.create_duplicate_race_reused',
                    'request_id' => $question->requestId,
                    'run_id' => $question->runId,
                    'tool_name' => $question->toolName,
                    'pid' => $question->pid,
                ]);

                return $existing;
            }

            // Refetch failed — rethrow the original exception.
            throw $e;
        }

        $this->logger->info('tool_question.created', [
            'component' => 'tool_question.store',
            'event_type' => 'tool_question.created',
            'request_id' => $question->requestId,
            'run_id' => $question->runId,
            'tool_name' => $question->toolName,
            'pid' => $question->pid,
        ]);

        return $question;
    }

    /**
     * Find a question by request_id.
     *
     * Clears the EntityManager identity map before each read to guarantee
     * fresh data from the database. This is essential for cross-process
     * reads — the tool worker writes, the controller answers, and this
     * method must see the latest state regardless of which process
     * previously loaded entities.
     *
     * Note: callers must not rely on previously managed entities remaining
     * managed after this call, as clear() detaches all tracked objects.
     * Any subsequent mutations require re-fetching the entity or calling
     * merge().
     */
    public function findByRequestId(string $requestId): ?ToolQuestion
    {
        $this->entityManager->clear();

        /* @var ?ToolQuestion */
        return $this->entityManager->getRepository(ToolQuestion::class)->findOneBy(['requestId' => $requestId]);
    }

    /**
     * Find all un-emitted pending questions across all runs, ordered by
     * created_at ascending.
     *
     * @return list<ToolQuestion>
     */
    public function findUnemittedPendingQuestions(): array
    {
        $this->entityManager->clear();

        $qb = $this->entityManager->getRepository(ToolQuestion::class)->createQueryBuilder('tq');
        $qb->where('tq.status = :status')
            ->andWhere('tq.emittedAt IS NULL')
            ->setParameter('status', ToolQuestionStatusEnum::Pending->value)
            ->orderBy('tq.createdAt', 'ASC');

        /* @var list<ToolQuestion> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Mark a question as emitted (prevents duplicate runtime events).
     */
    public function markEmitted(string $requestId): void
    {
        $question = $this->findByRequestId($requestId);
        if (null === $question) {
            return;
        }

        $question->markEmitted();
        $this->entityManager->flush();

        $this->logger->info('tool_question.emitted', [
            'component' => 'tool_question.store',
            'event_type' => 'tool_question.emitted',
            'request_id' => $requestId,
        ]);
    }

    /**
     * Answer a pending question with a boolean answer (Confirm-kind).
     * Uses fresh DB read to ensure we have the latest state.
     *
     * Idempotent: if the question is already resolved (answered or cancelled),
     * returns false without overwriting state. This prevents a late answer from
     * racing with cancellation, or a duplicate answer from mutating a resolved
     * question.
     *
     * @return bool true if the question was found and answered
     */
    public function answer(string $requestId, bool $answer): bool
    {
        return $this->answerWithCallable($requestId, static function (ToolQuestion $q) use ($answer): void {
            $q->setAnswer($answer);
        });
    }

    /**
     * Poll for an answer with a fresh DB read. Returns the answer if
     * the question has been answered, null if still pending, or false
     * if cancelled/rejected.
     */
    public function pollAnswer(string $requestId): ?bool
    {
        $question = $this->findByRequestId($requestId);
        if (null === $question) {
            return null;
        }

        if (ToolQuestionStatusEnum::Answered === $question->status) {
            return $question->answer;
        }

        if (ToolQuestionStatusEnum::Cancelled === $question->status) {
            return false;
        }

        return null;
    }

    /**
     * Cancel a pending question (tool detected cancellation while waiting).
     *
     * Idempotent: if the question is already resolved, returns without
     * changing state. This prevents a cancel racing after an answer from
     * flipping status to Cancelled.
     */
    public function cancel(string $requestId): void
    {
        $question = $this->findByRequestId($requestId);
        if (null === $question) {
            return;
        }

        // Guard against overwriting already-resolved state (e.g. cancel
        // racing after answer_tool_question already resolved the question).
        if ($question->isResolved()) {
            $this->logger->info('tool_question.cancel_skipped_resolved', [
                'component' => 'tool_question.store',
                'event_type' => 'tool_question.cancel_skipped_resolved',
                'request_id' => $requestId,
                'current_status' => $question->status->value,
            ]);

            return;
        }

        $question->markCancelled();
        $this->entityManager->flush();

        $this->logger->info('tool_question.cancelled', [
            'component' => 'tool_question.store',
            'event_type' => 'tool_question.cancelled',
            'request_id' => $requestId,
        ]);
    }

    /**
     * Cancel all pending questions created before the given cutoff.
     *
     * Used on controller startup to clean up stale pending questions
     * from a previous controller crash/restart where no blocked tool
     * worker remains to receive a late answer. Questions are marked
     * Cancelled (not deleted) to preserve audit trail.
     *
     * Uses DQL UPDATE to avoid loading/resolving individual entities,
     * since this is a bulk cleanup operation. DQL UPDATE bypasses entity
     * lifecycle callbacks (PreUpdate etc.), so updatedAt is set explicitly
     * in the query to keep timestamps consistent.
     *
     * @return int number of questions cancelled
     */
    public function cancelPendingQuestionsCreatedBefore(\DateTimeImmutable $cutoff): int
    {
        $this->entityManager->clear();

        $now = new \DateTimeImmutable();

        $qb = $this->entityManager->getRepository(ToolQuestion::class)->createQueryBuilder('tq');
        $count = (int) $qb->update()
            ->set('tq.status', ':cancelled')
            ->set('tq.answeredAt', ':now')
            ->set('tq.updatedAt', ':now')
            ->where('tq.status = :pending')
            ->andWhere('tq.createdAt < :cutoff')
            ->setParameter('cancelled', ToolQuestionStatusEnum::Cancelled->value)
            ->setParameter('now', $now)
            ->setParameter('pending', ToolQuestionStatusEnum::Pending->value)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();

        $this->entityManager->clear();

        if ($count > 0) {
            $this->logger->info('tool_question.cleanup_stale', [
                'component' => 'tool_question.store',
                'event_type' => 'tool_question.cleanup_stale',
                'count' => $count,
                'cutoff' => $cutoff->format(\DateTimeInterface::ATOM),
            ]);
        }

        return $count;
    }

    /**
     * Shared logic for answer().
     *
     * @param callable(ToolQuestion): void $mutator
     *
     * @return bool true if the question was found and answered
     */
    private function answerWithCallable(string $requestId, callable $mutator): bool
    {
        $question = $this->findByRequestId($requestId);
        if (null === $question) {
            $this->logger->warning('tool_question.answer_not_found', [
                'component' => 'tool_question.store',
                'event_type' => 'tool_question.answer_not_found',
                'request_id' => $requestId,
            ]);

            return false;
        }

        // Guard against overwriting already-resolved state (e.g. late answer
        // racing with cancellation, or duplicate answer_tool_question command).
        if ($question->isResolved()) {
            $this->logger->info('tool_question.answer_skipped_resolved', [
                'component' => 'tool_question.store',
                'event_type' => 'tool_question.answer_skipped_resolved',
                'request_id' => $requestId,
                'current_status' => $question->status->value,
            ]);

            return false;
        }

        $mutator($question);
        $this->entityManager->flush();

        $this->logger->info('tool_question.answered', [
            'component' => 'tool_question.store',
            'event_type' => 'tool_question.answered',
            'request_id' => $requestId,
        ]);

        return true;
    }
}
