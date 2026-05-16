 x# Clarify Hatfield settings merge semantics

## Goal
Keep the custom AppConfigLoader merge behavior, but make it obvious and well-tested. Rename/refactor/comment mergeSettings so future readers understand why array_merge_recursive() and plain array_replace_recursive() are not used for layered YAML settings.

## Acceptance criteria
- AppConfigLoader merge method name/comments explain semantics: associative arrays recursively overlay, lists replace entirely, scalars use higher-priority value.
- Code comments explicitly mention why array_merge_recursive() is unsuitable for scalar config overrides.
- Focused tests cover scalar override, nested associative merge, list replacement without append/index merge, and current null override behavior.
- Existing config resolution behavior remains unchanged.
- castor check passes.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/2026-05-16-clarify-hatfield-settings-merge-semantics
Worktree: /home/ineersa/projects/agent-core-worktrees/2026-05-16-clarify-hatfield-settings-merge-semantics
Fork run: zn7va4iiacgk
Started: 2026-05-16T01:49:59.209Z
Completed:

## Work log
- Created: 2026-05-16T01:34:07.980Z

## Task workflow update - 2026-05-16T01:49:59.209Z
- Moved TODO → IN-PROGRESS.
- Created branch task/2026-05-16-clarify-hatfield-settings-merge-semantics.
- Created worktree /home/ineersa/projects/agent-core-worktrees/2026-05-16-clarify-hatfield-settings-merge-semantics.
- Summary: Claiming for background fork implementation: clarify custom Hatfield settings merge semantics and tests.

## Task workflow update - 2026-05-16T01:50:00Z
- Fork run: zn7va4iiacgk
- Delegated implementation to background fork in worktree `/home/ineersa/projects/agent-core-worktrees/2026-05-16-clarify-hatfield-settings-merge-semantics`.

## Task workflow update - 2026-05-16T02:05:00Z
- Fork completed successfully.
- Commit: `8f1e71926efadc7012ebef05862c3c267ab9c974`.
- Changed `AppConfigLoader::mergeSettings()` to `overlayConfig()` with documented custom merge semantics.
- Added focused AppConfigLoader tests for scalar override, deep associative overlay, list replacement, null override, bool/int override, mixed-type override, and layered load behavior.
- Validation: `castor check` passed in worktree; fork reported PHPUnit 228 tests / 7750 assertions, deptrac 0 violations, PHPStan clean, CS Fixer clean.
PR URL: https://github.com/ineersa/agent-core/pull/1
PR Status: merged

## Task workflow update - 2026-05-16T02:20:00.065Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/2026-05-16-clarify-hatfield-settings-merge-semantics to origin.
- branch 'task/2026-05-16-clarify-hatfield-settings-merge-semantics' set up to track 'origin/task/2026-05-16-clarify-hatfield-settings-merge-semantics'.
- Created PR: https://github.com/ineersa/agent-core/pull/1
- Validation: Fork validation: 228 tests pass, 0 deptrac violations, PHPStan clean, CS Fixer clean
- Summary: Implementation complete and ready for review. Renamed AppConfigLoader merge helper to overlayConfig, documented config overlay semantics, and added focused tests preserving behavior.

## Task workflow update - 2026-05-16T02:21:35.410Z
- Updated PR Status: merged
- Summary: PR was merged externally; DONE transition is blocked until the integration checkout is clean enough for move_task to perform/record the merge.
