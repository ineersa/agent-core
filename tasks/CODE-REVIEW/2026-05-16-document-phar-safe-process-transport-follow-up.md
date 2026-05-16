# Document PHAR-safe process transport follow-up

## Goal
JsonlProcessAgentSessionClient currently assumes a source checkout and spawns bin/console from dirname(__DIR__, 4). For now, document this as a known limitation/TODO and outline the eventual self-executable/PHAR-safe process transport approach.

## Acceptance criteria
- JsonlProcessAgentSessionClient has a clear TODO/comment explaining why dirname(__DIR__, 4) and bin/console are source-checkout assumptions.
- A follow-up note exists in docs or task notes describing the future SelfExecutableLocator/process transport strategy for PHAR/binary distribution.
- No behavioral change required unless trivial and safe.
- castor check passes.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/2026-05-16-document-phar-safe-process-transport-follow-up
Worktree: /home/ineersa/projects/agent-core-worktrees/2026-05-16-document-phar-safe-process-transport-follow-up
Fork run: vrd2dxwnotxy
Started: 2026-05-16T17:49:06.561Z
Completed:

## Work log
- Created: 2026-05-16T01:22:20.671Z

## Task workflow update - 2026-05-16T17:49:06.561Z
- Moved TODO → IN-PROGRESS.
- Created branch task/2026-05-16-document-phar-safe-process-transport-follow-up.
- Created worktree /home/ineersa/projects/agent-core-worktrees/2026-05-16-document-phar-safe-process-transport-follow-up.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/2026-05-16-document-phar-safe-process-transport-follow-up.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/2026-05-16-document-phar-safe-process-transport-follow-up.
- Summary: Claiming task for forked implementation.

## Task workflow update - 2026-05-16T17:50:39.770Z
- Recorded fork run: vrd2dxwnotxy
- Fork vrd2dxwnotxy launched for implementation in `/home/ineersa/projects/agent-core-worktrees/2026-05-16-document-phar-safe-process-transport-follow-up`.

## Task workflow update - 2026-05-16T17:57:54.356Z
- Validation: `castor check` in worktree: deptrac passed, phpunit passed (228 tests, 7750 assertions), cs-fixer passed; full check blocked by pre-existing PHPStan error in `.castor/tasks.php:191` unrelated to this task.; `vendor/bin/phpstan analyse ... JsonlProcessAgentSessionClient.php`: passed.; `vendor/bin/php-cs-fixer fix --dry-run ... JsonlProcessAgentSessionClient.php`: passed.
- Summary: Fork vrd2dxwnotxy completed implementation. Commit `9a2d54c86ff9bf5f511c2982040ecad0730e00b1` on task branch documents source-checkout assumptions in `JsonlProcessAgentSessionClient` and adds `src/CodingAgent/Runtime/Process/AGENTS.md` with the future SelfExecutableLocator/BinaryLocator strategy. Behavior unchanged.
PR URL: https://github.com/ineersa/agent-core/pull/3
PR Status: open

## Task workflow update - 2026-05-16T18:16:44.008Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/2026-05-16-document-phar-safe-process-transport-follow-up to origin.
- branch 'task/2026-05-16-document-phar-safe-process-transport-follow-up' set up to track 'origin/task/2026-05-16-document-phar-safe-process-transport-follow-up'.
- Created PR: https://github.com/ineersa/agent-core/pull/3
- Validation: Changed files: `src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php`, `src/CodingAgent/Runtime/Process/AGENTS.md`.; Validation from fork: deptrac passed, phpunit passed (228 tests, 7750 assertions), changed-file PHPStan passed, changed-file CS fixer passed; full `castor check` blocked by pre-existing `.castor/tasks.php:191` PHPStan issue unrelated to the task.
- Summary: Implementation complete in worktree commit `9a2d54c86ff9bf5f511c2982040ecad0730e00b1`. Ready for code review.
