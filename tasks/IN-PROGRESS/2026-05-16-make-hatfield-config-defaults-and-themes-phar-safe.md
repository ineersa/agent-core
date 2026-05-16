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
Status: IN-PROGRESS
Branch: task/2026-05-16-make-hatfield-config-defaults-and-themes-phar-safe
Worktree: /home/ineersa/projects/agent-core-worktrees/2026-05-16-make-hatfield-config-defaults-and-themes-phar-safe
Fork run:
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
