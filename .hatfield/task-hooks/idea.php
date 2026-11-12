<?php

declare(strict_types=1);

use HatfieldProject\TaskHooks\WorktreeIdeaExclusions;
use HatfieldProject\TaskHooks\WorktreeIdeaSetup;

require __DIR__.'/WorktreeIdeaSetup.php';
require __DIR__.'/WorktreeIdeaExclusions.php';

$root = getenv('HATFIELD_PROJECT_ROOT');
$worktree = getenv('HATFIELD_TASK_WORKTREE');
if (false === $root || false === $worktree || '' === $worktree) {
    throw new RuntimeException('Missing project hook context.');
}
$exclusions = new WorktreeIdeaExclusions();
// Exclusions are project resources, so cleanup now precedes Git removal. A
// failed removal retains recovery state and retries this idempotent operation;
// never recursively delete a dirty tree or remove Git metadata from this hook.
$results = 'setup' === ($argv[1] ?? '')
    ? [$exclusions->addWorktreeExclusions(basename($worktree), dirname($worktree)), WorktreeIdeaSetup::ensure($root, $worktree)]
    : [$exclusions->removeWorktreeExclusions(basename($worktree), dirname($worktree))];
foreach ($results as $result) {
    $note = $result['note'] ?? null;
    if (null !== $note) {
        fwrite(\STDERR, $note."\n");
        if (str_contains($note, 'Failed') || str_contains($note, 'mismatched') || str_contains($note, 'reversed') || str_contains($note, 'cannot insert')) {
            exit(1);
        }
    }
}
