# Test Suite Rationalization — Inventory

Generated: 2026-06-02
Branch: task/test-suite-rationalization-application-tests
Baseline: 134 `*Test.php` files

---

## DELETED — Zero-value / visual-only / faux-integration tests

| File | Lines | Rationale |
|------|-------|-----------|
| `tests/AgentCore/Application/Tool/ToolContextTest.php` | 39 | Pure DTO getter test. Type system guarantees correctness. |
| `tests/AgentCore/Contract/Tool/ToolCallExceptionTest.php` | 67 | Exception constructor variant coverage. Static analysis + PHP guarantee this. |
| `tests/AgentCore/Contract/HookParityContractTest.php` | 124 | Mock hook chain ordering. Real call order exercised by pipeline tests + E2E. |
| `tests/AgentCore/Application/Pipeline/RunOrchestratorTopologyTest.php` | 498 | Hand-built RunOrchestrator with sys_get_temp_dir. No value over handler tests + controller E2E. |
| `tests/AgentCore/Application/Pipeline/RunOrchestratorSoakFailureDrillTest.php` | 328 | 1000 synthetic runs with hand-built fixture. No unique assertion not covered by handler tests. |
| `tests/AgentCore/Application/Pipeline/RunOrchestratorObservabilityTest.php` | 160 | Metrics/tracing recording. Thin, no unique coverage. |
| `tests/AgentCore/Application/Pipeline/RunOrchestratorStructuredLoggingTest.php` | 123 | Structured logging recording. Thin, no unique coverage. |
| `tests/CodingAgent/ExtensionApi/ExtensionApiContractsTest.php` | 358 | DTO readonly/constructor/final assertions + stub interface wiring. Type system guarantees it. |
| `tests/CodingAgent/Runtime/InProcess/AgentsContextInjectionTest.php` | 205 | Fake AgentRunner/EventStore. Injection orchestration verified by system-prompt renderer tests + controller E2E. |
| `tests/CodingAgent/Runtime/InProcess/SkillsContextInjectionTest.php` | 264 | Same pattern as AgentsContextInjectionTest. Behavior covered by SkillsContextBuilderTest + SkillContextRendererTest + E2E. |
| `tests/Tui/Transcript/PendingMessagesWidgetTest.php` | 46 | Visual widget — render output contains expected text. TUI E2E covers layout. |
| `tests/Tui/Editor/PromptEditorWidgetTest.php` | 48 | Visual widget — placeholder/prompt rendering. TUI E2E covers. |
| `tests/Tui/Header/HeaderWidgetTest.php` | 44 | Visual widget — logo box-drawing chars. Branding assertion. |
| `tests/Tui/Status/StatusWidgetTest.php` | 73 | Visual widget — idle/message/hidden. Thin state management. |
| `tests/Tui/Transcript/TranscriptWidgetTest.php` | 77 | Visual widget — welcome/prefix/role rendering. TUI E2E covers. |
| **Total deleted** | **15 files, ~2,454 lines** | |

## KEPT — Has real logic / application coverage value

| File | Lines | Value |
|------|-------|-------|
| `FooterBarWidgetTest.php` | 116 | Priority ordering, width truncation, provider pattern logic |
| `FooterStateSegmentProviderTest.php` | 121 | Thinking level → ThemeColorEnum mapping logic |
| `TranscriptBlockTest.php` | 358 | Includes serializer round-trip deserialization edge cases |
| `ToolRegistryTest.php` | 360 | Registration, guidelines, tool lines, mode resolution logic |
| `RuntimeEventTypeTest.php` | 278 | Enum completeness against .pi/plans spec |
| `OutputCapTest.php` | 400 | Capping, doc detection, cleanup, persistence — real service logic |
| `CommandMailboxPolicyTest.php` | 348 | Left as-is (task: "if ambiguous, leave and document TODO") |
| `WorkerFailedEventSubscriberTest.php` | 215 | Already well-structured mock/unit test |
| All E2E tests | ~5 files | Controller, LLM-real, TUI smoke — highest value |
| All remaining files | ~100 files | Architecture, domain, config, tool, session, listener, question tests |

## Left as TODO (needs human decision)

1. **CommandMailboxPolicyTest** — 348 lines, uses RunOrchestrator fixture pattern. The CommandMailboxPolicy logic could be tested more narrowly through a dedicated pure unit test (>200 lines of fixture overhead for a few policy assertions). But the rewrite cost is high and risk of losing unique edge-case coverage (drain mode superseding, mailbox ordering). Leave for a focused follow-up.

2. **WorkerFailedEventSubscriberTest** — 215 lines, already well-structured with mock run-store/event-store. Not large enough to justify rewrite.

3. **TuiStartupSnapshotTest** — Already has both exact golden snapshot AND presence/polling assertions (`testStartupContainsExpectedElements`). No change needed.

4. **TranscriptBlockTest** — 358 lines with ~50% noise (exhaustive kind enumeration, every field combination), but includes valid serializer round-trip edge cases. Could consolidate but not urgent.

## Post-cleanup totals

- Files deleted: 15
- Estimated lines removed: ~2,454
- Remaining test files: 119
- Coverage preserved: All E2E (controller, LLM-real, TUI), all handler-level pipeline tests, all config/tool/session/runtime unit tests, all TUI non-visual logic tests
