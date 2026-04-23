# Events & Hooks Coverage Report (agent-core vs pi-mono baseline)

Date: 2026-04-22  
Baseline checked: `implementation/15-pi-mono-hooks-events-report.md`

This report answers: which pi-mono events/hooks are covered by this repository (`agent-core`), and which are not.

Research method:
- Launched 3 scouts for parallel recon.
- Manually verified key claims in `src/**`, `config/services.php`, and docs.

Legend:
- ✅ **Covered** = implemented + used on runtime path
- 🟡 **Partial** = equivalent exists but differs, is limited, or not fully wired
- ❌ **Not covered** = no equivalent found in runtime

---

## 1) Core lifecycle events (pi-mono `AgentEvent` parity)

| Baseline event | Status | agent-core status | Evidence |
|---|---:|---|---|
| `agent_start` | ❌ | Defined in contract only, no runtime emission found | `src/Domain/Event/CoreLifecycleEventType.php:20`; no producer in `src/Application/Orchestrator/*` |
| `agent_end` | ✅ | Emitted on cancel/complete paths | `src/Application/Orchestrator/AdvanceRunHandler.php:51`; `src/Application/Orchestrator/LlmStepResultHandler.php:87,221`; `src/Application/Orchestrator/ToolCallResultHandler.php:72` |
| `turn_start` | 🟡 | Contract defines it, runtime uses `turn_advanced` instead | `src/Domain/Event/CoreLifecycleEventType.php:21`; `src/Application/Orchestrator/AdvanceRunHandler.php:106` |
| `turn_end` | ❌ | Contract defines it, no runtime emission found | `src/Domain/Event/CoreLifecycleEventType.php:28` |
| `message_start` | 🟡 | Emitted for **tool messages** (not assistant streaming lifecycle) | `src/Application/Orchestrator/ToolCallResultHandler.php:159` |
| `message_update` | ❌ | Contract defines it; no runtime emission found | `src/Domain/Event/CoreLifecycleEventType.php:23`; coalescer branch exists in publisher `src/Infrastructure/Mercure/RunEventPublisher.php:55` |
| `message_end` | 🟡 | Emitted for **tool messages** | `src/Application/Orchestrator/ToolCallResultHandler.php:167` |
| `tool_execution_start` | ✅ | Emitted when tool effects are scheduled | `src/Application/Orchestrator/LlmStepResultHandler.php:259` |
| `tool_execution_update` | ❌ | Contract defines it; no runtime emission found | `src/Domain/Event/CoreLifecycleEventType.php:26` |
| `tool_execution_end` | ✅ | Emitted when tool result arrives | `src/Application/Orchestrator/ToolCallResultHandler.php:132` |

### Note
`CoreLifecycleEventType::validateOrder()` is present but not invoked on the runtime commit path.
- Defined: `src/Domain/Event/CoreLifecycleEventType.php:78`
- No production calls found under `src/**`.

---

## 2) Core loop hooks (pi-mono loop hook parity)

| Baseline hook | Status | agent-core equivalent | Evidence |
|---|---:|---|---|
| `transformContext` | ✅ | `TransformContextHookInterface`, invoked in Platform chain | Interface: `src/Contract/Hook/TransformContextHookInterface.php:9`; invoke: `src/Infrastructure/SymfonyAi/Platform.php:47,164-169` |
| `convertToLlm` | ✅ | `ConvertToLlmHookInterface`, invoked in Platform chain | Interface: `src/Contract/Hook/ConvertToLlmHookInterface.php:10`; invoke: `src/Infrastructure/SymfonyAi/Platform.php:49,180-185` |
| `getApiKey` | ❌ | No per-call API key hook found | no symbol/hook in `src/Contract/Hook` or `Platform` |
| `getSteeringMessages` | 🟡 | Interface exists, but not wired/invoked in runtime | `src/Contract/Hook/SteeringMessagesProviderInterface.php:9`; only doc mention in `docs/hooks.md:13` |
| `getFollowUpMessages` | 🟡 | Interface exists, but not wired/invoked in runtime | `src/Contract/Hook/FollowUpMessagesProviderInterface.php:9`; only doc mention in `docs/hooks.md:13` |
| `beforeToolCall` | 🟡 | No first-class hook; indirect interception via Symfony Toolbox events | `docs/hooks.md:15`; execution entry `src/Application/Handler/ToolExecutor.php:146-175` |
| `afterToolCall` | 🟡 | Same as above (Toolbox event model, not agent-core hook contract) | `docs/hooks.md:15`; `src/Application/Handler/ToolExecutor.php:146-175` |

---

## 3) Extension/lifecycle events from pi-mono (`pi.on(...)`) mapped to agent-core

| Baseline surface | Status | agent-core equivalent / gap | Evidence |
|---|---:|---|---|
| `resources_discover` | ❌ | No resource discovery extension API | no equivalent in `src/**` |
| `session_start` | 🟡 | `run_started` event exists (run-oriented, not session-oriented) | `src/Application/Orchestrator/StartRunHandler.php:53` |
| `session_before_switch` / `session_before_fork` | ❌ | No session switch/fork model | no equivalent in `src/**` |
| `session_before_compact` / `session_compact` | ❌ | No compaction lifecycle in runtime | no equivalent in `src/**` |
| `session_shutdown` | 🟡 | Closest runtime signal is `agent_end` | emitters listed above |
| `session_before_tree` / `session_tree` | ❌ | No tree navigation/session-graph hooks | no equivalent in `src/**` |
| `input` | ❌ | No input interception hook equivalent | no equivalent in `src/Contract/Hook` |
| `before_agent_start` | ❌ | No pre-start hook equivalent | no equivalent |
| `context` | ✅ | Covered by `TransformContextHookInterface` | `src/Contract/Hook/TransformContextHookInterface.php:9` |
| `before_provider_request` | ✅ | Covered by `BeforeProviderRequestHookInterface` | `src/Contract/Hook/BeforeProviderRequestHookInterface.php:9`; invoke in `Platform.php:67,199-210` |
| `model_select` | 🟡 | Model resolution exists (`ModelResolverInterface`) but no model-select event | `src/Contract/Tool/ModelResolverInterface.php:10`; use in `Platform.php:55` |
| `user_bash` | ❌ | No interactive shell hook/event layer | no equivalent in `src/**` |

---

## 4) Tool interception (`tool_call`, `tool_result`) and extra surfaces

| Baseline surface | Status | agent-core equivalent / gap | Evidence |
|---|---:|---|---|
| `tool_call` | 🟡 | Indirect via Symfony Toolbox events (documented), not native hook contract | `docs/tool-execution.md:16`; tool call path `src/Application/Handler/ToolExecutor.php:146-175` |
| `tool_result` | 🟡 | Same as above | `docs/tool-execution.md:16`; `src/Application/Handler/ToolExecutor.php:146-175` |
| `queue_update` | ❌ | No equivalent event stream | no equivalent |
| `compaction_start/end` | ❌ | No equivalent | no equivalent |
| `auto_retry_start/end` | ❌ | No explicit retry lifecycle events (only state flags/flows) | e.g. `retryableFailure` updates in handlers |
| extension event bus (`pi.events`) | ❌ | No cross-extension pub/sub API found | no equivalent |
| bash `spawnHook` | ❌ | No command spawn hook equivalent | no equivalent |

---

## 5) agent-core-specific hook/event surfaces (not in pi-mono baseline)

### Boundary hooks (`BoundaryHookName`)
Defined:
- `before_command_apply`
- `after_command_apply`
- `before_turn_dispatch`
- `after_turn_commit`
- `before_run_finalize`

Evidence: `src/Domain/Event/BoundaryHookName.php:9-23`

Runtime invocation coverage:
- ✅ `after_turn_commit` is dispatched from commit path: `src/Application/Orchestrator/RunCommit.php:125`
- ❌ others are defined but not invoked on runtime path (no usages found under `src/Application/**`).

### Additional runtime events in agent-core
Agent-core emits many non-pi baseline events, e.g.:
- `run_started` (`StartRunHandler.php:53`)
- `turn_advanced` (`AdvanceRunHandler.php:106`)
- `llm_step_completed/aborted/failed` (`LlmStepResultHandler.php:188,79,137`)
- `tool_call_result_received`, `tool_batch_committed`, `waiting_human` (`ToolCallResultHandler.php:124,178,189`)
- `agent_command_*` events (`ApplyCommandHandler.php`, `CommandMailboxPolicy.php`)

---

## Executive summary

Compared to `pi-mono` baseline:
- **Clearly covered**: provider-boundary hook chain (`transformContext`, `convertToLlm`, `before_provider_request`), and core tool execution boundaries (`tool_execution_start/end`) plus `agent_end`.
- **Partially covered**: turn/message lifecycle parity (contract exists, runtime emission differs), tool interception (via Symfony Toolbox events instead of first-class agent-core hooks), model selection (resolver exists but no event).
- **Not covered**: most session/UI extension events (`session_*`, `resources_discover`, `user_bash`, event bus, compaction/queue/auto-retry stream events), plus `getApiKey`, `message_update`, `tool_execution_update`, `agent_start`, `turn_end` runtime emission.
