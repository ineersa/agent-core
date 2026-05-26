# TOOLS-01 Implement PathResolver helper for file tools

## Goal
Implement a small static path resolution helper for the toolbox rollout.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Scope:
- Create `src/CodingAgent/Tool/PathResolver.php`.
- Provide static helper(s) used by read/write/edit/view_image tools.
- Resolve absolute paths as-is after normalization.
- Resolve relative paths against the active project cwd / current working directory used by CodingAgent tools.
- Expand `~` to the user's home directory.
- Normalize `.` and `..` path segments without requiring the target path to already exist (write may create new files).
- Keep this as an application-layer utility in `CodingAgent`; do not add dependencies from `AgentCore` or `Tui`.
- Add focused PHPUnit tests under `tests/CodingAgent/Tool/`.

Out of scope:
- No sandbox/allowlist enforcement.
- No tool implementation in this task.
- Do not implement ToolRegistry.

## Acceptance criteria
- `PathResolver` exists under `src/CodingAgent/Tool/` and is usable by tool classes without service wiring.
- Tests cover absolute paths, cwd-relative paths, `~` expansion, and `.`/`..` normalization for non-existing paths.
- No `AgentCore` or `Tui` dependency is introduced from `CodingAgent/Tool`.
- Focused tests pass with Castor/PHPUnit.

## Workflow metadata
Status: DONE
Branch: task/tools-01-path-resolver
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-01-path-resolver
Fork run: 1xugt309ld1j
PR URL: https://github.com/ineersa/agent-core/pull/54
PR Status: merged
Started: 2026-05-26T16:08:47.690Z
Completed: 2026-05-26T19:19:55.302Z

## Work log
- Created: 2026-05-17T04:42:04.933Z

## Task workflow update - 2026-05-26T16:08:47.690Z
- Moved TODO → IN-PROGRESS.
- Created branch task/tools-01-path-resolver.
- Created worktree /home/ineersa/projects/agent-core-worktrees/tools-01-path-resolver.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/tools-01-path-resolver.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/tools-01-path-resolver.
- Summary: Started as part of wave 1 tools foundation per toolbox design plan.

## Task workflow update - 2026-05-26T16:15:38.523Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/tools-01-path-resolver to origin.
- branch 'task/tools-01-path-resolver' set up to track 'origin/task/tools-01-path-resolver'.
- Created PR: https://github.com/ineersa/agent-core/pull/54
- Validation: castor test --filter=PathResolver: pass (30 tests, 32 assertions); castor deptrac: pass (0 violations, 0 errors); castor cs-fix --path src/CodingAgent/Tool/PathResolver.php: pass; castor cs-check --path tests/CodingAgent/Tool/PathResolverTest.php: pass; castor check: pre-existing Extension* failures unrelated to PathResolver
- Summary: Implemented PathResolver static utility in src/CodingAgent/Tool/PathResolver.php with cwd-relative resolution, absolute normalization, bare ~/ expansion, dot/dot-dot normalization without requiring paths to exist, and focused PHPUnit coverage in tests/CodingAgent/Tool/PathResolverTest.php. Committed as dc9e3328 on task/tools-01-path-resolver.

## Task workflow update - 2026-05-26T16:42:37.913Z
- Summary: Reviewer subagent result: REQUEST CHANGES. Critical findings: PathResolver passes null bytes through; isAbsolute() treats any x: path as absolute on Unix, so relative paths like a:b are not resolved against cwd. Additional bugs: ~user syntax is mangled by substr($path, 2); bare ~ returns home without normalization; relative cwd can produce relative result despite absolute-return contract. Review artifact: /home/ineersa/.pi/agent/tmp/2026-05--ba6881a4.txt

## Task workflow update - 2026-05-26T16:47:46.302Z
- Recorded fork run: 1xugt309ld1j
- Summary: Launched follow-up fork to address PR #54 reviewer issues and inline PR comment requesting PathResolver be a reusable utility outside the Tool namespace. Fork will move/rename namespace as appropriate, search/DRY existing similar path logic, reject null bytes, fix colon absolute-path detection, reject unsupported ~user syntax, normalize bare ~, validate cwd absoluteness, update tests/imports, and run Castor validation.

## Task workflow update - 2026-05-26T19:00:52.982Z
- Recorded fork run: 1xugt309ld1j
- Validation: castor test --filter=PathResolver: pass (44 tests, 52 assertions; includes SettingsPathResolver filter matches); castor deptrac: pass (0 violations, 0 errors); castor cs-check --path src/CodingAgent/Path/PathResolver.php --path tests/CodingAgent/Path/PathResolverTest.php: pass; php -l src/CodingAgent/Path/PathResolver.php and tests/CodingAgent/Path/PathResolverTest.php: pass; castor test: reported pre-existing Extension test failures unrelated to TOOLS-01 per fork handoff
- Summary: Follow-up fork completed and pushed PR #54 branch update. Commit 8b9e08eb moves PathResolver from Ineersa\CodingAgent\Tool to Ineersa\CodingAgent\Path, deletes old Tool namespace file/test, adds 36 focused PathResolver tests, rejects null bytes, fixes colon absolute-path detection, rejects unsupported ~user syntax, normalizes bare ~, validates cwd absoluteness, and aligns home fallback with SettingsPathResolver via posix_getpwuid + /tmp fallback. PR comment addressed by moving utility out of Tool namespace. Fork noted full castor test has pre-existing Extension test failures unrelated to TOOLS-01.

## Task workflow update - 2026-05-26T19:04:10.266Z
- Validation: castor test --filter=PathResolver: pass (44 tests, 52 assertions); castor deptrac: pass (0 violations, 0 errors); castor cs-check --path src/CodingAgent/Path/PathResolver.php --path tests/CodingAgent/Path/PathResolverTest.php: pass
- Summary: Addressed new PR #54 inline comment on PathResolver home-directory fallback ('No fallback, error.'). Commit 8cc40887 removes /tmp fallback from PathResolver::getHomeDirectory(), documents fail-loud behavior, and throws RuntimeException when HOME/USERPROFILE/posix_getpwuid cannot resolve a home directory. Pushed branch task/tools-01-path-resolver to update PR #54.

## Task workflow update - 2026-05-26T19:19:55.302Z
- Moved CODE-REVIEW → DONE.
- Merged task/tools-01-path-resolver into integration checkout.
- Merge made by the 'ort' strategy.
 src/CodingAgent/Path/PathResolver.php       | 215 +++++++++++++++++++++
 tests/CodingAgent/Path/PathResolverTest.php | 278 ++++++++++++++++++++++++++++
 2 files changed, 493 insertions(+)
 create mode 100644 src/CodingAgent/Path/PathResolver.php
 create mode 100644 tests/CodingAgent/Path/PathResolverTest.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/tools-01-path-resolver.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Validation: PR #54 merged: https://github.com/ineersa/agent-core/pull/54
- Summary: PR #54 merged on GitHub. Marking TOOLS-01 PathResolver implementation done.
