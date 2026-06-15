# MAINT-05 Cardinal rework of QA, Castor, and test architecture

## Goal
## Context

The current QA/test system has proven unreliable and too expensive to maintain. This is not a stabilization or hardening task. The goal is a cardinal redesign that removes the causes of flakiness, slowness, orphaned processes, and model/test confusion.

Current pain:
- `castor check` relies on many parallel process trees and still sometimes leaks/hangs workers.
- TUI E2E repeatedly launches tmux/harness sessions and waits through startup/model paths for small assertions.
- llama.cpp/OpenAI-compatible live calls are too unstable under parallel load for routine tests.
- CodingAgent unit/integration tests take far too long; they should be understandable and fast without needing parallelization to mask cost.
- Castor has grown too large and procedural; test orchestration needs a smaller declarative design, not more ad-hoc hardening.

Non-goals:
- Do not add another layer of sleeps, broad stale-killers, longer timeouts, or workaround cleanup.
- Do not merely rebalance shards while keeping the same test architecture.
- Do not keep normal QA dependent on live llama.cpp calls.
- Do not preserve redundant low-value tests just because they exist.

Required reading before implementation:
- `.agents/skills/testing/SKILL.md`
- `tests/AGENTS.md`
- `.agents/skills/task-workflow/SKILL.md`

## Target architecture

### 1. TUI E2E becomes journey/session based

Replace the many one-test-one-tmux-launch smoke tests with a small number of long-lived interactive TUI journeys.

Desired model:
- Launch one tmux/TUI session per journey class/suite, not per tiny assertion.
- Exercise many UI-only behaviors in one session: startup layout, editor keys, hotkeys, reasoning cycling, border/status state, rename, slash commands, shell-prefix local validation, etc.
- Launch separate sessions only when the behavior explicitly requires process start/end/resume/relaunch isolation.
- Avoid fixed sleeps; use exact visible/event proof helpers.
- Keep TmuxHarness, but reshape it around session journeys and reusable steps/assertions.
- Track and assert session cleanup at the end of a journey.

Known scout entrypoints:
- `tests/Tui/E2E/TmuxHarness.php`
- `tests/Tui/E2E/TuiAgentSmokeTest.php`
- `tests/Tui/E2E/TuiStartupSnapshotTest.php`
- `tests/Tui/E2E/HotkeySmokeTest.php`
- `tests/Tui/E2E/ImmediateSubmitFeedbackTest.php`
- `tests/Tui/E2E/ShellPrefixSmokeTest.php`
- `tests/Tui/E2E/EditorBorderColorTest.php`
- `tests/Tui/E2E/ReasoningCycleTest.php`
- `tests/Tui/E2E/SessionRenameE2ETest.php`
- `tests/Tui/E2E/PromptTemplateSlashCommandE2ETest.php`

### 2. Normal tests use deterministic LLM replay, not live llama.cpp

Routine QA must not hit llama.cpp/OpenAI-compatible live endpoints. Build a first-class test LLM replay system.

Required capabilities:
- Pre-recorded fixtures contain request identity, prompt/chain metadata, all streamed deltas, usage, stop reason, tool-call deltas, and relevant model metadata.
- Tests can replay fixtures through the same runtime/TUI/controller paths that production uses, as much as practical.
- A Castor command can re-record fixtures from live llama.cpp when explicitly requested.
- Normal `castor test` and default `castor check` use replay only.
- Keep a minimal opt-in live LLM smoke command for provider compatibility, outside the default fast deterministic gate.

Known scout entrypoints:
- `src/AgentCore/Infrastructure/SymfonyAi/LlmPlatformAdapter.php`
- `src/AgentCore/Contract/Hook/BeforeProviderRequestHookInterface.php`
- `src/AgentCore/Contract/Hook/LlmStreamObserverInterface.php`
- `src/CodingAgent/Infrastructure/SymfonyAi/SymfonyAiProviderFactory.php`
- `src/CodingAgent/Infrastructure/SymfonyAi/ConfiguredSymfonyAiPlatformFactory.php`
- `config/services_test.yaml`
- `tests/AgentCore/Infrastructure/SymfonyAi/TraceReplayTest.php`
- `tests/AgentCore/Fixtures/traces/successful-response.json`

Preferred direction from scout: record at AgentCore request/stream seams and replay with a test-only Symfony AI model client/provider or equivalent fixture-backed platform. Extend existing trace replay rather than inventing a disconnected fake.

### 3. CodingAgent tests become fast sequential unit/integration tests

Target: CodingAgent unit/integration suite should finish in under ~30s sequentially on a normal dev machine. Parallelization may remain optional, but must not be required to hide poor test structure.

Actions:
- Audit KernelTestCase usage. Keep kernel boots only for true container/DB integration behavior.
- Extract/preserve pure logic tests as plain PHPUnit tests.
- Collapse redundant one-case-per-method tests into table/data-provider tests only where behavior remains meaningful.
- Delete/reduce tests that only verify PHP intrinsics, enum mechanics, getters/setters, or exhaustive case enumeration.
- Prefer one behavior-focused test class per production class.
- Do not add production APIs solely for tests.

Known scout candidates:
- `tests/CodingAgent/Runtime/RuntimeEventTypeTest.php` over-tests enum mechanics.
- SafeGuard command/path matcher tests are over-fragmented.
- Kernel-heavy/slow candidates: `Config/ModelSelectionServiceTest.php`, `Session/HatfieldSessionStoreTest.php`, `Tool/BashToolTest.php`, `Tool/BackgroundProcessManagerTest.php`.
- Large audit candidates: `Runtime/Projection/TranscriptProjectorTest.php`, `Tool/ViewImageToolTest.php`, `Tool/ToolRegistryTest.php`, `Path/PathResolverTest.php`.

### 4. Castor QA orchestration is redesigned, not patched

Refactor Castor from a large procedural runner into smaller maintainable components/tasks.

Desired direction:
- Make default QA deterministic and safe first; parallel mode should be optional and conservative.
- Separate fast local validation, deterministic full validation, and opt-in live/provider validation.
- Avoid complex process-tree fan-out unless it is demonstrably safe.
- Prefer fewer long-lived controlled processes over many nested shell/proc trees.
- Ensure every spawned controller/messenger/tmux/process group has an explicit owner and teardown contract.
- Store logs incrementally and make failures inspectable without relying on in-memory buffers.

Known scout entrypoints:
- `castor.php`
- `.castor/tasks.php`
- `.castor/helpers.php`
- `check()`, `test()`, `test_tui()`, `test_llm_real()`, `test_controller()`
- `run_commands_parallel()`, `run_check_commands_parallel()`, `cleanup_stale_check_workers()`
- `build_test_worker_command()`, `build_tui_e2e_worker_command()`

## Suggested implementation phases inside this task

1. Design the new command matrix and document it before deep code changes.
   - Example split: fast deterministic default, replay E2E, live LLM smoke, explicit parallel stress mode.
2. Build LLM replay foundation and port one controller/TUI path to replay.
3. Rework TUI E2E into journey-based tests with dramatically fewer tmux launches.
4. Audit and reduce CodingAgent slow/redundant tests until sequential runtime target is realistic.
5. Refactor Castor around the new command matrix and remove obsolete parallel/live defaults.
6. Run full deterministic validation, then opt-in live smoke separately.
7. Record before/after timings and remaining risks.

## Acceptance criteria
- Default routine QA no longer depends on live llama.cpp/OpenAI-compatible network calls. Live provider checks are opt-in commands, not required for default deterministic validation.
- A first-class LLM replay system exists for tests, including fixture format, replay path, and an explicit Castor command to re-record fixtures from live llama.cpp/provider when needed.
- At least one realistic multi-turn/tool-call stream fixture includes all required deltas/tool-call information and is used by controller and/or TUI/runtime tests.
- TUI E2E tests are restructured into a small number of journey tests that reuse a long-lived tmux/TUI session for multiple assertions. Separate tmux launches remain only for start/end/resume/relaunch behavior that requires isolation.
- The total number of TUI harness launches in the default E2E suite is substantially reduced and documented before/after.
- All fixed sleeps and `exec sleep 10`-style patterns in TUI/controller tests are removed unless timing itself is the behavior under test; waits use exact visible/event proof helpers.
- Controller/messenger/TUI subprocess ownership is explicit. When a test ends or fails, child consumers/process groups are terminated by design, not by broad stale-killer workaround.
- CodingAgent unit/integration tests are audited and simplified so they can reasonably run sequentially in under ~30s on a normal dev machine, or the task records exact remaining blockers and why they cannot be removed in this pass.
- Low-value tests that verify PHP enum mechanics, getters/setters, class existence, or exhaustive intrinsic behavior are removed/reduced while preserving behavior-level coverage.
- Castor QA commands are reorganized into a clear command matrix: fast deterministic local validation, deterministic full validation, opt-in live/provider smoke, and optional parallel/stress mode. Documentation and skill/test instructions are updated accordingly.
- Castor no longer relies on the current fragile many-step parallel fan-out as the default quality gate. If parallel execution remains, it is optional and has a simpler ownership/logging model.
- Per-step logs are written incrementally to `var/reports/` or equivalent during command execution, so failures/timeouts leave useful artifacts without depending on in-memory output buffers.
- Validation uses Castor only. Required final validation includes the new deterministic default QA command, `castor deptrac`, `castor phpstan`, `castor cs-check`, journey-based TUI replay E2E, and the opt-in live smoke when prerequisites are available.
- Before/after metrics are recorded in the task: default QA wall time, CodingAgent sequential time, TUI harness launch count, live LLM calls in default QA, and known remaining flakes/risks.

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
- Created: 2026-06-15T20:59:06.144Z

## Task workflow update - 2026-06-15T21:04:20.587Z
- Summary: Scope update: ParaTest should replace custom Castor PHPUnit sharding, but not replace the broader test architecture rework. Castor may still orchestrate top-level independent lanes in parallel (unit/integration via ParaTest, serial TUI journeys, serial replay E2E, static analysis), while ParaTest owns PHPUnit-level parallelization for suites that are safe to parallelize.
- Decision: adopt ParaTest as the replacement for custom Castor PHPUnit sharding (`coding-agent-1..4`, manual file round-robin, worker command builders) after the suite is simplified. ParaTest should use `TEST_TOKEN` / `UNIQUE_TEST_TOKEN` for per-worker SQLite DB, Symfony cache, PHPUnit cache, reports, and logs.
- Command matrix refinement: keep Castor as the top-level orchestrator. It can still run independent lanes concurrently when safe, e.g. unit/integration lane, deterministic replay E2E lane, journey-based TUI lane, deptrac/phpstan/cs-check. But the unit/integration lane should invoke ParaTest instead of hand-rolled Castor shards.
- Safety boundary: do not use ParaTest for TUI journey tests by default. TUI E2E should remain mostly serial and long-lived-session based, with separate sessions only where start/end/resume isolation is the feature under test.
- Safety boundary: do not use ParaTest for controller/messenger E2E by default unless the new replay/process ownership model proves safe. Replay E2E should prioritize determinism and cleanup over max parallelism.
- Design target remains: CodingAgent tests should become fast and understandable enough to run sequentially in roughly under 30s. ParaTest is allowed as optional acceleration, not as a way to hide slow, overcomplicated tests.
- Acceptance refinement: Castor's fragile many-step custom PHPUnit fan-out must be removed or bypassed from the default quality gate. Any remaining Castor-level parallelization should operate on coarse, independent lanes with explicit process ownership and incremental logs.
