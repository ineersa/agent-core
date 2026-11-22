<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Worktree;

enum HookReasonEnum: string
{
    case Start = 'start';
    case Review = 'review';
    case Done = 'done';
    case Cancel = 'cancel';

    public function requiresWorktree(): bool
    {
        return self::Start === $this || self::Review === $this;
    }
}
