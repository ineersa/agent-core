# Fix issue #134: prevent orphaned or unresolved assistant tool_calls from reaching providers

## Goal
GitHub issue: https://github.com/ineersa/agent-core/issues/134

Current triage summary:
- The durable streaming parser (`src/Platform/Bridge/Generic/DurableResultConverter.php`) likely fixed one contributing source: phantom/empty-id/sparse tool-call chunks are now suppressed/reconciled before final `ToolCallComplete`.
- The issue is still not proven fixed. The provider error can still be produced if the runtime sends a conversation containing an assistant message with `tool_calls` that is not immediately followed by matching tool result messages.

Evidence / likely remaining paths:
1. User steer/follow-up while a run is active or tools are pending:
   - `src/AgentCore/Application/Pipeline/ApplyCommandHandler.php` queues steer/follow_up and dispatches `AdvanceRun` via post-commit callback.
   - `src/AgentCore/Application/Pipeline/AdvanceRunHandler.php` applies pending turn-start commands and starts a new `ExecuteLlmStep` without guarding against unresolved `pendingToolCalls`, `isStreaming`, or an active in-flight step.
   - If a user message is inserted after an assistant tool-call but before tool results are committed, the next provider request violates OpenAI-style ordering.
2. Abort/cancel path with partial tool calls:
   - `src/AgentCore/Application/Pipeline/LlmStepResultHandler.php` abort branch appends the assistant message to `RunState.messages` when present, clears `pendingToolCalls`, and emits no tool results.
   - If the assistant message contains tool calls, a later follow-up after cancellation can resume with orphaned tool calls.
3. Provider conversion has no final invariant check:
   - `src/AgentCore/Infrastructure/SymfonyAi/AgentMessageConverter.php::assistantToolCalls()` converts assistant `metadata['tool_calls']` as-is.

Initial resolution direction:
- Define and enforce a state invariant: no LLM invocation may be dispatched while the prompt tail contains unresolved assistant tool calls.
- Prefer fixing orchestration so queued steer/follow_up during active work waits for the existing model/tool boundary instead of spawning an immediate parallel/early `AdvanceRun`.
- Fix abort/cancel handling so partial tool-call assistant messages are not persisted with executable `tool_calls` unless corresponding tool results will be produced.
- Add a defensive prompt-history validator/sanitizer or explicit local failure before provider conversion so regressions fail deterministically before hitting provider BadRequest.

Testing notes:
- Implementation touches runtime/LLM-visible flow, so forks must load `.agents/skills/testing/SKILL.md`, read `tests/AGENTS.md`, and validate via Castor only.
- Add deterministic regression tests for queued steer/follow_up during pending tool calls and for abort/cancel with partial tool calls.
- Consider controller replay/TUI E2E proof if user-visible runtime behavior changes; if TUI behavior is changed, real `TmuxHarness` E2E proof is mandatory.

## Acceptance criteria
- DurableResultConverter remains in place, but issue #134 is covered by runtime-level invariants rather than assuming parser durability is sufficient.
- Queued steer/follow_up while an LLM step or tool batch is active does not cause a new provider invocation until the current assistant tool calls have matching tool messages or the current turn is safely terminal.
- Abort/cancel paths do not persist assistant `tool_calls` that will never receive tool result messages, or otherwise convert them to a provider-safe transcript representation with clear events/diagnostics.
- Provider conversion or model invocation path has a defensive invariant check that prevents malformed assistant-tool/tool-result ordering from reaching live providers.
- Regression coverage proves the malformed ordering from issue #134 is prevented, including at least one test for user input during pending tool calls and one for abort/cancel with partial tool calls.
- Focused Castor validation passes: `castor test` for relevant filters, plus `castor deptrac`, `castor phpstan`, `castor cs-check`; run `castor check` before CODE-REVIEW. Run `castor test:llm-real` if live provider compatibility paths are changed.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/issue-134-orphaned-tool-calls
Worktree: /home/ineersa/projects/agent-core-worktrees/issue-134-orphaned-tool-calls
Fork run: b8uryk4sdhum
PR URL: https://github.com/ineersa/agent-core/pull/163
PR Status: open
Started: 2026-06-17T22:37:40.939Z
Completed:

## Work log
- Created: 2026-06-17T22:10:18.115Z

## Task workflow update - 2026-06-17T22:20:02.618Z
- Summary: Linked GitHub issue #152 as likely same command-mailbox/safe-boundary root cause: steer/follow_up queued during active/cancelling/restarted runs can be drained at the wrong time or out of order, causing duplicate/incorrect queued user messages. Design direction confirmed with user: drain steering only on safe model/tool/terminal boundaries; abort/cancel persistence should not leave provider-invalid assistant tool_calls; malformed transcript should fail loudly before provider conversion, not be silently filtered.

## Task workflow update - 2026-06-17T22:22:21.965Z
- Summary: pi-mono cancellation comparison: scout found pi-mono preserves aborted assistant messages as-is, including partial/complete toolCall content, with stopReason='aborted'; providers strip only streaming scratch fields like index/partialJson; no synthetic tool results are produced; UI marks pending tool components as Operation aborted. That design treats abort as meaningful conversation context. For agent-core, user prefers not to silently filter in AgentMessageConverter; local abort/cancel path should either drop aborted assistant prompt messages or explicitly sanitize them with diagnostics so provider-invalid orphan tool_calls are never persisted invisibly.

## Task workflow update - 2026-06-17T22:24:32.656Z
- Summary: Expanded implementation plan after user discussion.

Related GitHub issues:
- #134 (`assistant message with tool_calls must be followed by tool messages`) remains open and is the primary provider-facing failure.
- #152 (`Steering/followup queue seems to be broken`) is likely the same command-mailbox/safe-boundary root cause: queued steering/follow-up can be drained at the wrong time after cancel/restart, causing old queued messages to be consumed, duplicate steering messages, or incorrect queue order.

Confirmed design decisions:
- Steering/follow-up must be dispatched/drained only at safe boundaries.
- Do not silently filter malformed tool-call history in `AgentMessageConverter`; that hides state bugs.
- Add a loud/local invariant failure before provider invocation if malformed assistant tool-call ordering still exists.
- Abort/cancel persistence should drop aborted assistant messages from `RunState.messages`. Cancellation is already represented by events. If partial aborted text should be visible in the UI, keep/store/render it event-side, not as future prompt context.

pi-mono cancellation comparison:
- Scout checked `/home/ineersa/claw/pi-mono`.
- pi-mono preserves aborted assistant messages as-is with `stopReason: "aborted"`, including partial/complete tool-call blocks; providers strip only streaming scratch fields (`index`, `partialJson`); no synthetic tool results are generated; UI marks pending tool widgets as `Operation aborted`.
- Relevant pi-mono files from scout: `packages/agent/src/agent-loop.ts`, `packages/ai/src/providers/openai-responses.ts`, `packages/ai/src/providers/anthropic.ts`, `packages/coding-agent/src/core/agent-session.ts`, `packages/coding-agent/src/modes/interactive/interactive-mode.ts`.
- Do not copy pi-mono behavior directly into agent-core because agent-core currently persists plain `AgentMessage` without preserving `stopReason=aborted`; a later provider conversion would replay preserved tool calls as normal assistant tool calls.

Implementation detail plan:
1. Boundary-driven command draining:
   - Update `src/AgentCore/Application/Pipeline/ApplyCommandHandler.php` so queued `steer`/`follow_up` commands do not always dispatch `AdvanceRun` immediately.
   - If current state is active work (`RunStatus::Running` or `RunStatus::Cancelling`, active step, non-empty `pendingToolCalls`, or otherwise not a terminal safe boundary), enqueue command and emit `AgentCommandQueued`, but do not post-commit an `AdvanceRun`.
   - If current state is terminal/safe (`Completed`, `Failed`, `Cancelled`, possibly `WaitingHuman` depending existing semantics), keep/improve immediate dispatch so follow-up resumes terminal runs.
   - Existing safe drains remain: `LlmStepResultHandler` stop boundary for no-tool assistant responses, and `ToolCallResultHandler` after full tool batch commit.
   - Ensure steer superseding (`CommandMailboxPolicy::supersededSteerKeys`) still applies when commands are finally drained, so latest steer wins in one-at-a-time mode.

2. AdvanceRun safety guard:
   - Add an explicit guard in `src/AgentCore/Application/Pipeline/AdvanceRunHandler.php` before applying turn-start commands / dispatching `ExecuteLlmStep`.
   - If the run is already active with unresolved tool work or prompt-tail unresolved assistant tool calls, return no-op (or emit a diagnostic event if appropriate) instead of applying pending user commands and starting a new LLM step.
   - This prevents duplicate/parallel `AdvanceRun` messages from consuming queued commands out of order.

3. Abort/cancel persistence:
   - Update `src/AgentCore/Application/Pipeline/LlmStepResultHandler.php` aborted/cancelling branch.
   - Do not append `$message->assistantMessage` to `$messages` on abort/cancel. Drop all aborted assistant messages from future prompt context.
   - Keep emitting `LlmStepAborted` and `AgentEnd(cancelled)` with structural diagnostics (`step_id`, `stop_reason`, `usage`). If visible partial text/tool-call metadata is desired, include sanitized event payload fields only, not prompt-history `AgentMessage`.
   - Clear `pendingToolCalls`, `isStreaming`, and `streamingMessage` as today; preserve cancellation error text.

4. Provider preflight invariant:
   - Add a small invariant validator before provider invocation, preferably in/near `src/AgentCore/Infrastructure/SymfonyAi/LlmPlatformAdapter.php` after resolving/hydrating context messages and before `AgentMessageConverter::toMessageBag()`.
   - Validate OpenAI-style sequence: every assistant message with `metadata['tool_calls']` must be immediately followed by tool messages for every listed `tool_call_id` before any non-tool message appears; no orphan tool messages; no duplicate/missing tool_call_id in the immediate batch.
   - On violation, fail locally/loudly with a sanitized diagnostic (`run_id`, `step_id`, assistant message index, missing IDs/counts) and no raw prompt/tool output.
   - Do not mutate/filter messages in `AgentMessageConverter`.

5. Error propagation / user-visible failure:
   - The invariant failure should become a normal local LLM failure (`LlmStepFailed` / failed run state) rather than a provider BadRequest.
   - Diagnostic should be clear enough to identify malformed transcript/order while respecting log privacy rules.

Regression coverage to request from implementation fork:
- Load `.agents/skills/testing/SKILL.md` and read `tests/AGENTS.md` before tests/QA.
- Add unit/pipeline tests proving queued steer/follow_up during active/pending tool work is queued but not drained until stop/tool boundary.
- Add test for #152-style cancel/restart/follow-up ordering so an older queued steering message cannot be consumed unexpectedly after restart.
- Add LlmStepResultHandler test proving aborted assistant messages are not appended to `RunState.messages`, especially when assistant message contains tool calls.
- Add validator tests for valid assistant-tool sequence and invalid cases: assistant tool calls followed by user, missing tool result, orphan tool message, duplicate tool result.
- Run Castor validation only: focused `castor test --filter=...`, then `castor deptrac`, `castor phpstan`, `castor cs-check`; full `castor check` before CODE-REVIEW. Run `castor test:llm-real` only if live provider compatibility/conversion behavior is materially changed.

## Task workflow update - 2026-06-17T22:30:01.994Z
- Summary: Additional pi-mono scout findings and refined cancellation policy.

pi-mono details from second scout:
- User prompt that started a run remains in session/history if that run is later aborted.
- User messages queued while streaming/active (`steering`/`followUp`) are not persisted to session on abort; interactive mode clears those queues and restores queued text to the editor before calling `agent.abort()` (`packages/coding-agent/src/modes/interactive/interactive-mode.ts::restoreQueuedMessagesToEditor`).
- Aborted assistant messages are persisted in pi-mono and included in future LLM context because their typed `stopReason='aborted'` survives in history and rendering (`packages/agent/src/agent.ts::defaultConvertToLlm`, `packages/coding-agent/src/core/agent-session.ts::_handleAgentEvent`).
- pi-mono TUI renders text-only aborted assistant partials with `Operation aborted`; if aborted assistant had tool calls, pending tool components are marked as error.

Refined agent-core design:
- Keep already-applied user messages in `RunState.messages`. If a user message has reached prompt history (initial prompt or command applied at a safe boundary), cancellation should not erase it.
- Do not apply queued steer/follow_up messages while work is active. They should not become history until a safe boundary.
- On cancel, reject/clear pending unapplied steer/follow_up commands (or emit enough command-cancelled/rejected events for TUI to remove/restore pending text) so stale queued messages cannot be consumed by a later follow-up/restart (#152). This mirrors pi-mono restoring queued text instead of persisting it.
- Drop all aborted assistant messages from `RunState.messages` in agent-core. Unlike pi-mono, agent-core currently does not preserve `stopReason=aborted` on `AgentMessage`, so keeping aborted assistant content risks replaying partial tool calls as normal provider-visible `assistant.tool_calls`.
- If aborted partial assistant text/tool-call UI should be displayed later, record sanitized details in `LlmStepAborted`/event projection only; never use it as future prompt context.

## Task workflow update - 2026-06-17T22:35:17.980Z
- Summary: Finalized abort-message storage decision: implement simple safe behavior now by excluding aborted assistant messages from `RunState.messages` / future prompt context, but preserve enough sanitized event-side data for possible future TUI display. Concretely: `LlmStepResultHandler` abort/cancel branch should not append the assistant `AgentMessage`; `LlmStepAborted` event payload should include sanitized structural/preview metadata for the aborted assistant message (for example: whether an assistant message was present, text preview length/hash or bounded preview if existing event privacy permits, `has_tool_calls`, `tool_call_count`, tool call IDs/counts if safe, stop reason, usage, step id). This event data is projection/UI-only and must not be consumed as model prompt history. Already-applied user messages remain in `RunState.messages`; only unapplied queued steer/follow_up should be cleared/rejected on cancel so stale queued text cannot be consumed later.

## Task workflow update - 2026-06-17T22:37:40.939Z
- Moved TODO → IN-PROGRESS.
- Created branch task/issue-134-orphaned-tool-calls.
- Created worktree /home/ineersa/projects/agent-core-worktrees/issue-134-orphaned-tool-calls.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/issue-134-orphaned-tool-calls.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/issue-134-orphaned-tool-calls.
- Summary: Starting implementation. Decisions locked in: boundary-driven command draining; keep already-applied user messages; clear/reject unapplied steer/follow_up on cancel; drop aborted assistant messages from RunState.messages; preserve sanitized aborted assistant metadata in LlmStepAborted event payload/projection only; add provider preflight invariant for orphan assistant tool_calls.

## Task workflow update - 2026-06-17T22:41:16.883Z
- Recorded fork run: ujyykpezoctq
- Summary: Implementation fork launched in worktree `/home/ineersa/projects/agent-core-worktrees/issue-134-orphaned-tool-calls` with exact instructions: implement boundary-driven steer/follow_up draining, cancel rejection of stale queued steer/follow_up, AdvanceRun pending-tool guard, abort/cancel branch dropping assistant messages from RunState while preserving sanitized LlmStepAborted metadata, provider preflight assistant/tool-call sequence validator, focused regression tests, Castor validation, and commit changes on branch `task/issue-134-orphaned-tool-calls`.

## Task workflow update - 2026-06-17T23:00:26.494Z
- Recorded fork run: ujyykpezoctq
- Validation: Fork: `castor test --filter='ApplyCommandHandlerTest\|AdvanceRunHandlerTest\|LlmStepResultHandlerTest\|AgentMessageToolCallSequenceValidatorTest\|CommandMailboxPolicyTest'` — OK (34 tests, 201 assertions).; Fork: `castor deptrac` — OK, 0 violations.; Fork: `castor phpstan` — OK, 0 errors.; Fork: `castor cs-check` — OK, 0 files fixed.; Fork also ran full unit suite excluding E2E/live groups: 360 tests, 1 pre-existing TraceReplayTest DB error reproduced from main, 4 skipped.; Not run yet: full `castor check` (requires tmux; run during task-to-pr before moving to CODE-REVIEW).; Not run: `castor test:llm-real`; skipped because validator fails before live provider call and does not alter provider conversion compatibility.
- Summary: Implementation fork completed and committed on worktree branch at SHA `76a4f9eb3` (`Fix orphan assistant tool-call history handling`). Verified worktree is clean and diff stat shows expected core pipeline/provider/test changes. Implemented: queued steer/follow_up no longer dispatch immediate AdvanceRun while Running/Cancelling; cancel rejects pending steer/follow_up/continue; AdvanceRun no-ops while pendingToolCalls contains unresolved entries; LlmStepResultHandler abort/cancel branch keeps RunState.messages unchanged and emits sanitized `aborted_assistant` metadata in `llm_step_aborted`; new `AgentMessageToolCallSequenceValidator` + `MalformedToolCallSequenceException` wired into `LlmPlatformAdapter` before provider invocation; regression tests added/updated. Fork confirmed it read `AGENTS.md`, `.agents/skills/testing/SKILL.md`, and `tests/AGENTS.md`. Integration checkout was accidentally dirtied by the fork due relative edit/read tool paths; those duplicate uncommitted edits were discarded after confirming the worktree commit contains the changes. Integration checkout is clean again. Not moved to CODE-REVIEW per task-start workflow; `castor check` remains for task-to-pr phase.

## Task workflow update - 2026-06-17T23:15:49.567Z
- Validation: Reviewer subagent initial decision: APPROVED WITH SUGGESTIONS; all actionable findings addressed via fork.; Reviewer subagent final decision: APPROVE; no issues.; `castor test` in worktree — OK (2701 tests, 7995 assertions).; `castor deptrac` in worktree — OK (violations=0, errors=0).; `castor phpstan` in worktree — OK (errors=0, file_errors=0).; `castor cs-check` in worktree — OK (files_fixed=0).; `castor test:llm-real` in worktree — OK (5 tests, 51 assertions); run because task touches Symfony AI / LLM-visible preflight path.; Worktree status before CODE-REVIEW move: clean.
- Summary: Task-to-PR review completed. Initial reviewer returned APPROVED WITH SUGGESTIONS and flagged actionable items: duplicate tool results were reported as unknown while `duplicateToolResult()` was dead code; 10 formatter-required binary-mode `fopen` changes looked unrelated; and additional focused tests were desirable. A review-fix fork committed `32f4351d6` (`Address tool-call validator review findings`): duplicate tool result diagnostics now use `duplicate_tool_result`; validator system-message gap test added; mixed/all-resolved pending-tool-call AdvanceRun tests added; `ExecuteLlmStepWorker` error propagation test added; cancel-rejected command kinds extracted to a constant. Follow-up cleanup fork restored the formatter-required binary-mode files to HEAD after `castor cs-check` proved they are required by current CS rules; no additional commit was needed. Final reviewer re-review returned APPROVE with no actionable issues. Current HEAD is `32f4351d6`; worktree is clean.

## Task workflow update - 2026-06-17T23:16:43.837Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Running deterministic castor check in worktree (timeout 1200s)...
- castor check passed (36.3s).
- Pushed task/issue-134-orphaned-tool-calls to origin.
- branch 'task/issue-134-orphaned-tool-calls' set up to track 'origin/task/issue-134-orphaned-tool-calls'.
- Created PR: https://github.com/ineersa/agent-core/pull/163
- Validation: Final reviewer subagent: APPROVE, no actionable issues.; `castor test` — OK (2701 tests, 7995 assertions).; `castor deptrac` — OK (violations=0, errors=0).; `castor phpstan` — OK (errors=0, file_errors=0).; `castor cs-check` — OK (files_fixed=0).; `castor test:llm-real` — OK (5 tests, 51 assertions).
- Summary: Prepared for code review. Final reviewer decision: APPROVE. Worktree HEAD `32f4351d6` implements boundary-driven command draining, cancel rejection of stale queued commands, AdvanceRun unresolved-tool-call guard, abort/cancel dropping assistant messages from prompt history with sanitized `llm_step_aborted` metadata, and provider preflight validation for assistant tool-call/tool-result ordering. Review-fix commit addressed duplicate tool-result diagnostics and added additional regression coverage. Focused validation passed before move; deterministic `castor check` is run by this transition.

## Task workflow update - 2026-06-17T23:19:10.346Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: Reopening from CODE-REVIEW to address merge conflicts on PR #163 before returning to code review.

## Task workflow update - 2026-06-17T23:19:53.635Z
- Recorded fork run: b8uryk4sdhum
- Summary: Launched merge-conflict resolution fork in worktree `/home/ineersa/projects/agent-core-worktrees/issue-134-orphaned-tool-calls` on branch `task/issue-134-orphaned-tool-calls`. Instructions: fetch/merge latest `origin/main`, resolve conflicts while preserving issue #134/#152 behavior and current IN-PROGRESS task-board state, run focused Castor validation (`ApplyCommandHandlerTest|AdvanceRunHandlerTest|LlmStepResultHandlerTest|AgentMessageToolCallSequenceValidatorTest|CommandMailboxPolicyTest|ExecutionWorkerTest`, `castor cs-check`, plus deptrac/phpstan if possible), commit resolution, do not push or move task.

## Task workflow update - 2026-06-17T23:23:23.094Z
- Recorded fork run: b8uryk4sdhum
- Validation: Fork: `castor test --filter='ApplyCommandHandlerTest\|AdvanceRunHandlerTest\|LlmStepResultHandlerTest\|AgentMessageToolCallSequenceValidatorTest\|CommandMailboxPolicyTest\|ExecutionWorkerTest'` — OK (42 tests, 241 assertions).; Fork: `castor cs-check` — OK (0 files).; Fork: `castor deptrac` — OK (violations=0, errors=0).; Fork: `castor phpstan` — OK (errors=0, file_errors=0).; Fork: `castor test` — OK (2701 tests, 7995 assertions).; Parent: `castor test:llm-real` — OK (5 tests, 51 assertions).; Worktree status after merge + validation: clean at `c19acf824`, ahead of remote task branch pending push.
- Summary: Merge-conflict resolution fork completed. Fork merged latest `origin/main` into `task/issue-134-orphaned-tool-calls`, resolved the single conflict in `src/AgentCore/Application/Pipeline/ApplyCommandHandler.php` by keeping the branch's `REJECT_ON_CANCEL_KINDS` constant over main's semantically equivalent inline array, accepted current task-board state from `origin/main`, and committed merge `c19acf824` (`Merge origin/main into task/issue-134-orphaned-tool-calls`). Worktree is clean and branch is ahead of remote task branch pending push. Parent additionally reran live LLM smoke validation after the merge.

## Task workflow update - 2026-06-17T23:24:18.139Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Running deterministic castor check in worktree (timeout 1200s)...
- castor check passed (36.1s).
- Pushed task/issue-134-orphaned-tool-calls to origin.
- branch 'task/issue-134-orphaned-tool-calls' set up to track 'origin/task/issue-134-orphaned-tool-calls'.
- PR already exists: https://github.com/ineersa/agent-core/pull/163
- Validation: Merge-conflict fork: `castor test --filter='ApplyCommandHandlerTest\|AdvanceRunHandlerTest\|LlmStepResultHandlerTest\|AgentMessageToolCallSequenceValidatorTest\|CommandMailboxPolicyTest\|ExecutionWorkerTest'` — OK (42 tests, 241 assertions).; Merge-conflict fork: `castor cs-check` — OK (0 files).; Merge-conflict fork: `castor deptrac` — OK (violations=0, errors=0).; Merge-conflict fork: `castor phpstan` — OK (errors=0, file_errors=0).; Merge-conflict fork: `castor test` — OK (2701 tests, 7995 assertions).; Parent post-merge: `castor test:llm-real` — OK (5 tests, 51 assertions).
- Summary: Merge conflicts resolved and task branch refreshed against latest `origin/main`. Fork merge commit `c19acf824` resolved the only manual conflict in `src/AgentCore/Application/Pipeline/ApplyCommandHandler.php` by keeping the branch's `REJECT_ON_CANCEL_KINDS` constant; task-board state from main was accepted. Worktree is clean. Focused/full validation passed after merge; this transition runs deterministic `castor check`, pushes the refreshed branch, and updates PR #163.

## Task workflow update - 2026-06-17T23:35:09.044Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: Reopening PR #163 after user reproduced a remaining cancellation case: cancelling after an assistant tool_call has already been committed and a tool is running leaves the committed assistant tool_calls without matching tool messages. A later Continue correctly fails the new preflight validator with an unresolved tool-call sequence. Next fix: when cancellation finalizes while `pendingToolCalls` are present, synthesize provider-safe cancelled tool result messages for the committed assistant tool-call batch so future prompt history is valid.
