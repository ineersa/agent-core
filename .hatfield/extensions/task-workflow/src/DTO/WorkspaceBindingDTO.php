<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\DTO;

final readonly class WorkspaceBindingDTO
{
    public function __construct(public string $branch, public string $worktree)
    {
    }
}
