# Make Hatfield config defaults and themes PHAR-safe

## Goal
Separate bundled application resources from the active project cwd. AppConfigResolver should load config/hatfield.defaults.yaml and built-in themes from the app/PHAR resource root, while project settings continue to come from the user's cwd. Avoid using %kernel.project_dir% as both app install root and target project root.

## Acceptance criteria
- AppConfigResolver no longer constructs defaults path from a generic project cwd concept.
- Built-in defaults and theme directories are resolved through an explicit app resource locator/path abstraction that can support PHAR packaging.
- Project .hatfield/settings.yaml remains resolved from the active project cwd.
- Existing config/theme tests are updated or expanded for the separated path concepts.
- castor check passes.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/2026-05-16-make-hatfield-config-defaults-and-themes-phar-safe
Worktree: /home/ineersa/projects/agent-core-worktrees/2026-05-16-make-hatfield-config-defaults-and-themes-phar-safe
Fork run: 6olw83ugqqk8
Started: 2026-05-16T17:49:33.123Z
Completed:

## Work log
- Created: 2026-05-16T01:22:11.088Z

## Task workflow update - 2026-05-16T17:49:33.123Z
- Moved TODO → IN-PROGRESS.
- Created branch task/2026-05-16-make-hatfield-config-defaults-and-themes-phar-safe.
- Created worktree /home/ineersa/projects/agent-core-worktrees/2026-05-16-make-hatfield-config-defaults-and-themes-phar-safe.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/2026-05-16-make-hatfield-config-defaults-and-themes-phar-safe.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/2026-05-16-make-hatfield-config-defaults-and-themes-phar-safe.
- Summary: Claiming task for forked implementation.

## Task workflow update - 2026-05-16T17:50:39.771Z
- Recorded fork run: 6olw83ugqqk8
- Fork 6olw83ugqqk8 launched for implementation in `/home/ineersa/projects/agent-core-worktrees/2026-05-16-make-hatfield-config-defaults-and-themes-phar-safe`.

## Task workflow update - 2026-05-16T18:06:50.121Z
- Validation: `vendor/bin/phpunit tests/CodingAgent/Config/ --colors=always`: passed (33 tests, 55 assertions).; `vendor/bin/phpunit tests/CodingAgent/Session/ tests/Tui/Theme/ --colors=always`: passed (41 tests, 109 assertions).; `vendor/bin/phpunit --exclude-group tui-e2e --colors=always`: passed (228 tests, 7750 assertions) with 1 pre-existing PHPUnit notice/skipped test.; `castor deptrac`: passed (0 violations).; `vendor/bin/phpstan analyse -c phpstan.dist.neon --no-progress`: passed.; `vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff`: passed.; `castor check`: passed (`quality: ok`).
- Summary: Fork 6olw83ugqqk8 completed implementation. Commit `eea069fd257437a9250950a055c0ce1ae0cb8487` on task branch introduces `AppResourceLocator` to separate bundled app resources from active project cwd, updates AppConfigResolver/ThemeFactory/settings path wiring, updates tests and PHPStan baseline, and fixes pre-existing `.castor/tasks.php` PHPStan short-ternary issue that blocked full validation.
PR URL: https://github.com/ineersa/agent-core/pull/4
PR Status: open

## Task workflow update - 2026-05-16T18:17:00.412Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/2026-05-16-make-hatfield-config-defaults-and-themes-phar-safe to origin.
- branch 'task/2026-05-16-make-hatfield-config-defaults-and-themes-phar-safe' set up to track 'origin/task/2026-05-16-make-hatfield-config-defaults-and-themes-phar-safe'.
- Created PR: https://github.com/ineersa/agent-core/pull/4
- Validation: Changed files include new `src/CodingAgent/Config/AppResourceLocator.php`, config resolver/theme factory/service wiring updates, tests, PHPStan baseline, and `.castor/tasks.php` short-ternary fix.; Validation from fork: `vendor/bin/phpunit tests/CodingAgent/Config/` passed (33 tests, 55 assertions); `vendor/bin/phpunit tests/CodingAgent/Session/ tests/Tui/Theme/` passed (41 tests, 109 assertions); `vendor/bin/phpunit --exclude-group tui-e2e` passed (228 tests, 7750 assertions); `castor deptrac` passed; PHPStan passed; CS fixer passed; full `castor check` passed (`quality: ok`).
- Summary: Implementation complete in worktree commit `eea069fd257437a9250950a055c0ce1ae0cb8487`. Ready for code review.
