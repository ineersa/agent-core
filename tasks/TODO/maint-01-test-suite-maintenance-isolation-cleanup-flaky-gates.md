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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-06-09T21:53:09.939Z
