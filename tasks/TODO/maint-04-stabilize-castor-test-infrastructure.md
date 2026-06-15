# MAINT-04 Stabilize and simplify Castor/test infrastructure

## Goal
## Context

Primary goal: make the QA/test workflow safe, deterministic, faster, and easier for humans/models to understand. Current `castor check` fans out many parallel steps, CodingAgent shards are slow, TUI/controller/real-LLM tests are flaky under parallel load, and failed runs can leave orphan worker processes.

Important constraints:
- Follow `AGENTS.md`, `.agents/skills/testing/SKILL.md`, and `tests/AGENTS.md` before touching tests or running QA.
- All QA/test/static-analysis/formatting commands must go through Castor.
- Do not weaken the required TUI real-flow proof gate; instead make it smaller, deterministic, and less duplicated.
- Preserve high-value E2E coverage while moving non-essential LLM dependency to deterministic replay/fixtures.

## Scout entrypoints

Castor/test runner:
- `castor.php` imports `.castor/tasks.php`
- `.castor/tasks.php`: `check()`, `test()`, `test_llm_real()`, `test_tui()`, `test_controller()`, `coding_agent_shard_groups()`, `tui_e2e_shard_groups()`, `build_test_worker_command()`, `build_tui_e2e_worker_command()`, `run_check_commands_parallel()`, `timeout_check_command()`, `cleanup_stale_check_workers()`
- `.castor/helpers.php`: `phar_ensure()`, `phar_build_with_lock()`, `check_llm_generation_ready()`, `run_commands_parallel()`

Likely quick wins found by scouts:
- `test_tui()` appears to run Doctrine migrations twice.
- `run_commands_parallel()` buffers all output in memory; stream logs incrementally to avoid pipe/memory hangs.
- `check()` has per-step timeouts but no overall wall-clock deadline.
- stale-worker cleanup is broad and process/session cleanup should be safer/less orphan-prone.
- TUI shard assignment is hardcoded and easy to unbalance.

CodingAgent test simplification candidates:
- `tests/CodingAgent/Runtime/RuntimeEventTypeTest.php` over-tests enum/intrinsic behavior.
- SafeGuard matcher tests have many tiny one-case methods that can become data-provider/table tests.
- Several KernelTestCase-heavy files likely dominate shard time: `ModelSelectionServiceTest`, `HatfieldSessionStoreTest`, `BashToolTest`, `BackgroundProcessManagerTest`.
- Large/high-method files to audit: `TranscriptProjectorTest`, `ViewImageToolTest`, `ToolRegistryTest`, `PathResolverTest`.

TUI/controller E2E candidates:
- `tests/Tui/E2E/TmuxHarness.php`
- `tests/Tui/E2E/TuiAgentSmokeTest.php`
- `tests/Tui/E2E/TuiStartupSnapshotTest.php` has an `exec sleep 10` pattern to remove.
- UI-only tests such as hotkeys, reasoning cycle, editor border color, and rename can be combined into fewer TUI journeys.
- Some TUI smoke assertions overlap and can be folded into multi-turn journeys.
- Controller tool E2Es can likely be grouped/data-driven around `collectEventsUntilToolCompleted()`.

LLM replay candidates:
- `src/CodingAgent/Infrastructure/SymfonyAi/SymfonyAiProviderFactory.php`
- `src/CodingAgent/Infrastructure/SymfonyAi/ConfiguredSymfonyAiPlatformFactory.php`
- `src/AgentCore/Infrastructure/SymfonyAi/LlmPlatformAdapter.php`
- `src/AgentCore/Contract/Hook/BeforeProviderRequestHookInterface.php`
- `src/AgentCore/Contract/Hook/LlmStreamObserverInterface.php`
- `tests/AgentCore/Infrastructure/SymfonyAi/TraceReplayTest.php`
- `tests/AgentCore/Fixtures/traces/successful-response.json`

Preferred replay direction: use AgentCore-level request/stream hooks to record fixtures and a fake/fixture Symfony AI `ModelClientInterface` or provider for replay. Keep a minimal real llama.cpp smoke path, but let most expensive/flaky LLM-dependent assertions run deterministically from fixtures.

## Acceptance criteria
- Castor/test infrastructure is refactored enough that QA steps are easier to reason about: duplicate migrations removed, runner responsibilities split or made declarative, and `castor list` still exposes the documented commands.
- `castor check` and `castor test` stream per-step logs incrementally to `var/reports/` instead of relying only on in-memory buffers, and failures/timeouts leave useful partial logs.
- E2E process cleanup is hardened: controller/TUI/messenger child process groups are terminated reliably; stale-worker cleanup is scoped to this project/run; repeated failed runs do not leave permanent orphan consumers or tmux sessions.
- A deterministic LLM replay mode exists for tests, based on existing Symfony AI/AgentCore seams, with fixtures checked into `tests/AgentCore/Fixtures/` or an equivalent test-only location; at least one existing real-LLM-dependent test path is ported to replay while one minimal real llama.cpp smoke remains.
- TUI E2E tests are simplified/consolidated: obvious duplicate boot flows are merged, UI-only interactions share fewer harness launches, the `exec sleep 10` startup-snapshot pattern is removed, and fixed sleeps are not added except harness polling/backoff.
- Low-value or redundant CodingAgent tests are reduced per `tests/AGENTS.md` (especially enum/intrinsic tests and many tiny matcher/path tests), without removing behavior-level coverage.
- CodingAgent shard balance is improved using measured or static cost weighting, or the task documents why a different runner strategy is chosen; no single shard should dominate avoidably.
- Validation is performed only via Castor. Required final validation: `castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check`, `castor test:tui`, `castor test:llm-real`/replay equivalent as applicable, and `LLM_MODE=true castor check` when tmux + llama.cpp prerequisites are available.
- Docs/instructions are updated if command semantics change: `.agents/skills/testing/SKILL.md`, `tests/AGENTS.md`, and/or relevant docs explain real vs replay LLM modes, cleanup expectations, and when to run which Castor task.
- Performance/stability result is recorded in the task work log: before/after timings, number of TUI harness launches, known remaining flakes, and any blocker if full E2E prerequisites are unavailable.

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
- Created: 2026-06-15T20:47:35.742Z
