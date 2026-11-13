<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\DTO;

final readonly class PullRequestDTO
{
    /** @param list<int> $closingIssues */
    public function __construct(
        public int $number,
        public string $url,
        public string $state,
        public ?string $mergedAt,
        public string $headRepository,
        public string $headBranch,
        public string $baseBranch,
        public string $headSha,
        public string $reviewDecision,
        public array $closingIssues,
    ) {
    }
}
