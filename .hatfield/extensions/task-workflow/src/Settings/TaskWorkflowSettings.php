<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Settings;

final readonly class TaskWorkflowSettings
{
    /** @param array<string, array{script: string, timeout_seconds: int}> $hooks */
    public function __construct(
        public string $repository = '',
        public string $remote = 'origin',
        public string $baseBranch = 'main',
        public string $worktreeRoot = '../worktrees',
        public string $workflowField = 'Workflow',
        public string $mergeMethod = 'merge',
        public array $hooks = [],
    ) {
    }

    /** @param array<string, mixed> $settings */
    public static function fromArray(array $settings): self
    {
        return new self(
            repository: $settings['repository'] ?? '',
            remote: $settings['remote'] ?? 'origin',
            baseBranch: $settings['base_branch'] ?? 'main',
            worktreeRoot: $settings['worktree_root'] ?? '../worktrees',
            workflowField: $settings['workflow_field'] ?? 'Workflow',
            mergeMethod: $settings['merge_method'] ?? 'merge',
            hooks: $settings['hooks'] ?? [],
        );
    }
}
