# TOOLS-R05 Parallel tool execution orchestration

## Goal
Implement true parallel tool execution above individual tool execution, using durable runtime state and multiple tool workers rather than hiding parallelism inside any individual tool runner.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Context:
- TOOLS-00 is intentionally minimal: it provides current tool execution context and cancellation token access, not cross-process PID/process management.
- Symfony AI PR #1829 adds fiber-based cooperative execution, but fibers do not provide OS-level parallelism for blocking foreground subprocess waits.
- Existing `ToolBatchCollector` is in-memory and cannot safely coordinate parallel tool calls across multiple Messenger tool consumers.
- This task owns the cross-process orchestration needed for true parallel tools.

Dependencies:
- Depends on TOOLS-R03 (registry-backed Toolbox and allowlist propagation) for stable tool execution messages and toolsRef handling.
- Depends on TOOLS-R04 (tool settings hydration) for `tools.execution.max_parallelism` and related execution settings.
- Depends on TOOLS-00 for cancellation context primitives available to each tool worker.

Scope:
- Design and implement durable per-run/per-turn/per-step tool batch state so multiple tool consumers can work on one model tool-call batch safely.
- Configure/launch multiple `tool` Messenger consumers according to Hatfield settings, or otherwise document and implement the selected parallel worker strategy.
- Preserve model/tool-call ordering when collecting results back into the canonical run state.
- Honor `tools.execution.max_parallelism` and per-tool execution mode/policy (`sequential`, `parallel`, `interrupt`) from the existing execution policy model.
- Ensure run cancellation marks pending/in-flight tool calls consistently and makes cancellation state visible to each in-flight tool worker through the existing cancellation token/context path.
- Add observability/logging for batch scheduling, dispatch, completion, cancellation, and retry/failure paths.
- Add product-level validation that exercises multiple tool calls in one turn through the real async runtime.

Out of scope:
- Do not introduce per-tool process runner/process registry abstractions; concrete process-owning tools should handle their own local cancellation checks when they exist.
- Do not implement Bash backgrounding; that remains TOOLS-09/TOOLS-08.
- Do not rely on PHP Fibers for blocking subprocess parallelism.

Implementation notes:
- Prefer Symfony Messenger/DBAL/Serializer primitives over custom lock files or hand-rolled routers.
- If durable state uses SQLite, reuse the existing project-local Doctrine DBAL connection/database rather than adding a separate lock-file mechanism.
- Keep AgentCore free of CodingAgent dependencies; App/runtime orchestration belongs in CodingAgent or AgentCore contracts as appropriate.

## Acceptance criteria
- Parallel execution has a dedicated durable batch state, not an in-memory-only collector.
- Multiple tool calls from one model turn can execute concurrently when policy/settings allow it.
- Sequential/interrupt tools still execute in order and block later parallel dispatch where required.
- Results are applied to the run in the correct tool-call order regardless of completion order.
- Run cancellation cancels pending calls and exposes cancellation to all in-flight tool workers without central PID/process tracking.
- Settings document and control max parallelism / worker behavior; `.hatfield/settings.yaml` and `docs/settings.md` stay in sync.
- Focused unit/integration tests cover scheduler state transitions, ordering, cancellation, and failure handling.
- A product-level Castor workflow (`castor test:controller` or equivalent) validates the real async runtime path.

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
- Created: 2026-05-26T20:20:59.767Z
