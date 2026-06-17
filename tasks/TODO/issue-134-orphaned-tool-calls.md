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
