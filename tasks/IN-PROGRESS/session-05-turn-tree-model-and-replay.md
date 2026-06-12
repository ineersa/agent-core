# SESSION-05 Turn tree model and replay anchors

## Goal
Introduce the canonical data model needed for `/tree`: a branchable per-session turn tree with stable anchors that can be replayed into prompt/run state.

## Context
Pi's session model is append-only JSONL entries with `id`/`parentId`, a movable leaf pointer, and context rebuilt by walking from leaf to root. Agent-core currently has canonical `events.jsonl` and linear `RunState`; tree navigation needs explicit turn/branch/leaf semantics that remain compatible with `events.jsonl` being the source of truth.

This task should design and implement the data/replay foundation before UI.

## Dependencies
- RTVS-08B canonical events and RunState replay should exist or this task should extend that reducer cleanly.
- RTVS-08 final resume integration should not be broken by tree metadata.

## Out of scope
- Rendering the `/tree` picker UI.
- Executing rewind/branch from the TUI.
- LLM branch summarization/compaction; can be a later enhancement.

## Current code facts

### Where turn boundaries exist today
- `RunEventTypeEnum` (src/AgentCore/Domain/Run/RunEventTypeEnum.php) — event types for AgentCore state machine
- `turn_no` in `RunState` — monotonically incrementing turn counter, persisted to `state.json`
- `lastSeq` in `TuiSessionState` — cursor position into runtime event stream, derived from `RuntimeEventPoller`
- `RuntimeEventMapper` — maps AgentCore `RunEvent` → `RuntimeEvent`; this could be extended to emit turn-boundary events
- No current concept of "parent turn" or "branch" exists in either `events.jsonl` or `RunState`

### Pi's approach (for reference, not direct translation)
- Append-only JSONL with `SessionEntry { id, parentId, timestamp, type, ... }`
- `SessionManager::branch(id)` moves leaf to `id`, so next append creates a child of that entry
- `SessionManager::getTree()` walks all entries, builds nested `SessionTreeNode[]` based on parentId relationships
- `SessionManager::buildSessionContext()` walks from leaf to root, collecting messages for prompt context
- `resetLeaf()` sets leaf to null (empty conversation)
- `appendSessionInfo(name)` sets a display name on the session file

### Implications for agent-core
- `events.jsonl` must remain the canonical source of truth. Adding tree metadata must not require a separate tree file.
- Two viable approaches:
  1. **Inline tree events in `events.jsonl`**: Add new `RunEvent` types like `turn_branched`, `leaf_moved`. Extend `RuntimeEventMapper` to produce corresponding `RuntimeEvent` types. Replay service reads these to determine active branch.
  2. **Separate tree metadata store**: Use `hatfield_session` DB columns (parent_id, root_id already exist but refer to forked sessions, not turn-level branching) or a new tree-index file.
  - **Recommendation**: Approach 1 (inline) keeps canonical source unified. Pi's model proves this works.

## Suggested event types to add
```php
// In AgentCore event stream (events.jsonl)
'run.turn_branched' => [
    'runId' => string,
    'seq' => int,
    'turnNo' => int,
    'parentTurnNo' => int|null,   // null = root
    'reason' => 'rewind'|'continue'|'fork',
]

// Or simpler: add parent_turn_no to existing turn-boundary events
```

## Canonical leaf tracking options

A) **Last-turn pointer** — `state.json` stores current leaf turn number. On replay, replay only events belonging to the active branch path. Simple but requires `state.json` to be correct.

B) **Leaf event** — Append a `run.leaf_set` event to `events.jsonl` each time the user navigates. Replay walks events, tracks leaf changes, only includes events in the active ancestry. More robust (canonical). **Recommended.**

## Implementation seams

### New file or extended in `src/AgentCore/...`
- Turn tree read model service: `src/AgentCore/Domain/Run/TurnTreeView.php` or similar
- Method: `buildFromEvents(string $runId): TurnTree` — walks `events.jsonl`, builds parent-child tree
- Method: `getActiveBranchPath(TurnTree, int $leafTurnNo): TurnNo[]` — returns ordered list of turn numbers from root to leaf

### Extended in `src/Tui/Runtime/...`
- TUI-side service to query the tree read model and format for display

## Tests to create
- Build linear tree from events, assert tree structure and active path
- Branch from earlier turn, assert both branches present and active path switches
- Replay state up to a non-leaf turn, assert only ancestor events included
- Multiple sequential branches preserve history

## Known pitfalls
- `events.jsonl` currently has no concept of "tree branching" — every `RunEvent` with `seq` occupies a linear sequence. Branching means events from an abandoned path have higher seq than the branch point but are not ancestors of the new leaf.
- RunState replay from `events.jsonl` must explicitly filter to active branch events. If replay is naive-sequential, abandoned branch events will corrupt state.
- The existing `ReplayService` in `src/AgentCore/Application/ReplayService.php` rebuilds prompt state by replaying all events in seq order — this is incompatible with tree branching without modification.
- DB `parent_id` and `root_id` currently refer to session-forking, not within-session turn branching. Do not overload these columns; keep turn-level branching in events.jsonl.
- No backward-compatibility for old sessions without tree events: they should be treated as linear single-branch history.
- Runtime/TUI/Messenger changes require full `castor check` before CODE-REVIEW.
- TUI must talk to runtime via `AgentSessionClient`, not AgentCore internals per deptrac boundaries.

## Acceptance criteria
- Canonical events include enough structure to identify user-visible turns and their replay boundaries/anchors.
- A session has an explicit current leaf/head concept or equivalent canonical event representation so future turns can branch from an earlier turn without rewriting history.
- A read model can build a turn tree for a session from canonical events, including turn id/anchor, parent relationship, labels/title text or prompt preview, timestamps, and current leaf marker.
- RunState/prompt replay can rebuild state up to a selected turn/leaf without including abandoned sibling branch events.
- Tree metadata remains append-only/canonical; no destructive truncation of `events.jsonl` is used to go back in history.
- Tests cover building a linear tree, creating a branch from an earlier turn, marking current leaf, and replaying only the selected branch path.
- Docs describe turn tree semantics and how they relate to canonical `events.jsonl`, `state.json`, and future `/tree`.
- Validation uses Castor per project rules; runtime/Messenger changes require full `castor check` before CODE-REVIEW.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/session-05-turn-tree-model-and-replay
Worktree: /home/ineersa/projects/agent-core-worktrees/session-05-turn-tree-model-and-replay
Fork run: 5rowk3bcy2hb
PR URL: https://github.com/ineersa/agent-core/pull/128
PR Status: open
Started: 2026-06-12T01:41:43.505Z
Completed:

## Work log
- Created: 2026-06-07T20:46:01.617Z

## Task workflow update - 2026-06-12T01:41:43.505Z
- Moved TODO → IN-PROGRESS.
- Created branch task/session-05-turn-tree-model-and-replay.
- Created worktree /home/ineersa/projects/agent-core-worktrees/session-05-turn-tree-model-and-replay.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/session-05-turn-tree-model-and-replay.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/session-05-turn-tree-model-and-replay.

## Task workflow update - 2026-06-12T01:48:30.411Z
- Validation: Read-only setup/context gathering only; no code validation run in orchestrator.
- Summary: Claimed task and created worktree `/home/ineersa/projects/agent-core-worktrees/session-05-turn-tree-model-and-replay` on branch `task/session-05-turn-tree-model-and-replay`. Loaded task-workflow/testing/subagents skills. Read task context, docs/session-storage.md, src/AgentCore Domain/Application AGENTS docs, tests/AGENTS.md, and future SESSION-06/07/08 task context. Scout subagents mapped AgentCore run event/replay seams and runtime/TUI projection seams; SESSION-05 appears core/runtime data foundation, not a TUI feature, so no TmuxHarness E2E proof is required unless the implementation adds user-visible TUI behavior. Full runtime validation remains for task-to-pr; implementation fork should run focused Castor tests only.

## Task workflow update - 2026-06-12T01:50:05.727Z
- Recorded fork run: 9o47vlbpy830
- Summary: Launched implementation fork `9o47vlbpy830` in worktree `/home/ineersa/projects/agent-core-worktrees/session-05-turn-tree-model-and-replay`. Fork instructions require core/runtime turn tree metadata, branch-aware replay filtering, docs/tests, focused Castor validation, and a branch commit. Fork explicitly must not move task, push, open PR, or run `LLM_MODE=true castor check`; full gate is deferred to task-to-pr.

## Task workflow update - 2026-06-12T02:04:48.416Z
- Recorded fork run: 9o47vlbpy830
- Validation: Verified commit: `git -C /home/ineersa/projects/agent-core-worktrees/session-05-turn-tree-model-and-replay rev-parse --verify HEAD` → `e8cac9d37bff189765318be84be8f358614c1c80`.; Verified branch/status: `git -C /home/ineersa/projects/agent-core-worktrees/session-05-turn-tree-model-and-replay status --short` → clean; branch `task/session-05-turn-tree-model-and-replay`.; Verified changed files: `git diff --stat HEAD~1..HEAD` → 17 files, 1621 insertions, 6 deletions; expected AgentCore turn tree/replay/runtime/docs/tests files changed.; Fork validation reported: `castor phpstan` ok; `castor test --filter=TurnTreeProjectorTest` ok (10 tests, 56 assertions); focused replay/pipeline/runtime mapper tests ok (72 tests, 303 assertions); full `castor test` ok (2,347 tests, 0 failures); `castor cs-check` ok after `castor cs-fix`; `castor deptrac` ok (0 violations/errors).; Not run by design: `LLM_MODE=true castor check`; deferred to `task-to-pr`.
- Summary: Implementation fork completed successfully. Verified worktree `/home/ineersa/projects/agent-core-worktrees/session-05-turn-tree-model-and-replay` is clean on branch `task/session-05-turn-tree-model-and-replay` at commit `e8cac9d37bff189765318be84be8f358614c1c80` (`e8cac9d3 Implement turn tree replay anchors`). Diff stat shows 17 files changed: new TurnTree DTO/projector/filter/replay DTO, AgentCore event/replay/pipeline updates, runtime protocol drop handling, docs, and tests. SESSION-05 remains core/runtime data foundation only; no TUI feature or TmuxHarness E2E was required/added. Noted fork caveat: `TurnTreeReplayFilter` is constructor-injected as nullable and should be verified during review/container validation; services.yaml resource autowiring includes `src/AgentCore/**/*.php`, so the service should be available.

## Task workflow update - 2026-06-12T02:18:33.992Z
- Recorded fork run: zlk8lxlq5po8
- Summary: Reviewer subagent returned `APPROVE WITH SUGGESTIONS` for commit `e8cac9d3`. Actionable findings: stale `RunStateReplayService::replay()` contiguity docblock, document `TurnTreeNodeDTO::lastSeq` semantics for rewound/non-last leaves, remove dead `$turnIndicesByTurnNo` in `TurnTreeProjector`, and remove empty no-op test helper conditional. Launched review-fix fork `zlk8lxlq5po8` in the task worktree with focused Castor validation instructions. Skipped broader NTH refactors for now (projector extraction, replay message allowlist, double-sort optimization) because they are non-blocking and higher-risk/subjective for this PR-prep pass.

## Task workflow update - 2026-06-12T02:21:04.065Z
- Recorded fork run: zlk8lxlq5po8
- Validation: Fork validation reported: `castor test --filter=TurnTreeProjectorTest` ok (10 tests, 56 assertions).; Fork validation reported: `castor test --filter=RunStateReplayServiceTest` ok (25 tests, 107 assertions).; Fork validation reported: `castor phpstan` ok (errors=0, file_errors=0).; Fork validation reported: `castor cs-fix` ok (1 formatting-only file fixed), then `castor cs-check` ok (files_fixed=0).
- Summary: Review-fix fork completed. Applied reviewer suggestions in commit `182bf592 Address turn tree review feedback`: updated branch-filtered replay docblock, documented `TurnTreeNodeDTO::lastSeq` semantics, removed dead `$turnIndicesByTurnNo`, and removed a no-op test-helper conditional. Fork reports no behavioral changes and no broader refactors.

## Task workflow update - 2026-06-12T02:31:13.016Z
- Recorded fork run: ta7fy8pux9bu
- Summary: Second reviewer pass still returned `APPROVE WITH SUGGESTIONS`. Prior review fixes were confirmed. Remaining actionable edge-case: `TurnTreeProjector::computeLastSeqs()` has no direct coverage and may misattribute rewind-only `leaf_set` events to the abandoned last anchored turn. Launched fork `ta7fy8pux9bu` to make node `lastSeq` reflect max event seq scoped to each turn, update DTO docs, add linear/rewind/branch lastSeq tests, and run focused Castor validation. Non-blocking design/NTH items (projector location, direct filter tests, minor simplification) intentionally not included unless required for the edge-case fix.

## Task workflow update - 2026-06-12T02:35:51.945Z
- Recorded fork run: ta7fy8pux9bu
- Validation: Fork validation reported: `castor test --filter=TurnTreeProjectorTest` ok (13 tests, 68 assertions).; Fork validation reported: `castor test --filter=RunStateReplayServiceTest` ok (25 tests, 107 assertions).; Fork validation reported: focused combined tests (`RunStateReplayServiceTest|ReplayServiceTest|AdvanceRunHandlerTest|RuntimeEventMapperTest|TurnTreeProjectorTest`) ok (85 tests, 371 assertions).; Fork validation reported: `castor phpstan --path src/AgentCore` ok (errors=0, file_errors=0).; Fork validation reported: `castor cs-check` initially required formatting; `castor cs-fix` then `castor cs-check` ok (files_fixed=0).
- Summary: Last-seq edge-case fork completed. Commit `6be3c4cb Fix turn tree node last sequence projection` changes `TurnTreeProjector` to compute node `lastSeq` by max event sequence scoped to each `RunEvent::$turnNo`, updates `TurnTreeNodeDTO` docs, removes now-unused anchor-window/maxSeq code, and adds explicit linear/rewind/abandoned-branch `lastSeq` tests. Replay/filter behavior was intentionally unchanged.

## Task workflow update - 2026-06-12T02:47:03.937Z
- Recorded fork run: 7sd6ljvxc7ox
- Summary: Final reviewer pass returned `APPROVE WITH SUGGESTIONS` with no correctness issues. Remaining actionable convention suggestion: `TurnTreeProjectorTest` tests an Application/Handler class but lived under `tests/AgentCore/Domain/Run`. Launched fork `7sd6ljvxc7ox` to move the test to `tests/AgentCore/Application/Handler`, update namespace, run focused validation, and commit. Other NTH notes were explicitly non-actionable/no-action-needed.

## Task workflow update - 2026-06-12T02:48:27.965Z
- Recorded fork run: 7sd6ljvxc7ox
- Validation: Fork validation reported: `castor test --filter=TurnTreeProjectorTest` ok (13 tests, 68 assertions).; Fork validation reported: `castor phpstan --path tests/AgentCore/Application/Handler/TurnTreeProjectorTest.php` ok (errors=0, file_errors=0).; Fork validation reported: `castor cs-check` ok (files_fixed=0).
- Summary: Final convention-fix fork completed. Commit `9e779948 Move turn tree projector test to handler suite` moved `TurnTreeProjectorTest` from `tests/AgentCore/Domain/Run` to `tests/AgentCore/Application/Handler` and updated its namespace to mirror the production class location. No production code or test assertions changed.

## Task workflow update - 2026-06-12T02:58:34.824Z
- Recorded fork run: d5cn0j1flb83
- Summary: Reviewer after test move still returned `APPROVE WITH SUGGESTIONS`. No critical issues. Actionable final items: replace inconsistent `isset(parent_turn_no)` checks in `TurnTreeProjector` with `array_key_exists`, document that `turn_branched` is explicit new-style tree metadata for future emitters, and add a comment explaining the defensive metadata inclusion safety net in `TurnTreeReplayFilter`. Launched fork `d5cn0j1flb83` for minimal fixes plus focused Castor validation.

## Task workflow update - 2026-06-12T03:01:01.129Z
- Recorded fork run: d5cn0j1flb83
- Validation: Fork validation reported: `castor test --filter=TurnTreeProjectorTest` ok (13 tests, 69 assertions).; Fork validation reported: `castor test --filter=RunStateReplayServiceTest` ok (25 tests, 107 assertions).; Fork validation reported: `castor phpstan --path src/AgentCore` ok (errors=0, file_errors=0).; Fork validation reported: `castor cs-check` ok (files_fixed=0).
- Summary: Final metadata-handling fork completed. Commit `3e92baae Tighten turn tree metadata handling` replaces `isset(parent_turn_no)` checks with `array_key_exists` in `TurnTreeProjector`, documents `turn_branched` future-emitter requirements, documents the defensive tree-metadata inclusion safety net in `TurnTreeReplayFilter`, and adds an explicit `parent_turn_no => null` assertion. No broad refactors or behavioral replay changes.

## Task workflow update - 2026-06-12T03:09:44.201Z
- Recorded fork run: lo07sunzel4n
- Summary: Final-final reviewer returned `APPROVE WITH SUGGESTIONS` with no issues/critical findings. To satisfy actionable quality feedback, launched fork `lo07sunzel4n` for low-risk polish: remove unused `titleForTurn($anchorSeq)` parameter, add generic-title fallback comment, replace/simplify the single-use tree-metadata closure in `TurnTreeReplayFilter`, and comment why `leaf_set.previous_turn_no` and `parent_turn_no` intentionally coincide in the normal continue path. Broader suggestions such as `RunState::withLastSeq()` and DTO moves are intentionally skipped as non-blocking future cleanup.

## Task workflow update - 2026-06-12T03:11:10.000Z
- Recorded fork run: lo07sunzel4n
- Validation: Fork validation reported: `castor test --filter=TurnTreeProjectorTest` ok (13 tests, 68 assertions).; Fork validation reported: `castor test --filter=RunStateReplayServiceTest` ok (25 tests, 107 assertions).; Fork validation reported: `castor test --filter=AdvanceRunHandlerTest` ok.; Fork validation reported: `castor phpstan --path src/AgentCore` ok (errors=0, file_errors=0).; Fork validation reported: `castor cs-check` ok (files_fixed=0).
- Summary: Polish fork completed. Commit `4c7f5002 Polish turn tree review findings` removes the unused `titleForTurn($anchorSeq)` parameter, adds a generic title fallback comment, extracts `TurnTreeReplayFilter` tree-metadata check into a private method, and documents why `leaf_set.previous_turn_no` and `parent_turn_no` coincide in the normal continue path. No behavior changes.

## Task workflow update - 2026-06-12T03:21:14.037Z
- Validation: Local validation on worktree at HEAD `4c7f5002`: `castor test` ok across 7 suites (agent-core 289 tests/1251 assertions; coding-agent shards 374/1058, 373/965, 309/1038, 287/752; tui 664/1660; platform 54/221), total 56.7s.; Local validation: `castor deptrac` ok (violations=0, errors=0, uncovered=796, allowed=1096).; Local validation: `castor phpstan` ok (errors=0, file_errors=0).; Local validation: `castor cs-check` ok (files_fixed=0).
- Summary: PR-prep review loop completed. Latest reviewer pass found no critical/issues; only non-blocking design/NTH notes remained. All actionable findings from prior passes were addressed in commits `182bf592`, `6be3c4cb`, `9e779948`, `3e92baae`, and `4c7f5002`. User directed to stop further reviewer loops and move to CODE-REVIEW.
Castor Check Status: passed
Castor Check Commit: 4c7f500244f45b4ba759eda45c9c61ef87557045
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 240s
Castor Check Completed: 2026-06-12T03:22:35.931Z
Castor Check Output SHA256: 15f51d5cf4aba80de58ef1fa97d43f52f7a114c15d218958d9102e6aa33a9124

## Task workflow update - 2026-06-12T03:22:39.421Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (240s timeout). Commit: 4c7f500244f4.
- Pushed task/session-05-turn-tree-model-and-replay to origin.
- branch 'task/session-05-turn-tree-model-and-replay' set up to track 'origin/task/session-05-turn-tree-model-and-replay'.
- Created PR: https://github.com/ineersa/agent-core/pull/128
- Validation: `castor test` ok across 7 suites, total 56.7s (agent-core 289 tests/1251 assertions; coding-agent shards 374/1058, 373/965, 309/1038, 287/752; tui 664/1660; platform 54/221).; `castor deptrac` ok (violations=0, errors=0).; `castor phpstan` ok (errors=0, file_errors=0).; `castor cs-check` ok (files_fixed=0).; Focused fork validations also passed for TurnTreeProjectorTest, RunStateReplayServiceTest, ReplayServiceTest, AdvanceRunHandlerTest, RuntimeEventMapperTest, and scoped PHPStan/CS checks.; Full `LLM_MODE=true castor check` to be run by move_task CODE-REVIEW gate.
- Summary: Prepared for code review. Implementation stack through `4c7f5002` adds canonical turn tree metadata events, turn tree DTO/projector/replay filter, branch-aware RunState/hot-prompt replay, runtime protocol drops for internal metadata, docs, and tests. Review iterations addressed all actionable findings; remaining reviewer notes were non-blocking design/NTH items. Local Castor validation passed before PR handoff.

## Task workflow update - 2026-06-12T03:38:08.569Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: PR #128 review iteration started. Inline review comments read via `gh api repos/ineersa/agent-core/pulls/128/comments`: (3400478889) `TurnTreeProjector` should not live in Handler namespace; (3400484847) same for `TurnTreeReplayFilter`; (3400481245) truncation should use Symfony String; (3400482341/3400483744) one-line `previewForTitle()` helper used once should be removed/inlined. Moving back to IN-PROGRESS before implementation fixes.

## Task workflow update - 2026-06-12T03:38:35.785Z
- Recorded fork run: sgk8spvhpkhn
- Summary: Launched review-iteration fork `sgk8spvhpkhn` to address all PR #128 inline comments: move `TurnTreeProjector` from `Application\Handler` to `Domain\Run` and mirror test path; move `TurnTreeReplayFilter` from `Application\Handler` to `Application\Replay`; update imports/docs; switch manual truncation to Symfony String; remove/ineline one-use `previewForTitle()` helper. Fork instructed to run focused Castor validation plus deptrac/phpstan/cs and commit only.

## Task workflow update - 2026-06-12T03:44:22.009Z
- Recorded fork run: sgk8spvhpkhn
- Validation: Verified `git show --stat --find-renames HEAD`: 9 files changed, 3 renames, 20 insertions, 28 deletions; expected namespace/import/doc/test changes only.; `castor test --filter=TurnTreeProjectorTest` ok (13 tests, 69 assertions).; `castor test --filter=RunStateReplayServiceTest` ok (25 tests, 107 assertions).; `castor test --filter=ReplayServiceTest` ok (28 tests, 127 assertions).; `castor phpstan --path src/AgentCore` ok (errors=0, file_errors=0).; Targeted test phpstan ok for `tests/AgentCore/Domain/Run/TurnTreeProjectorTest.php`, `tests/AgentCore/Application/Handler/RunStateReplayServiceTest.php`, and `tests/AgentCore/Application/Handler/ReplayServiceTest.php`.; `castor deptrac` ok (violations=0, errors=0).; `castor cs-check` ok (files_fixed=0).; Stale reference grep for old `Application\Handler\TurnTree*` and `previewForTitle` returned no matches.
- Summary: Review-iteration fork completed. Commit `2588049b Address turn tree PR review comments` addresses all PR #128 inline comments: `TurnTreeProjector` moved to `Ineersa\AgentCore\Domain\Run`, `TurnTreeReplayFilter` moved to `Ineersa\AgentCore\Application\Replay`, imports/docs/tests updated, manual truncation replaced with Symfony String `u()->truncate()`, and one-use `previewForTitle()` removed/inlined. Worktree verified clean and ahead of remote by this commit only.

## Task workflow update - 2026-06-12T03:50:54.541Z
- Summary: Reviewer subagent re-reviewed commit `2588049b` and returned `APPROVE WITH SUGGESTIONS`. PR inline comments are resolved, but reviewer found two actionable follow-ups: inconsistent ASCII vs Unicode ellipsis in `TurnTreeProjector` prompt preview truncation, and `walkActivePath()` silently truncates active path if an intermediate parent turn reference is missing. Launching a narrow follow-up fork to fix both with tests.

## Task workflow update - 2026-06-12T03:51:12.510Z
- Recorded fork run: 5rowk3bcy2hb
- Summary: Launched follow-up fork `5rowk3bcy2hb` to address reviewer actionable findings after `2588049b`: use `TurnTreeProjector::truncate()` for `promptPreview` to keep Unicode ellipsis consistent, add dangling parent detection in `walkActivePath()`, and add/update focused projector tests. Fork instructed to run focused Castor validation and commit.
