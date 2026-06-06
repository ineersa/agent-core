<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\ToolQuestion;

use Doctrine\ORM\QueryBuilder;
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

    /** @return list<ToolQuestion> */
    public function findUnemittedPending(string $runId): array;

    public function createQueryBuilder(): QueryBuilder;

    public function markEmitted(string $requestId): void;

    public function answer(string $requestId, bool $answer): bool;

    public function pollAnswer(string $requestId): ?bool;

    public function cancel(string $requestId): void;
}
