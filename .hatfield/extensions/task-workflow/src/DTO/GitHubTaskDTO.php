<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\DTO;

use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;

final readonly class GitHubTaskDTO
{
    public function __construct(
        public int $number,
        public string $url,
        public string $title,
        public string $body,
        public string $state,
        public ?string $stateReason,
        public string $updatedAt,
        public string $repository,
        public ?TaskStatusEnum $status,
    ) {
    }
}
