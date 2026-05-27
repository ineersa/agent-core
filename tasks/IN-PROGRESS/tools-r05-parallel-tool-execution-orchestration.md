# TOOLS-R05 Parallel tool execution orchestration

## Goal
Implement true parallel tool execution above individual tool execution, using durable runtime state and multiple tool workers rather than hiding parallelism inside any individual tool runner.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Context:
- TOOLS-00 is intentionally minimal: it provides current tool execution context and cancellation token access, not cross-process PID/process management.
- Symfony AI PR #1829 adds fiber-based cooperative execution, but fibers do not provide OS-level parallelism for blocking foreground subprocess waits.
- Existing `ToolBatchCollector` was purely in-memory and could not coordinate across consumer processes.
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
- [x] Parallel execution has a dedicated durable batch state, not an in-memory-only collector.
- [x] Multiple tool calls from one model turn can execute concurrently when policy/settings allow it.
- [x] Sequential/interrupt tools still execute in order and block later parallel dispatch where required.
- [x] Results are applied to the run in the correct tool-call order regardless of completion order.
- [x] Run cancellation cancels pending calls and exposes cancellation to all in-flight tool workers without central PID/process tracking.
- [x] Settings document and control max parallelism / worker behavior; `.hatfield/settings.yaml` and `docs/settings.md` stay in sync.
- [x] Focused unit/integration tests cover scheduler state transitions, ordering, cancellation, and failure handling.
- [ ] product-level validation (`castor test:controller`) — requires running llama.cpp E2E, not feasible in automated fork; best-available validation: castor test (1086 tests, 10266 assertions, 0 errors), deptrac, cs-check.

## Implementation summary

### New files
- `src/AgentCore/Contract/Tool/ToolBatchStoreInterface.php` — contract for durable batch state
- `src/AgentCore/Application/Handler/InMemoryToolBatchStore.php` — default/fallback store
- `src/CodingAgent/Tool/Store/DbalToolBatchStore.php` — Doctrine DBAL/SQLite store
- `tests/AgentCore/Application/Handler/InMemoryToolBatchStoreTest.php` — 5 tests
- `tests/CodingAgent/Tool/Store/DbalToolBatchStoreTest.php` — 7 tests (in-memory SQLite)
- `tests/AgentCore/Application/Handler/ToolBatchCollectorDurableTest.php` — 6 cross-process recovery tests

### Modified files
- `src/AgentCore/Application/Handler/ToolBatchCollector.php` — optional store, serialization/reconstruction
- `src/CodingAgent/Runtime/Controller/ConsumerSupervisor.php` — multi-worker support
- `src/CodingAgent/Runtime/Controller/HeadlessController.php` — launch N tool workers
- `config/services.yaml` — wire DbalToolBatchStore as ToolBatchStoreInterface
- `docs/tool-execution.md` — durable batch + parallel dispatch section
- `docs/settings.md` — max_parallelism worker count docs
- `config/hatfield.defaults.yaml` — worker count comment
- `.pi/plans/toolbox-design-plan.md` — R05 section updated

### Key design decisions
1. Batch state stored as single JSON blob per run/turn/step in `tool_batch_state` table
2. ToolBatchCollector loads from store on in-memory cache miss → reconstructs ExecuteToolCall/ToolCallResult from serialized call data
3. RunLockManager serializes concurrent batch updates per run ID (already present in RunMessageProcessor)
4. Tool worker count defaults to `max_parallelism` setting
5. Table created lazily (CREATE TABLE IF NOT EXISTS) — no explicit migration needed

## Workflow metadata
Status: IN-PROGRESS
Branch: task/tools-r05-parallel-tool-execution-orchestration
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-r05-parallel-tool-execution-orchestration
Fork run:
PR URL:
PR Status:
Started: 2026-05-26T21:00:00
Completed:

## Work log
- Created: 2026-05-26T20:20:59.767Z
- Implemented: 2026-05-26T21:00:00 — durable batch store, multi-worker support, cross-process coordination, 20 new tests
