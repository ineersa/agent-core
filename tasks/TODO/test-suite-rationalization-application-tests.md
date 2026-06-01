# Test suite rationalization and application-test cleanup

## Goal
Context from parallel scout audit (2026-05-31): current unit/integration test suite is oversized, brittle, and often hand-wires fake application graphs instead of testing either pure rules or the real Symfony/container application. User preference: favor full application tests with container/E2E for service/runtime behavior, and keep unit tests only for specific pure rules, validation, mapping, parsing, algorithms, and edge cases that do not touch container/actual services.

Scout report artifacts: /home/ineersa/.pi/agent/tmp/2026-05--7efff596.txt (full capped report) plus derived capped excerpts /home/ineersa/.pi/agent/tmp/2026-05--a26e8bf6.txt and /home/ineersa/.pi/agent/tmp/2026-05--e26bf21b.txt.

High-level findings:
- tests/AgentCore contains several faux-integration orchestrator tests with duplicated 80+ line fixtures and dead temp dirs. Several tests assert DTO getters/constructors or manually reimplement contracts.
- tests/CodingAgent has broad manual bootstrap anti-patterns: tmp dirs/chdir in many tests, hand-built serializers/session stores/model services, fake AgentRunner/EventStore tests that do not exercise real runtime behavior, and tests that mirror DTO/enum definitions.
- tests/Tui has high-value E2E smoke tests, but many widget/rendering unit tests assert visual implementation details better covered by TUI E2E/presence assertions. Snapshot golden test is brittle.
- Messenger tests/config need Symfony-native test treatment per Symfony 8.1 Messenger docs "In Memory Transport": override named transports in `when@test` with `in-memory://`; assert dispatched envelopes via `messenger.transport.<name>` (`InMemoryTransport::getSent()`); rely on KernelTestCase/WebTestCase automatic reset between tests; use the `serialize` option only when a test intentionally validates serialization behavior. Do this instead of `.env.test` transport DSN overrides. Keep controller/E2E validation explicitly exercising the real async transport path.

Principles for cleanup:
1. Delete tests that only assert type-system guarantees, DTO fields, enum counts, constructor passthrough, theme colors, or branding text.
2. Rewrite service/runtime/store tests to use Symfony KernelTestCase + isolated test cwd/.hatfield DB under var/tests or var/tmp, not manual container/Doctrine/serializer bootstraps.
2a. Messenger unit/kernel tests must use Symfony's supported in-memory transport configuration under `when@test` or `config/packages/test/messenger.yaml`; do not set messenger transport DSNs through `.env.test` just to make tests pass. Symfony's intended split is: test handlers as plain PHP classes only when testing handler business logic, and use in-memory transports in functional/kernel tests to verify that dispatch/routing sends the expected message to the expected named transport.
3. Keep pure unit tests for algorithms/rules/parsers/mappers/validation where they do not touch Symfony container or real services.
4. Keep and strengthen product-level E2E tests: controller, real LLM, TUI smoke. These are the tests that catch actual regressions.
5. All QA through Castor. Before deleting a test, either show the behavior is covered by a kept application/E2E test, or add/adjust a high-value test first.

## Acceptance criteria
- Inventory current tests and classify each as KEEP, DELETE, REWRITE-AS-UNIT, or REWRITE-AS-APPLICATION using the scout reports as starting evidence; commit the inventory as docs or task notes before mass deletion.
- Delete clear zero-value tests: tests/AgentCore/Application/Tool/ToolContextTest.php, tests/AgentCore/Contract/Tool/ToolCallExceptionTest.php, tests/AgentCore/Contract/HookParityContractTest.php, tests/CodingAgent/ExtensionApi/ExtensionApiContractsTest.php, OutputCapTest::testPersistThrowsOnWriteFailure, and trivial TUI visual/widget tests identified by scouts unless a concrete regression value is documented.
- Remove or fold faux-integration AgentCore pipeline tests: RunOrchestratorTopologyTest.php, RunOrchestratorSoakFailureDrillTest.php, RunOrchestratorStructuredLoggingTest.php, RunOrchestratorObservabilityTest.php; preserve only unique behavior by moving it into handler-level pure tests or confirming controller E2E covers it.
- Rewrite CommandMailboxPolicyTest and WorkerFailedEventSubscriberTest to use focused in-memory doubles/handler-level tests instead of duplicated orchestrator fixtures and PHPUnit mock expectation chains.
- Fix AgentCore architecture test violations: move/rename tests importing CodingAgent config (LlamaCppSmokeTest.php, TraceReplayTest.php, PlatformIntegrationTest.php) into the correct CodingAgent/application test area or extract a Core-only support layer; castor deptrac must remain clean.
- For CodingAgent session/store/runtime tests, replace manual serializer/session/model/service bootstraps and chdir hacks with shared Symfony application test infrastructure: KernelTestCase, isolated cwd with .hatfield under var/tests or var/tmp, real container services, migrations/schema via Symfony/Doctrine, and cleanup helpers.
- Fix Messenger test configuration the Symfony way: add `when@test`/`config/packages/test/messenger.yaml` in-memory transports for `run_control`, `llm`, and `tool` where kernel/unit tests should not hit Doctrine queues. Suggested shape based on Symfony docs:
  ```yaml
  # config/packages/test/messenger.yaml or config/packages/messenger.yaml under when@test
  framework:
      messenger:
          transports:
              run_control:
                  dsn: 'in-memory://'
                  serializer: 'messenger.transport.native_php_serializer' # keep only if test needs StartRun native serialization coverage
              llm: 'in-memory://'
              tool: 'in-memory://'
  ```
  If serialization round-trip is specifically under test, use the documented in-memory `serialize: true` option on that transport/test config; otherwise use default non-serializing in-memory transport for dispatch/routing assertions. Remove any `.env.test`-style transport DSN overrides if they exist or are introduced. Tests should assert routing/dispatch through the real Messenger bus by fetching `messenger.transport.run_control`, `messenger.transport.llm`, or `messenger.transport.tool` as `Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport` and checking `getSent()` envelopes/messages, rather than mocking buses or creating Doctrine queues manually.
- Preserve product-level async coverage: controller E2E / `castor test:controller` / `castor check` must still exercise the real controller + consumer + Doctrine transport path (or an explicitly documented E2E test environment override), so in-memory `when@test` config must not silently turn the controller smoke tests into synchronous/fake Messenger tests.
- Rewrite or remove AgentsContextInjectionTest and SkillsContextInjectionTest fake AgentRunner/EventStore tests; verify the same behavior through controller/application tests or a small pure unit if there is isolated transformation logic.
- Consolidate low-value DTO/enum plumbing tests: reduce TranscriptBlockTest, RuntimeEventTypeTest, ToolRegistryTest to only meaningful invariants/edge cases; remove hardcoded enum counts and exhaustive lists unless they are a documented public compatibility surface.
- TUI: keep ControllerSmokeTest, ViewImageToolE2eTest, WriteFileToolE2eTest, TuiAgentSmokeTest, command/parser/editor/question/theme pure logic tests; rewrite TuiStartupSnapshotTest from exact golden snapshot to robust presence/polling assertions; delete redundant visual widget tests (PendingMessagesWidgetTest, PromptEditorWidgetTest, HeaderWidgetTest, FooterBarWidgetTest, StatusWidgetTest, TranscriptWidgetTest, FooterStateSegmentProviderTest) unless unique behavior is documented.
- Standardize temporary paths and cleanup across remaining tests: use project var/tmp or var/tests helpers for application tests; sys_get_temp_dir is allowed only for actual OS temp/process needs and must be cleaned; eliminate duplicated rmDir/removeDir implementations.
- Remove shell/platform brittleness where possible: avoid test shell_exec('diff -u') unless explicitly justified; replace fixed usleep waits with polling helpers; move hardcoded llama_cpp base URL/IP to env/config fallback.
- Validation: castor cache:clear, castor test, castor deptrac, castor phpstan, castor cs-check must pass. For deleted/replaced E2E-relevant tests, run castor test:controller and, when prerequisites exist, castor check. If tmux or llama.cpp:9052 is unavailable, record exact blocker and keep task IN-PROGRESS.
- Outcome target: reduce test LOC substantially (initial scout estimate: 3k+ removable/rewrite lines), while increasing confidence by relying on application/E2E tests for real service/runtime behavior and small pure unit tests for real logic.

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
- Created: 2026-06-01T01:43:14.260Z

## Task workflow update - 2026-06-01T01:52:15.118Z
- Added Messenger-specific cleanup requirements per user feedback: use Symfony-native `when@test` / `config/packages/test/messenger.yaml` in-memory transports for kernel/unit Messenger tests; do not rely on `.env.test` transport DSN overrides; tests should assert through real Messenger bus/InMemoryTransport services instead of bus mocks/manual Doctrine queues; preserve controller/E2E coverage of real async Doctrine transport path so product smoke tests are not accidentally faked.

## Task workflow update - 2026-06-01T01:53:37.674Z
- Checked Symfony 8.1 Messenger docs section 'In Memory Transport' (symfony.com/doc/current/messenger.html#in-memory-transport) and expanded the Messenger acceptance criteria with exact behavior: override named transports with `in-memory://` under `when@test`; assert using `messenger.transport.<name>` as `InMemoryTransport::getSent()`; KernelTestCase/WebTestCase reset in-memory transports automatically; use `serialize: true` only when intentionally testing serialization; split plain handler tests from functional routing/dispatch tests.
