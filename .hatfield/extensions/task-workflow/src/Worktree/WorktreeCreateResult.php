<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Worktree;

final readonly class WorktreeCreateResult
{
    public function __construct(
        public string $branch,
        public string $worktree,
        public string $output,
        public bool $veraCopied,
        public bool $vendorCopied,
        public bool $extensionsVendorInstalled,
        public bool $ideaExclusionsUpdated,
        public ?string $ideaNote = null,
    ) {
    }
}
