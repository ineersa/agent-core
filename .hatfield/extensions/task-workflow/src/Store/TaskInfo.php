<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Store;

final readonly class TaskInfo
{
    public function __construct(
        public TaskStatusEnum $status,
        public string $file,
        public string $path,
        public string $title,
        public ?string $branch = null,
        public ?string $worktree = null,
        public ?string $prUrl = null,
    ) {
    }
}
