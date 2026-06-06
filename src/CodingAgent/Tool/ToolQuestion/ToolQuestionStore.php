<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\ToolQuestion;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
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
 * All methods use explicit flush() to commit changes immediately,
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
     * @return ToolQuestion the persisted entity
     */
    public function create(ToolQuestion $question): ToolQuestion
    {
        $this->entityManager->persist($question);
        $this->entityManager->flush();

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
     * Find a question by request_id. Uses fresh DB read.
     */
    public function findByRequestId(string $requestId): ?ToolQuestion
    {
        $this->entityManager->clear();

        /* @var ?ToolQuestion */
        return $this->entityManager->getRepository(ToolQuestion::class)->findOneBy(['requestId' => $requestId]);
    }

    /**
     * Create a QueryBuilder against the tool_question table.
     * Used by ToolQuestionPoller for broad queries across runs.
     */
    public function createQueryBuilder(): QueryBuilder
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(ToolQuestion::class)->createQueryBuilder('tq');
    }

    /**
     * Find pending questions for a run that have not yet been emitted.
     * Returns fresh DB results, ordered by created_at ascending.
     *
     * @return list<ToolQuestion>
     */
    public function findUnemittedPending(string $runId): array
    {
        $this->entityManager->clear();

        $qb = $this->entityManager->getRepository(ToolQuestion::class)->createQueryBuilder('tq');
        $qb->where('tq.runId = :runId')
            ->andWhere('tq.status = :status')
            ->andWhere('tq.emittedAt IS NULL')
            ->setParameter('runId', $runId)
            ->setParameter('status', ToolQuestionStatusEnum::Pending)
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
     * Answer a pending question. Sets the boolean answer and marks status Answered.
     * Uses fresh DB read to ensure we have the latest state.
     *
     * @return bool true if the question was found and answered
     */
    public function answer(string $requestId, bool $answer): bool
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

        $question->setAnswer($answer);
        $this->entityManager->flush();

        $this->logger->info('tool_question.answered', [
            'component' => 'tool_question.store',
            'event_type' => 'tool_question.answered',
            'request_id' => $requestId,
            'answer' => $answer ? 'yes' : 'no',
        ]);

        return true;
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
     */
    public function cancel(string $requestId): void
    {
        $question = $this->findByRequestId($requestId);
        if (null === $question) {
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
}
