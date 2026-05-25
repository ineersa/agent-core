# EXT-01 Project extension loader and settings

## Goal
Plan: `.pi/plans/extension-api-phar-plan.md`

Depends on EXT-00.

Implement the minimal project-local extension loading path. Hatfield PHAR remains location-independent: extension state is resolved from project cwd, not PHAR location. Load enabled extension classes from `<cwd>/.hatfield/extensions/vendor/autoload.php` and call `HatfieldExtensionInterface::register()` with the app's extension API implementation.

EXT-01 owns settings, project autoload loading, extension class instantiation, and lifecycle errors. It can run in parallel with EXT-02 after EXT-00 lands.

## Acceptance criteria
- Settings support an explicit `extensions.enabled` list in project/user Hatfield settings according to existing settings precedence.
- At startup/runtime wiring, Hatfield attempts to require `<cwd>/.hatfield/extensions/vendor/autoload.php` when present and handles the file being absent as a no-extension case.
- Enabled extension classes are instantiated through a small loader/factory and must implement `Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface` unless a deliberate adapter is documented.
- Each enabled extension receives `ExtensionApiInterface` via `register($api)`.
- Errors for missing classes, invalid implementations, or registration failures are deterministic and user-visible/loggable without crashing obscurely.
- No project root `vendor/autoload.php` is loaded by default.
- Validation includes `castor deptrac` and targeted tests for settings/autoload/class loading behavior.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/ext-01-project-extension-loader-settings
Worktree: /home/ineersa/projects/agent-core-worktrees/ext-01-project-extension-loader-settings
Fork run: mpp1639c53wq
PR URL: https://github.com/ineersa/agent-core/pull/49
PR Status: open
Started: 2026-05-25T21:07:02.252Z
Completed:

## Work log
- Created: 2026-05-22T22:43:13.274Z

## Task workflow update - 2026-05-25T21:07:02.252Z
- Moved TODO → IN-PROGRESS.
- Created branch task/ext-01-project-extension-loader-settings.
- Created worktree /home/ineersa/projects/agent-core-worktrees/ext-01-project-extension-loader-settings.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/ext-01-project-extension-loader-settings.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/ext-01-project-extension-loader-settings.

## Task workflow update - 2026-05-25T21:07:30.467Z
- Recorded fork run: rjmg3otsslpq
- Launched implementation fork rjmg3otsslpq in /home/ineersa/projects/agent-core-worktrees/ext-01-project-extension-loader-settings.

## Task workflow update - 2026-05-25T21:15:47.194Z
- Recorded fork run: mpp1639c53wq
- Previous fork rjmg3otsslpq ended with partial uncommitted implementation while fixing tests (`array_merge_recursive` settings override issue). Launched continuation fork mpp1639c53wq to finish EXT-01, avoid `/tmp` logger paths, prefer var/tmp or ArrayLogger/test logger, validate, and commit.

## Task workflow update - 2026-05-25T21:20:09.448Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/ext-01-project-extension-loader-settings to origin.
- branch 'task/ext-01-project-extension-loader-settings' set up to track 'origin/task/ext-01-project-extension-loader-settings'.
- Created PR: https://github.com/ineersa/agent-core/pull/49
- Validation: vendor/bin/phpunit tests/CodingAgent/Extension/ExtensionManagerTest.php: OK (10 tests, 26 assertions); castor deptrac: OK (0 violations, 326 uncovered, 740 allowed); castor test: OK (826 tests, 0 failures); castor cs-fix on new/modified files: applied formatting; castor cs-check on new/modified files: OK
- Summary: Implemented EXT-01 in worktree /home/ineersa/projects/agent-core-worktrees/ext-01-project-extension-loader-settings and committed 2f437726 on task/ext-01-project-extension-loader-settings. Added ExtensionManager to load configured extension classes from .hatfield/extensions/vendor/autoload.php and call HatfieldExtensionInterface::register() with ExtensionApiInterface. Added ExtensionApiBridge collecting ToolRegistrationDTO registrations with getRegistrations()/drainRegistrations() for EXT-02. Wired AgentCommand to load extensions before interactive/controller mode selection. Added extensions.enabled defaults/docs, services wiring, AppExtension deptrac layer, tests. Fixed prior partial-work issues: array_merge_recursive test helper morphing overrides and /tmp logger paths; tests now use project-local var/tmp. Removed unused extension stubs. EXT-02 registry bridge, PHAR packaging, and ToolRegistry internals remain out of scope.
