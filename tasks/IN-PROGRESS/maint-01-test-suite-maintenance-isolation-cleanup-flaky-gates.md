# MAINT-01 Test suite maintenance, isolation, cleanup, and flaky gate fixes

## Goal
Scouts audited test value, sleeps/performance, duplication/standards, isolation/artifacts/cleanup, and the current PHAR + llm-real gate failures. This task is a stop-and-maintenance pass to restore Castor check reliability, reduce low-value tests, remove test boilerplate, improve isolation, and make cleanup comprehensive.

Priority findings to address:

1. Fix current quality gate failures:
   - Castor PHAR smoke failure root cause: .castor/helpers.php phar_smoke() inherits real HOME, so PHAR can read ~/.hatfield/settings.yaml and fail when ai.default_model references a non-packaged model/provider. PHPUnit PharSmokeTest has HOME isolation, but Castor phar_smoke() does not.
   - ViewImageToolE2eTest is brittle: it fails when the LLM naturally says "does not support images" even though the image tool executed. Replace broad negative substring check with positive tool execution/event proof and only reject the exact gating placeholder.

2. Expand cleanup:
   - castor cleanup should remove missing generated artifacts: var/tmp/hatfield-llamacpp-*, var/test/app_test.sqlite, system /tmp test dirs created by tests, phar smoke/cache hash temp dirs, and PHAR staging/build caches.
   - phar_smoke() should use try/finally cleanup for /tmp/hatfield-phar-smoke-*.
   - Keep TUI E2E success snapshots under var/tmp/tui-e2e-* by design; cleanup removes them manually.

3. Reduce low-value tests:
   - Delete structural/introspection-only tests like tests/Tui/Utility/ClipboardTest.php.
   - Remove QuestionRequestTest::testObjectIsReadonly property-existence assertions.
   - Collapse enum/PHP-intrinsic tests in RuntimeEventTypeTest, RunStateTest, TranscriptBlockTest.
   - Reduce micro-case bloat in PathResolverTest, CodexOAuthConfigTest, EditorStateTest, ThemePaletteTest, DefaultThemeTest, SafeGuard matcher tests, AiConfigTest, builder/default getter tests.

4. Improve performance:
   - Replace HatfieldSessionStoreTest sleep(1) timestamp ordering workaround with a clock/test-time strategy.
   - Replace blind usleep waits in TuiStartupSnapshotTest with targeted waitForCaptureContains/waitForCallback conditions.
   - Avoid unnecessary PHAR staleness scans/rebuild checks for pure unit-test paths where possible.
   - Audit IsolatedKernelTestCase/kernel-boot overhead before changing lifecycle; do not break cwd or container isolation.

5. Establish shared test standards/helpers:
   - Expand TestDirectoryIsolation instead of direct sys_get_temp_dir(), manual .hatfield mkdir, and duplicated removeDir/rmdirRecursive methods.
   - Add shared TestMessageBus and TestLogger test doubles.
   - Add TestAiConfigBuilder for standardAiData(), makeAppConfig(), resolver/model-selection service builders.
   - Add TuiRuntimeContextBuilder for repeated TuiRuntimeContext construction.
   - Expand ControllerE2eTestCase with event indexing/ack assertions.
   - Add TuiE2eTestCase for tmux/TUI setup, agentCommand(), artifact dumping, and snapshot handling.
   - Add tests/AGENTS.md documenting standards.

Important non-goal: Do not delete valuable behavioral tests just to reduce count. Prefer fewer high-signal cases over many getter/enum/micro-case assertions.

## Acceptance criteria
- `LLM_MODE=true castor check` passes on main, including PHAR smoke and `test:llm-real` ViewImageToolE2eTest.
- `.castor/helpers.php::phar_smoke()` uses isolated HOME and cleans temp dirs with try/finally; PHAR no longer reads real `~/.hatfield/settings.yaml` during smoke.
- `ViewImageToolE2eTest` proves image tool execution via events/tool batch and no exact gating placeholder, without brittle broad LLM-output substring matching.
- `castor cleanup` removes TUI E2E success snapshots, failure snapshots, PHAR output/staging/cache dirs, LlamaCpp test dirs, test DB, QA/cache/log dirs, and known system `/tmp` test artifact prefixes; docs mention what is kept and cleaned.
- At least the zero-value tests are removed: `ClipboardTest.php` and `QuestionRequestTest::testObjectIsReadonly`.
- At least one representative assertion-bloat cluster is collapsed (enum/PHP-intrinsic tests or path/config micro-cases), with before/after test counts recorded in task notes.
- Shared test helper foundation is added or expanded for directory isolation and at least two of: `TestMessageBus`, `TestLogger`, `TestAiConfigBuilder`, `TuiRuntimeContextBuilder`, `ControllerE2eTestCase` event assertions, `TuiE2eTestCase`.
- Tests that create temp dirs use project `var/tmp` isolation or are covered by `castor cleanup`; no new direct unmanaged `sys_get_temp_dir()` prefixes are introduced.
- Sleeps audit addressed for the easy wins: remove `sleep(1)` in HatfieldSessionStoreTest and replace blind TUI startup waits with condition-based waits where feasible.
- `tests/AGENTS.md` or testing skill documents test standards: no structural getter-only tests, use shared fixtures/builders, use Castor, use TUI E2E proof for TUI behavior, and use cleanup/snapshot conventions.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/maint-01-test-suite-maintenance-isolation-cleanup-flaky-gates
Worktree: /home/ineersa/projects/agent-core-worktrees/maint-01-test-suite-maintenance-isolation-cleanup-flaky-gates
Fork run: glfjh5lgz225
PR URL:
PR Status:
Started: 2026-06-09T22:01:04.785Z
Completed:

## Work log
- Created: 2026-06-09T21:53:09.939Z

## Task workflow update - 2026-06-09T21:57:40.918Z
- Summary: Additional clean-context implementation context from scout reports:

Correction for ViewImageToolE2eTest: llama_cpp_test/test supports images. The failure is not because the test model lacks image support; the brittle part is that the test scans all LLM-generated prose for the generic phrase "does not support images". A model that supports images can still mention that phrase in explanatory prose. The robust assertion should prove the view_image path actually executed with image-capable flow and reject only the exact project gating placeholder text (for example text containing both "Actual image omitted" and "active model does not support images"), not arbitrary assistant wording.

Snapshots policy: keep all useful test snapshots/artifacts by default, especially TUI E2E snapshots under var/tmp/tui-e2e-*/. They are inspection artifacts, not garbage. `castor cleanup` is the manual cleanup mechanism.

Concrete copy-paste/refactor inventory the implementor should use:

1. Manual recursive rmDir/rmdirRecursive duplication exists in at least these files and should be replaced by a shared helper (expand TestDirectoryIsolation::removeDirectory or equivalent):
- tests/CodingAgent/Skills/SkillRegistryTest.php
- tests/CodingAgent/Skills/SkillsContextBuilderTest.php
- tests/CodingAgent/Skills/SkillDiscoveryTest.php
- tests/CodingAgent/Config/SessionAwareModelResolverTest.php
- tests/CodingAgent/Config/ModelSettingsPersisterTest.php
- tests/CodingAgent/Config/AppConfigLoaderTest.php
- tests/CodingAgent/Config/ModelSelectionServiceTest.php
- tests/CodingAgent/Config/AppConfigTest.php
- tests/CodingAgent/Tool/OutputCapLlmTransformHookTest.php
- tests/CodingAgent/Tool/OutputCapTest.php
- tests/CodingAgent/CLI/FileMentionIndexBuilderTest.php
- tests/CodingAgent/CLI/CompletionFileIndexRefreshCommandTest.php
- tests/Tui/Picker/ModelPickerControllerTest.php
- tests/Tui/Completion/FileMentionCompletionProviderTest.php
- tests/Tui/Completion/FileMentionIndexReaderTest.php
- tests/Tui/Listener/CopyCommandRegistrarTest.php
- tests/CodingAgent/Extension/ExtensionManagerTest.php
- tests/CodingAgent/SystemPrompt/AgentsContextDiscoveryTest.php
- tests/CodingAgent/SystemPrompt/SystemPromptBuilderTest.php
- tests/Tui/Listener/SessionCommandRegistrarTest.php
- tests/CodingAgent/Session/SessionRunStoreTest.php
- tests/CodingAgent/Session/AggregateResumeTest.php
- tests/CodingAgent/Logging/LogReaderTest.php

2. Manual temp directory creation via sys_get_temp_dir() is duplicated and often unmanaged by castor cleanup. Migrate to project var/tmp helpers where possible. Scout examples:
- tests/CodingAgent/Config/AppConfigTest.php
- tests/CodingAgent/Config/AppConfigLoaderTest.php
- tests/CodingAgent/Config/SessionAwareModelResolverTest.php
- tests/CodingAgent/Config/ModelSelectionServiceTest.php
- tests/CodingAgent/Config/ModelSettingsPersisterTest.php
- tests/CodingAgent/Config/HomeSettingsWriterTest.php
- tests/CodingAgent/Skills/SkillRegistryTest.php
- tests/CodingAgent/Skills/SkillDiscoveryTest.php
- tests/CodingAgent/SystemPrompt/AgentsContextDiscoveryTest.php
- tests/CodingAgent/SystemPrompt/SystemPromptBuilderTest.php
- tests/CodingAgent/Extension/ExtensionManagerTest.php
- tests/Tui/Picker/ModelPickerControllerTest.php
- tests/Tui/Listener/CopyCommandRegistrarTest.php
- tests/Tui/Listener/ModelCommandHandlerTest.php
- SessionRunStoreTest, AggregateResumeTest, SessionRunEventStoreTest, LogReaderTest also use system temp patterns.

3. Manual .hatfield tree scaffolding is duplicated. Replace with TestDirectoryIsolation::createHatfieldTree or new helper:
- tests/CodingAgent/Auth/CodexAuthStorageTest.php
- tests/CodingAgent/Auth/CodexOAuthServiceTest.php
- tests/CodingAgent/Config/SessionAwareModelResolverTest.php
- tests/CodingAgent/Config/ModelSettingsPersisterTest.php
- tests/CodingAgent/Config/AppConfigLoaderTest.php
- tests/CodingAgent/Config/AppConfigTest.php
- tests/CodingAgent/Config/HomeSettingsWriterTest.php
- tests/CodingAgent/Session/SessionRunStoreTest.php
- tests/Tui/Listener/CopyCommandRegistrarTest.php
- tests/Tui/Picker/ModelPickerControllerTest.php
- tests/AgentCore/Infrastructure/SymfonyAi/TraceReplayTest.php

4. Manual settings.yaml heredocs are duplicated. Add helper(s) for minimal test home/project settings and test LLM settings. Scout examples:
- tests/CodingAgent/Config/SessionAwareModelResolverTest.php
- tests/CodingAgent/Config/ModelSettingsPersisterTest.php
- tests/CodingAgent/Config/ModelSelectionServiceTest.php
- tests/CodingAgent/Config/AppConfigLoaderTest.php
- tests/CodingAgent/Config/AppConfigTest.php
- tests/Tui/Picker/ModelPickerControllerTest.php
- tests/Tui/E2E/TuiAgentSmokeTest.php
- tests/Tui/E2E/TuiStartupSnapshotTest.php
- tests/CodingAgent/Runtime/Controller/E2E/ControllerE2eTestCase.php

5. Duplicate standardAiData/makeAppConfig/model service setup should be centralized in a TestAiConfigBuilder or fixture:
- tests/CodingAgent/Config/ModelResolverTest.php
- tests/CodingAgent/Config/SessionAwareModelResolverTest.php
- tests/CodingAgent/Config/ModelSelectionServiceTest.php
- tests/Tui/Picker/ModelPickerControllerTest.php
- tests/Tui/Listener/ModelCommandHandlerTest.php
- tests/AgentCore/Infrastructure/SymfonyAi/TraceReplayTest.php

6. Duplicate MessageBus test doubles should be replaced by one shared TestMessageBus:
- ExecutionWorkerTest CollectingMessageBus
- ExecutionFailureDrillTest DrillCollectingMessageBus / FailingOnceMessageBus (keep failing variant if behavior differs)
- StartRunHandlerTest StartRunRecordingBus
- LlmStepResultHandlerTest LlmHandlerRecordingBus
- ApplyCommandHandlerTest ApplyCommandRecordingBus
- CommandMailboxPolicyTest MailboxRecordingMessageBus

7. Duplicate TuiRuntimeContext construction should be replaced by TuiRuntimeContextBuilder:
- tests/Tui/Listener/CompletionListenerTest.php
- tests/Tui/Listener/PromptHistoryListenerTest.php
- tests/Tui/Listener/CopyCommandRegistrarTest.php
- tests/Tui/Listener/SessionCommandRegistrarTest.php
- tests/Tui/Listener/CancelListenerTest.php

8. Duplicate controller E2E event indexing/ack checks should move into ControllerE2eTestCase:
- OutputCapReadFileControllerTest already has indexByType()/foundAck() style helpers.
- ControllerSmokeTest, WriteFileToolE2eTest, ViewImageToolE2eTest still have inline event indexing/ack loops.

9. Duplicate TUI E2E setup should become TuiE2eTestCase:
- TuiAgentSmokeTest and TuiStartupSnapshotTest duplicate agentCommand(), createIsolatedProjectDir(), settings setup, snapshot/artifact flow.

Recommended implementation sequence for maintainability:
A. Fix gate blockers first: phar_smoke HOME isolation + ViewImageToolE2eTest robust assertion.
B. Expand cleanup and artifact handling.
C. Add shared helpers with no behavior change: TestMessageBus, TestLogger, ControllerE2eTestCase event helpers, directory isolation helpers.
D. Refactor copy-paste sites gradually; do not combine with major test deletion in the same commit if it makes review noisy.
E. Remove/collapse low-value tests after helper extraction so the suite gets smaller and clearer.

Implementation warning: this is a maintenance task; avoid broad production API changes just for tests. Shared helpers must live under tests/. Production code changes are acceptable only for real bugs such as phar_smoke HOME isolation/castor cleanup behavior or replacing sleep with existing clock seams.

## Task workflow update - 2026-06-09T22:00:08.682Z
- Summary: Add Castor check parallelization objective:

Once isolation fixes are in place, optimize `castor check` by running independent validation phases in parallel where safe. This belongs in MAINT-01 because the task explicitly audits and fixes test isolation; parallel check is a good validation pressure test for that isolation.

Desired design:
- Run a single PHAR ensure/build step before parallel test groups so each worker does not independently rebuild or race on `var/tmp/phar` / `var/tmp/phar-build`.
- Then run independent phases concurrently where safe: `castor test`, `castor test:controller`, `castor test:llm-real`, `castor test:tui`, `castor phpstan`, `castor deptrac`, `castor cs-check`.
- Capture each branch output/report separately under `var/qa/` or branch-specific report files, then print a combined summary.
- Do not fail-fast in a way that loses diagnostics; collect all branch results and report every failure.
- Guard or serialize groups that remain unsafe after audit. Known risks to verify: shared `var/test/app_test.sqlite`, unique tmux session names, unique `var/tmp/test-*`/`var/tmp/tui-e2e-*` dirs, PHAR staging/cache races, and llama.cpp endpoint concurrency.
- If DB isolation cannot safely support parallel `castor test` + E2E groups, keep DB-using groups serialized but still run phpstan/deptrac/cs-check in parallel with test execution.

Acceptance addendum: `LLM_MODE=true castor check` should either run independent branches in parallel safely, or explicitly document/encode why a branch remains serialized. Parallelization must not be merged until isolation artifacts prove there are no cross-run temp/session/HOME/DB collisions.

## Task workflow update - 2026-06-09T22:01:04.785Z
- Moved TODO → IN-PROGRESS.
- Created branch task/maint-01-test-suite-maintenance-isolation-cleanup-flaky-gates.
- Created worktree /home/ineersa/projects/agent-core-worktrees/maint-01-test-suite-maintenance-isolation-cleanup-flaky-gates.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/maint-01-test-suite-maintenance-isolation-cleanup-flaky-gates.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/maint-01-test-suite-maintenance-isolation-cleanup-flaky-gates.

## Task workflow update - 2026-06-09T23:10:20.386Z
- Recorded fork run: 59h6xp2tn72u
- Validation: castor test — PASS (2269 tests, 6660 assertions, 0 errors/failures/skipped); castor deptrac — PASS (0 violations); castor phpstan — PASS (0 errors); castor cs-check — PASS after cs-fix; castor test:llm-real --filter ViewImageToolE2eTest — PASS (1 test, 11 assertions); castor phar:build — PASS / PHAR smoke ok (reported by fork); LLM_MODE=true castor check — not run by fork; full flow requires tmux + llama.cpp:9052 and should be run during task-to-pr/move_task gate
- Summary: Continuation implementation fork completed. Worktree branch task/maint-01-test-suite-maintenance-isolation-cleanup-flaky-gates is clean at HEAD 3cf1a257, with commits 660b2135, 39a24aa5, b69a059f, 3cf1a257 on top of task metadata. Cumulative diff vs origin/main: 21 files changed, +556/-435.

Implemented: phar_smoke HOME isolation and try/finally temp cleanup; ViewImageToolE2eTest changed from brittle generic LLM-prose rejection to robust image/tool execution proof plus exact gating-placeholder rejection; castor cleanup expanded for var/tmp, var/test app DB, PHAR dirs, LlamaCpp dirs, and known system /tmp prefixes; ControllerE2eTestCase event helpers added and controller E2E tests migrated; shared TestMessageBus and TestLogger added and 6 duplicate MessageBus test doubles migrated; tests/AGENTS.md added with standards; low-value tests removed/collapsed (ClipboardTest deleted, QuestionRequest readonly-structure test removed, RunState tests collapsed); .agents/skills/testing points to tests/AGENTS.md; castor check parallelization added with one PHAR prebuild, pcntl_fork parallel group for deptrac/test/phpstan/cs-check, sequential fallback, and controller/llm-real/tui serialized for DB/tmux/LLM safety.

Deferred by fork with rationale: TestAiConfigBuilder, TuiRuntimeContextBuilder, TuiE2eTestCase, full rmDir/sys_get_temp_dir migration, TUI startup usleep replacement, and HatfieldSessionStoreTest sleep removal. Fork found HatfieldSession/TimestampableLifecycleTrait uses direct new DateTimeImmutable(), so Clock mocking cannot remove sleep without production timestamp seam changes. Fork also found RuntimeEventTranslator drops tool_batch_committed from stdout stream, so ViewImageToolE2eTest now reads persisted events.jsonl for that proof.

## Task workflow update - 2026-06-10T19:47:37.709Z
- Summary: Additional performance objective requested by user: split `castor test` internally into parallel PHPUnit suites and isolate DB-using suites with per-worker SQLite DB paths.

Desired design:
- Replace hardcoded test DB path (`%kernel.project_dir%/var/test/app_test.sqlite`) with an env-driven path such as `%env(default:...:HATFIELD_TEST_DATABASE_PATH)%` or equivalent Symfony config approach.
- Castor should create/select a unique DB file per PHPUnit worker/suite, run migrations/schema setup for that worker DB, and pass the env var into that suite process.
- Split `castor test` into logical suites/groups that can run concurrently, for example AgentCore unit, CodingAgent unit/config/filesystem, TUI unit, DB/kernel integration, extensions, etc. Exact split should be based on existing test directory/group layout and isolation risks.
- `castor test` and `castor check` should run these suites in parallel, with separate JUnit/report/log files per suite and a combined summary. No fail-fast; collect all failures.
- DB suites can run in parallel if each has its own SQLite DB. Non-DB suites should not pay DB migration cost.
- Keep `castor test --filter=...` behavior working; filter mode can run a single sequential PHPUnit invocation if easier.
- Preserve existing exclusions for `tui-e2e` and `llm-real`; those remain separate top-level checks unless intentionally included by `castor check` orchestration.

Rationale: `castor test` alone currently takes roughly 1m40s, so top-level `castor check` parallelization is not enough. Internal PHPUnit suite parallelism is likely the next biggest performance win once test isolation is cleaned up.

## Task workflow update - 2026-06-10T19:52:36.153Z
- Summary: Scout findings for internal `castor test` parallelization with per-worker DB:

Exact seams:
1. Test DB is currently hardcoded in `config/packages/test/doctrine.yaml` as `path: '%kernel.project_dir%/var/test/app_test.sqlite'`.
   - Recommended change: make it env-overridable while preserving default, e.g. `path: '%env(default:kernel.project_dir/var/test/app_test.sqlite:string:HATFIELD_TEST_DATABASE_PATH)%'` or equivalent Symfony env processor syntax.
   - Castor can then pass `HATFIELD_TEST_DATABASE_PATH=var/test/app_test-<worker>.sqlite` per PHPUnit worker.

2. `castor test()` currently:
   - creates `var/test/`
   - runs one migration command: `APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration`
   - runs `vendor/bin/phpunit --exclude-group tui-e2e --exclude-group llm-real`
   - in LLM_MODE writes one report pair: `var/reports/phpunit.junit.xml` and `var/reports/phpunit.log`
   - applies filter by appending `--filter=<filter>`.

3. PHPUnit suites already exist in `phpunit.xml.dist`:
   - `agent-core` => `tests/AgentCore`
   - `coding-agent` => `tests/CodingAgent`
   - `tui` => `tests/Tui`
   - `platform` => `tests/Platform`
   - Exclusions for `tui-e2e` and `llm-real` still apply to `castor test`.

4. Collision surfaces for parallel `castor test`:
   - JUnit XML and logs must be unique per worker, e.g. `phpunit-agent-core.junit.xml`, `phpunit-agent-core.log`.
   - PHPUnit cache should be unique per worker via `--cache-directory var/cache/.phpunit-<worker>`.
   - SQLite DB must be unique per DB-using worker via `HATFIELD_TEST_DATABASE_PATH`.
   - Existing `var/tmp/hatfield-test-*` isolation is random per test and safe.

5. DB migration per worker:
   - For each DB-using worker, run migrations with `APP_ENV=test HATFIELD_TEST_DATABASE_PATH=<worker-db> php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration` before that worker's PHPUnit command.
   - DAMA DoctrineTestBundle static connection is process-local, so separate PHPUnit processes + separate DB files should be safe.

6. Suggested behavior for `castor test --filter=...`:
   - Keep sequential path for filters to preserve simplicity and expected targeting. Parallelism is useful for full-suite runs, not one filtered class/method.

7. Low-risk implementation strategy:
   - First pass can split by existing PHPUnit suites: agent-core, coding-agent, tui, platform. Give each worker its own report/cache and DB env to be maximally safe, even if some suites do not use DB. This avoids needing perfect classification up front.
   - More aggressive future split can separate pure vs kernel DB tests, but existing suite split is enough to reduce the current ~1m40 `castor test` wall time substantially.
   - `castor check` should call the parallelized `test()` as one branch, and top-level check can also run controller/llm-real/tui/static branches in parallel as separately implemented.

Implementation warning: reports and summaries must aggregate all worker results; do not fail-fast. Keep standalone sequential behavior for filtered runs and ensure `LLM_MODE` summaries still show meaningful combined failure output.

## Task workflow update - 2026-06-10T19:53:09.199Z
- Recorded fork run: sez5vrrck2v7
- Validation: castor test — PASS (2269/2269, 6660 assertions); castor deptrac — PASS (0 violations); castor phpstan — PASS (0 errors); castor cs-check — PASS (clean after fix); php -l .castor/tasks.php — PASS (reported by fork; raw PHP syntax check only); LLM_MODE=true castor check — not run by fork; requires tmux + llama.cpp:9052 and will be exercised by gate after internal test parallelism is complete
- Summary: Performance fork completed at HEAD 083bda0e and pushed. It rewrote `.castor/tasks.php::check()` so all 7 top-level quality steps run concurrently after a single PHAR ensure/build: deptrac, test, test:controller, test:llm-real, test:tui, phpstan, cs-check. Added per-step timing, visible parallel/sequential mode output, child stdout capture to `var/reports/check-<step>.log`, total wall-time summary, and no fail-fast/skip behavior. Sequential fallback remains for environments without pcntl_fork. Docs updated in tests/AGENTS.md and testing skill. Branch is clean/pushed at 083bda0e.

Important limitation: this fork did not implement the newly requested internal `castor test` suite split with per-worker DB. `castor test` remains one branch inside `castor check`; follow-up implementation fork is needed to reduce the standalone ~1m40 `castor test` wall time.

## Task workflow update - 2026-06-10T20:10:01.235Z
- Recorded fork run: glfjh5lgz225
- Validation: vendor/bin/phpunit --exclude-group tui-e2e --exclude-group llm-real — PASS (2269/2269, 6648 assertions, 4 skipped); vendor/bin/phpunit --testsuite agent-core with isolated DB — PASS (271/271, 1145 assertions); vendor/bin/phpunit --testsuite coding-agent with isolated DB — PASS (1333/1333, 3777 assertions, 4 skipped); vendor/bin/phpunit --testsuite tui with isolated DB — PASS (611/611, 1505 assertions); vendor/bin/phpunit --testsuite platform with isolated DB — PASS (54/54, 221 assertions); vendor/bin/phpunit --filter RunStateTest — PASS (3/3, 33 assertions); vendor/bin/deptrac --config-file=depfile.yaml — PASS (0 violations); vendor/bin/phpstan analyse — PASS (0 errors); vendor/bin/php-cs-fixer fix --dry-run — PASS (clean); LLM_MODE=true castor check — not run by fork; needs task-to-pr gate
- Summary: Internal `castor test` parallelization fork completed at HEAD 1a4ecf7a and pushed. It added per-suite parallel `castor test` by existing PHPUnit suites (`agent-core`, `coding-agent`, `tui`, `platform`), with one worker per suite, unique SQLite DB filename via `HATFIELD_TEST_DATABASE_PATH`, unique migrations, unique PHPUnit cache dir, unique JUnit XML/log files, no fail-fast, per-suite timings, and sequential fallback. `castor test --filter=...` remains sequential. `config/packages/test/doctrine.yaml` now uses env-overridable DB path with parameter fallback after discovering Symfony `%env(default:...)%` fallback refers to a container parameter. Docs updated in tests/AGENTS.md and testing skill.

Important: fork validated with raw vendor/bin commands because it reported Castor runtime unavailable in worktree; main/orchestrator still needs to run Castor validation before CODE-REVIEW.
