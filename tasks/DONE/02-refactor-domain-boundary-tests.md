# 02-refactor-domain-boundary-tests: domain invariants and boundary coverage

## Goal
Plan: .pi/plans/architecture-refactor-plan.md
Reports: .pi/reports/tests-architecture.md, .pi/reports/agent-core-architecture.md

Add focused boundary tests for AgentCore domain value objects and contracts that are currently tested mostly through handlers. These tests should lock down invariants before pipeline refactors.

Scope:
- Add direct tests for RunState status/version/turn/step behavior, ToolCall/ToolResult invariants, model invocation DTO contracts, AgentMessage payload/content behavior, and lifecycle ordering edge cases.
- Use the builders from 01-refactor-test-foundations where available.
- Keep tests at domain boundaries; avoid duplicating handler tests or production implementation details.

## Acceptance criteria
- New tests cover core RunState, ToolCall/ToolResult, model invocation, command/message, and lifecycle contract invariants.
- Tests are pure unit tests unless they genuinely require the Symfony kernel; no standalone Doctrine DB setup is introduced.
- No production-only-for-test APIs or constructor bypass tricks are added.
- Run and report Castor validation: at minimum castor test --filter for new tests plus castor check, or exact environmental blockers.

## Workflow metadata
Status: DONE
Branch: task/02-refactor-domain-boundary-tests
Worktree: /home/ineersa/projects/agent-core-worktrees/02-refactor-domain-boundary-tests
Fork run: w899qe4fkt2h
PR URL: https://github.com/ineersa/agent-core/pull/85
PR Status: merged
Started: 2026-06-03T01:31:49.557Z
Completed: 2026-06-03T02:13:14.815Z

## Work log
- Created: 2026-06-03T00:31:45.476Z

## Task workflow update - 2026-06-03T01:31:49.557Z
- Moved TODO → IN-PROGRESS.
- Created branch task/02-refactor-domain-boundary-tests.
- Created worktree /home/ineersa/projects/agent-core-worktrees/02-refactor-domain-boundary-tests.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/02-refactor-domain-boundary-tests.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/02-refactor-domain-boundary-tests.
- Validation: Pre-claim integration checkout clean on main: `git status --short --branch` showed `## main...origin/main` with no changes before claim.; Read task file `tasks/TODO/02-refactor-domain-boundary-tests.md`.; Read referenced `.pi/plans/architecture-refactor-plan.md`, `.pi/reports/tests-architecture.md`, and `.pi/reports/agent-core-architecture.md`.
- Summary: Claiming task after inspecting the task file and referenced architecture/test reports. Task 01 is DONE, so the new builder/test isolation infrastructure is available on main. Scope is to add pure AgentCore domain boundary tests for RunState, ToolCall/ToolResult, model invocation DTOs, AgentMessage, command/message DTOs, and lifecycle edge cases without production-only test APIs or DB/kernel setup.

## Task workflow update - 2026-06-03T01:37:45.855Z
- Validation: Worktree created: /home/ineersa/projects/agent-core-worktrees/02-refactor-domain-boundary-tests on branch task/02-refactor-domain-boundary-tests.; Worktree inspected: `git status --short --branch` => `## task/02-refactor-domain-boundary-tests`; no changed files.; Scout artifact for detailed recon: /home/ineersa/.pi/agent/tmp/2026-06--2cb16335.txt.
- Summary: Preparation context gathered for implementation fork. Read the moved task file, Domain/Event/Message AGENTS notes, referenced architecture/test reports, relevant AgentCore domain classes, existing domain/lifecycle tests, and task-01 builders. Ran 3 scout subagents in the task worktree to map domain constructors/behaviors, lifecycle ordering gaps, and test-layout/helper patterns. Implementation should stay test-only and pure PHPUnit: add focused AgentCore domain boundary tests, use task-01 builders where helpful, extend existing AgentMessage/Lifecycle tests, and avoid invented invariants where production constructors intentionally do not validate.
- Preparation/scout phase complete. Key targets identified: RunState/EventFactory/RunEvent, ToolCall/ToolResult/policy enum contracts, ModelInvocation/ProviderRequest DTO contracts, CoreCommandKind/RoutedCommand/PendingCommand, AgentBusMessage accessors, AgentMessage toArray/custom roles, and lifecycle validateOrder negative edge cases.

## Task workflow update - 2026-06-03T01:38:52.003Z
- Recorded fork run: q97fkoum8ive
- Summary: Launched implementation fork q97fkoum8ive in background on worktree `/home/ineersa/projects/agent-core-worktrees/02-refactor-domain-boundary-tests`. Fork instructions are test-only: add focused pure PHPUnit AgentCore domain boundary tests for RunState/RunStatus, RunEvent/EventFactory, ToolCall/ToolResult/policy, model invocation DTOs, command DTOs, bus message contracts, AgentMessage toArray/custom role behavior, and lifecycle ordering edge cases. Fork must use Castor-only validation, run focused tests plus `castor test` and `LLM_MODE=true castor check` (or exact environmental blockers), commit changes, and return a dense handoff.
- Implementation fork q97fkoum8ive launched with exact file targets, boundaries, and validation commands. Parent will verify commit/diff and record validation when fork reports back.
Castor Check Status: passed
Castor Check Commit: 0382f2a2771cf2a8b4dc6705fce23999274df8a1
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 900s
Castor Check Completed: 2026-06-03T01:47:59.221Z
Castor Check Output SHA256: 80bf59c9aec8d8fe422a085d91a73f7debcdb0a93bc88551bbe3b673e20b4bcf

## Task workflow update - 2026-06-03T01:48:02.495Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (900s timeout). Commit: 0382f2a2771c.
- Pushed task/02-refactor-domain-boundary-tests to origin.
- branch 'task/02-refactor-domain-boundary-tests' set up to track 'origin/task/02-refactor-domain-boundary-tests'.
- Created PR: https://github.com/ineersa/agent-core/pull/85
- Validation: Fork validation reported: `castor test --filter=RunStateTest\|EventFactoryAndRunEventTest\|ToolBoundaryTest\|ModelInvocationContractTest\|CommandBoundaryTest\|AgentBusMessageContractTest\|AgentMessageTest\|LifecycleEventContractTest` passed (113 tests, 350 assertions).; Fork validation reported: `castor test` passed (1584 tests, 4723 assertions).; Fork validation reported: `castor deptrac` passed (0 violations, 0 errors).; Fork validation reported: `castor phpstan` passed (0 errors).; Fork validation reported: `castor cs-check` passed (0 files fixed).; Fork validation reported: `LLM_MODE=true castor check` passed all 7 stages (deptrac, phpunit, controller E2E, real LLM E2E, TUI E2E, phpstan, cs-check).; Parent verification: `git status --short --branch` in worktree showed `## task/02-refactor-domain-boundary-tests` with no changes; `git diff --name-only HEAD~1..HEAD` listed only the 8 test files.
- Summary: Implementation complete at commit 0382f2a2 (`test: add AgentCore domain boundary coverage`) on branch task/02-refactor-domain-boundary-tests. Added focused pure PHPUnit AgentCore domain boundary coverage for RunState/RunStatus, RunEvent/EventFactory, ToolCall/ToolResult/ToolExecutionMode/ToolExecutionPolicy, ProviderRequest/model invocation DTOs, CoreCommandKind/RoutedCommand/PendingCommand, bus message accessors across concrete message classes, AgentMessage toArray/custom-role behavior, and lifecycle ordering negative edge cases. Parent verification inspected the commit: 8 files changed, 1232 insertions, all under tests/, no production files changed, worktree clean.

## Task workflow update - 2026-06-03T01:56:38.629Z
- Recorded fork run: w899qe4fkt2h
- Validation: Reviewer verdict: REQUEST CHANGES due to stale task-file workflow regression in PR diff; test code itself had no correctness issues.; Parent pre-fork remediation: `git fetch origin && git rebase origin/main` in worktree succeeded; new local HEAD c7bb0490 rebased onto main 366af205.; Parent pre-fork verification: `git diff --name-status origin/main...HEAD` showed only the 8 test files and no `tasks/` paths.; Parent pre-fork validation: `LLM_MODE=true castor check` passed all stages after rebase (deptrac, phpunit 1584 tests/4723 assertions, controller E2E, real LLM E2E, TUI E2E, phpstan, cs-check).
- Summary: Reviewer subagent completed and found one blocker plus minor suggestions. Blocker: PR diff included stale task workflow changes because branch was based before the task metadata move; it would re-add `tasks/TODO/02-refactor-domain-boundary-tests.md` and delete the live CODE-REVIEW task file. Parent rebased the worktree branch onto `origin/main` and verified `git diff --name-status origin/main...HEAD` now lists only test files; `LLM_MODE=true castor check` passed after the rebase. Launched follow-up fork w899qe4fkt2h to address all reviewer findings, verify the task-file blocker stays gone, implement low-risk test cleanup suggestions, run Castor validation, commit/push the PR branch, and return a handoff.
- Follow-up fork w899qe4fkt2h launched to address reviewer findings and update the PR branch.

## Task workflow update - 2026-06-03T02:08:14.330Z
- Validation: Parent verification after fork: `git fetch origin && git status --short --branch` in worktree showed branch synced with origin/task/02-refactor-domain-boundary-tests at 73994f10.; Parent verification: `git diff --name-status origin/main...HEAD` lists only 9 test files under tests/AgentCore; no task files or production files.; Parent focused validation after latest commit: `castor test --filter='RunEventTest|EventFactoryTest|CommandBoundaryTest|ModelInvocationContractTest|AgentBusMessageContractTest|AgentMessageTest|LifecycleEventContractTest|RunStateTest|ToolBoundaryTest'` passed (113 tests, 350 assertions).; Parent full `LLM_MODE=true castor check` rerun after latest commit exited nonzero due unrelated E2E/runtime flakes; deptrac ok, full phpunit ok (1584 tests/4723 assertions), controller ok, phpstan ok, cs-check ok; failure details saved at /home/ineersa/.pi/agent/tmp/2026-06--7993d995.txt and included ViewImageToolE2eTest LLM output variance plus TuiAgentSmokeTest timeout/CWD confusion. Reviewer explicitly assessed these flakes as unrelated and non-blocking for this test-only PR.
- Summary: Re-reviewer approved updated PR #85 at HEAD 73994f10. Reviewer confirmed previous stale task-file blocker is resolved: PR diff against origin/main contains no `tasks/` paths and no production files; only tests/AgentCore changes remain. Reviewer confirmed all prior requested changes are addressed (event tests split, named cancellation token stub, CoreCommandKind constants in positive provider, AgentBusMessageContractTest clarification). Verdict: APPROVE, with one non-blocking trivial suggestion to remove an unused `StartRunMessageBuilder` import in `AgentBusMessageContractTest.php`. No status move performed; task remains CODE-REVIEW.
- Reviewer subagent re-ran review on updated branch and returned APPROVE.

## Task workflow update - 2026-06-03T02:13:14.815Z
- Moved CODE-REVIEW → DONE.
- Merged task/02-refactor-domain-boundary-tests into integration checkout.
- Already up to date.
- Removed worktree /home/ineersa/projects/agent-core-worktrees/02-refactor-domain-boundary-tests.
- Pulled integration checkout: Already up to date..
- Validation: Pre-completion GitHub check: `gh pr view 85 --json url,state,mergedAt,headRefName,baseRefName,headRefOid` reported state=MERGED, mergedAt=2026-06-03T02:12:15Z, headRefName=task/02-refactor-domain-boundary-tests, headRefOid=73994f106bd0962a16b972b23aa2efa98d1dfda2.; Pre-completion integration sync: `git pull --ff-only` updated main from 2233b73b to 7aaf6df6 and pulled the 9 test-file PR changes.; Reviewer approval recorded: re-reviewer verdict APPROVED; no tasks/ paths and no production files in PR diff; focused Castor tests passed (113 tests, 350 assertions).
- Summary: Completing reviewed task after PR #85 was merged on GitHub. Confirmed GitHub PR state MERGED (mergedAt 2026-06-03T02:12:15Z) for branch task/02-refactor-domain-boundary-tests, then fast-forward pulled integration checkout from 2233b73b to 7aaf6df6 to pick up the merged test-only changes. Reviewer subagent approval was recorded before completion. Implementation adds focused AgentCore domain boundary tests under tests/AgentCore only; no production files were changed.
