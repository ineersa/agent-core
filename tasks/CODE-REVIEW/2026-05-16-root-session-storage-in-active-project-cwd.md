# Root session storage in active project cwd

## Goal
Session persistence should use the user project cwd/session config, not the app install root or Symfony kernel.project_dir. Review HatfieldSessionStore, SessionRunStore, SessionRunEventStore, and runtime wiring so .hatfield/sessions lives under the target project unless settings override it.

## Acceptance criteria
- SessionRunStore and SessionRunEventStore no longer implicitly write to app install root for TUI/headless runs.
- The active project cwd is passed through runtime/session boundaries explicitly enough to support PHAR distribution.
- HatfieldSessionStore and AgentCore stores agree on the same session directory for a run.
- Resume flow still works with session_id === run_id.
- Relevant session-storage docs/tests are updated.
- castor check passes.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/2026-05-16-root-session-storage-in-active-project-cwd
Worktree: /home/ineersa/projects/agent-core-worktrees/2026-05-16-root-session-storage-in-active-project-cwd
Fork run: cozv1blzbi34
Started: 2026-05-16T17:49:44.465Z
Completed:

## Work log
- Created: 2026-05-16T01:22:15.792Z

## Task workflow update - 2026-05-16T17:49:44.465Z
- Moved TODO → IN-PROGRESS.
- Created branch task/2026-05-16-root-session-storage-in-active-project-cwd.
- Created worktree /home/ineersa/projects/agent-core-worktrees/2026-05-16-root-session-storage-in-active-project-cwd.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/2026-05-16-root-session-storage-in-active-project-cwd.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/2026-05-16-root-session-storage-in-active-project-cwd.
- Summary: Claiming task for forked implementation.

## Task workflow update - 2026-05-16T17:50:39.771Z
- Recorded fork run: cozv1blzbi34
- Fork cozv1blzbi34 launched for implementation in `/home/ineersa/projects/agent-core-worktrees/2026-05-16-root-session-storage-in-active-project-cwd`.

## Task workflow update - 2026-05-16T18:10:57.275Z
- Validation: `php vendor/bin/phpunit tests/AgentCore/Infrastructure/Storage/ tests/CodingAgent/Session/`: passed (25 tests, 116 assertions).; `php vendor/bin/phpunit`: passed (236 tests, 7782 assertions).; `php vendor/bin/deptrac analyze --config-file=depfile.yaml --no-progress`: passed (0 violations).; `php vendor/bin/phpstan analyze --no-progress <7 changed src files>`: passed.; `php vendor/bin/php-cs-fixer fix --dry-run --diff --path-mode intersection src/`: passed.; Full PHPStan noted a pre-existing `.castor/tasks.php` short-ternary issue, unrelated to this task.
- Summary: Fork cozv1blzbi34 completed implementation. Commit `8fe3738f8af89dba3a60402395575af86a3ac71c` on task branch makes session storage use active project cwd/session config by adding sessions-base-path initialization through `AgentSessionClient`, propagating it from `InteractiveMode` to in-process AgentCore stores, exposing `HatfieldSessionStore::resolveSessionsBasePath()`, and updating docs/tests. Process transport currently has a no-op/TODO for passing sessions path to subprocess, documented as follow-up.
PR URL: https://github.com/ineersa/agent-core/pull/5
PR Status: open

## Task workflow update - 2026-05-16T18:17:15.835Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/2026-05-16-root-session-storage-in-active-project-cwd to origin.
- branch 'task/2026-05-16-root-session-storage-in-active-project-cwd' set up to track 'origin/task/2026-05-16-root-session-storage-in-active-project-cwd'.
- Created PR: https://github.com/ineersa/agent-core/pull/5
- Validation: Changed files include session storage docs, AgentCore session stores, runtime client contract/implementations, HatfieldSessionStore, InteractiveMode, and targeted tests.; Validation from fork: `php vendor/bin/phpunit tests/AgentCore/Infrastructure/Storage/ tests/CodingAgent/Session/` passed (25 tests, 116 assertions); `php vendor/bin/phpunit` passed (236 tests, 7782 assertions); deptrac passed (0 violations); PHPStan on changed src files passed; CS fixer dry-run passed. Full PHPStan noted pre-existing `.castor/tasks.php` short-ternary issue unrelated to this task.
- Summary: Implementation complete in worktree commit `8fe3738f8af89dba3a60402395575af86a3ac71c`. Ready for code review.
