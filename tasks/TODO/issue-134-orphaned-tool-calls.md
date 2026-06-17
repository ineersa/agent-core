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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
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
