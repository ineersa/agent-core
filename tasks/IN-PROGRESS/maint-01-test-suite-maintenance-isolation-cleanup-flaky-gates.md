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
Fork run:
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
