<?php

declare(strict_types=1);

use HatfieldProject\TaskHooks\WorktreeIdeaExclusions;

$root = getenv('HATFIELD_PROJECT_ROOT');
$worktree = getenv('HATFIELD_TASK_WORKTREE');
if (false === $root || false === $worktree || '' === $worktree) {
    throw new RuntimeException('Missing project hook context.');
}
require $root.'/vendor/autoload.php';
require __DIR__.'/WorktreeIdeaExclusions.php';

fwrite(\STDERR, (new WorktreeIdeaExclusions())->update($worktree)."\n");
