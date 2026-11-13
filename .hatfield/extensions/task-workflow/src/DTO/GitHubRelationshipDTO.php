<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\DTO;

final readonly class GitHubRelationshipDTO
{
    public function __construct(
        public string $type,
        public string $url,
        public string $repository,
        public string $branch,
        public ?int $number = null,
        public ?string $state = null,
    ) {
    }
}
