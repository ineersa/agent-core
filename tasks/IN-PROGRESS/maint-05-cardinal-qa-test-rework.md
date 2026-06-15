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
Status: IN-PROGRESS
Branch: task/maint-05-cardinal-qa-test-rework
Worktree: /home/ineersa/projects/agent-core-worktrees/maint-05-cardinal-qa-test-rework
Fork run:
PR URL:
PR Status:
Started: 2026-06-15T21:12:17.664Z
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

## Task workflow update - 2026-06-15T21:05:21.261Z
- Summary: Scope update: Castor refactor is a first-class deliverable, not incidental cleanup. The current Castor implementation is too large/procedural for models and humans to safely edit; this task must split it by responsibility, remove unused/obsolete helpers, and replace ad-hoc procedural orchestration with explicit typed concepts where practical.
- Castor refactor requirement: `.castor/tasks.php` and `.castor/helpers.php` must not remain giant catch-all files after this work. Split responsibilities into focused files and/or classes/namespaces for QA command definitions, PHPUnit/ParaTest runner config, E2E runner config, process supervision, PHAR tasks, LLM fixture record/replay tasks, cleanup, reporting/logging, and environment/preflight checks.
- Remove obsolete Castor code as part of the rework: manual PHPUnit shard discovery/round-robin, old per-shard worker builders, unused hardening workarounds, stale compatibility paths, dead helper functions, and legacy commands that no longer fit the deterministic/replay command matrix.
- Model-editability is an explicit goal: keep files small, functions/classes single-purpose, and command behavior declarative enough that future agents do not need to understand a 2000+ line task runner before making a safe change.
- Refactor should preserve Castor as the single QA/tooling entrypoint, but Castor should become a thin orchestrator over well-named components rather than a monolithic procedural script.

## Task workflow update - 2026-06-15T21:08:22.204Z
- Summary: Umbrella task retained by user request. Do not delete MAINT-05; use it as the parent/epic record for the staged rework tasks rather than as a single implementation task.
- User explicitly said: "Don't delete 05". Keep `maint-05-cardinal-qa-test-rework.md` in TODO as the umbrella/epic task. Implementation should happen through smaller staged tasks: MAINT-05A Castor command matrix/modular foundation, MAINT-05B ParaTest unit/integration runner, MAINT-05C LLM replay/recording foundation, MAINT-05D controller replay E2E/process ownership, MAINT-05E TUI replay-backed journey E2E, MAINT-05F CodingAgent test diet/sequential speed, plus final integration/docs/cleanup if created.

## Task workflow update - 2026-06-15T21:09:17.183Z
- Summary: Umbrella/epic task for the staged cardinal QA/test rework. Do not implement MAINT-05 directly and do not delete it. Execute the linked MAINT-05A-G tasks in order/as dependencies allow, then use MAINT-05 to track overall completion and cross-task decisions.
- Umbrella links/stages:
- `tasks/TODO/maint-05a-castor-command-matrix-modular-foundation.md` — Castor command matrix and modular QA foundation.
- `tasks/TODO/maint-05b-paratest-unit-integration-runner.md` — Replace custom PHPUnit sharding with ParaTest.
- `tasks/TODO/maint-05c-llm-replay-recording-foundation.md` — LLM replay and fixture re-recording foundation.
- `tasks/TODO/maint-05d-controller-replay-e2e-process-ownership.md` — Controller replay E2E and explicit process ownership.
- `tasks/TODO/maint-05e-tui-journey-e2e-rework.md` — Rework TUI E2E into replay-backed journey tests.
- `tasks/TODO/maint-05f-codingagent-test-diet-sequential-speed.md` — CodingAgent test diet and sequential speed target.
- `tasks/TODO/maint-05g-deterministic-qa-cutover-docs-metrics.md` — Deterministic QA cutover, docs, and metrics.
- Execution intent: MAINT-05 remains the parent narrative and acceptance umbrella. A-F build the new pieces; G performs the final cutover/docs/metrics and updates this umbrella with final status.

## Task workflow update - 2026-06-15T21:12:17.664Z
- Moved TODO → IN-PROGRESS.
- Created branch task/maint-05-cardinal-qa-test-rework.
- Created worktree /home/ineersa/projects/agent-core-worktrees/maint-05-cardinal-qa-test-rework.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/maint-05-cardinal-qa-test-rework.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/maint-05-cardinal-qa-test-rework.
- Summary: Started umbrella/epic branch for the MAINT-05 staged QA/test rework. This branch is intended to serve as the integration/base branch for MAINT-05A-G PRs rather than a direct all-in-one implementation task. User requested PRs for staged tasks target this MAINT-05 branch instead of main.

## Task workflow update - 2026-06-15T21:13:07.275Z
- Summary: Epic/base-branch policy established for the staged MAINT-05 series: keep `task/maint-05-cardinal-qa-test-rework` as the integration branch for MAINT-05A-G. Stage PRs should target this branch, not `main`. Full `LLM_MODE=true castor check` and reviewer-subagent workflow are deferred until MAINT-05G; user will perform manual reviews for stage PRs.
- Branch/base policy: `task/maint-05-cardinal-qa-test-rework` is the MAINT-05 epic branch. When moving MAINT-05A-G tasks to CODE-REVIEW, pass `prBaseBranch="task/maint-05-cardinal-qa-test-rework"` to `move_task`. Do not target `main` for staged PRs.
- Validation/review policy: for MAINT-05A-F, run focused Castor validation appropriate to the stage, but skip the full `LLM_MODE=true castor check` gate until MAINT-05G. Skip reviewer subagent until MAINT-05G; user will review stage PRs manually. MAINT-05G owns final deterministic cutover validation, docs, metrics, and final full gate strategy.

## Task workflow update - 2026-06-15T21:34:55.741Z
- Summary: Policy change: main is now the MAINT-05 epic/integration branch. Do not use `task/maint-05-cardinal-qa-test-rework` as a PR base. Stage PRs for MAINT-05A-G should target `main`. Work should proceed sequentially, one MAINT-05 stage at a time; user will not run the staged work in parallel.
- User changed the branch strategy: "use main as epic branch" and "I won't run anything in parallel". Effective immediately: `main` is the integration branch for the staged MAINT-05 series. The previously-created `task/maint-05-cardinal-qa-test-rework` branch/worktree is no longer the PR base and should be treated as obsolete bookkeeping unless explicitly needed later.
- Execution policy: do not run MAINT-05A-G as parallel branches against an epic branch. Finish/review/merge each stage into `main` sequentially before starting the next stage unless the user explicitly changes this.
- PR base policy: when opening PRs for MAINT-05A-G, target `main` (default) rather than `task/maint-05-cardinal-qa-test-rework`.
- Validation/review policy remains: for MAINT-05A-F, skip reviewer subagent and skip full `LLM_MODE=true castor check`; user reviews manually. MAINT-05G owns the final full deterministic validation/docs/metrics.
