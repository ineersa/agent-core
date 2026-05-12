# pi-mono Hooks & Events Inventory (source-mapped)

_Last verified from source on 2026-04-22._

Scope covered:
- `packages/agent` (core agent loop + `Agent` events/hooks)
- `packages/coding-agent` (extension hooks/events via `pi.on(...)`)
- supporting surfaces used by those hooks (`packages/ai`, event bus, bash spawn hook)

Source root analyzed: `/home/ineersa/claw/pi-mono`

---

## 1) Core Agent events (`packages/agent`)

Definition: `packages/agent/src/types.ts:326-341` (`AgentEvent`)

These are emitted by the low-level loop and consumed through `Agent.subscribe(...)`.

| Event | Defined | Emitted at | Payload | Can listeners modify behavior? | Downstream effects/emits |
|---|---|---|---|---|---|
| `agent_start` | `packages/agent/src/types.ts:328` | `packages/agent/src/agent-loop.ts:109, 138` | none | No return channel (observer only) | Forwarded to extension layer as `agent_start` via `packages/coding-agent/src/core/agent-session.ts:603-610` |
| `agent_end` | `packages/agent/src/types.ts:329` | Normal completion: `agent-loop.ts:196, 231`; failure path: `packages/agent/src/agent.ts:470` | `{ messages: AgentMessage[] }` | No | `Agent` clears streaming message (`agent.ts:526`), awaits listeners (`agent.ts:535`); forwarded to extensions (`agent-session.ts:610`) |
| `turn_start` | `packages/agent/src/types.ts:331` | `agent-loop.ts:110, 139, 174` | none | No | Forwarded as extension `turn_start` with added `{turnIndex,timestamp}` (`agent-session.ts:613-617`) |
| `turn_end` | `packages/agent/src/types.ts:332` | `agent-loop.ts:195, 214` | `{ message, toolResults }` | No | `Agent` may update `errorMessage` (`agent.ts:520`); forwarded to extension `turn_end` (`agent-session.ts:620-624`) |
| `message_start` | `packages/agent/src/types.ts:334` | Prompts/queued: `agent-loop.ts:112, 182`; assistant stream: `282, 314, 327`; tool result message: `628` | `{ message }` | No | `Agent` updates `streamingMessage` (`agent.ts:493`); forwarded (`agent-session.ts:629-632`) |
| `message_update` | `packages/agent/src/types.ts:336` | `agent-loop.ts:298` | `{ message, assistantMessageEvent }` | No | `Agent` updates `streamingMessage` (`agent.ts:497`); forwarded (`agent-session.ts:635-639`) |
| `message_end` | `packages/agent/src/types.ts:337` | Prompts/queued: `agent-loop.ts:113, 183`; assistant final: `316, 329`; tool result message: `629` | `{ message }` | No | `Agent` appends transcript (`agent.ts:501`); `AgentSession` persists entry (`agent-session.ts:512+`); forwarded (`agent-session.ts:642-645`) |
| `tool_execution_start` | `packages/agent/src/types.ts:339` | Sequential/parallel paths: `agent-loop.ts:362, 403` | `{ toolCallId, toolName, args }` | No | `Agent` adds pending tool call id (`agent.ts:506`); forwarded (`agent-session.ts:648-653`) |
| `tool_execution_update` | `packages/agent/src/types.ts:340` | `agent-loop.ts:540` | `{ toolCallId, toolName, args, partialResult }` | No | Forwarded (`agent-session.ts:656-662`) |
| `tool_execution_end` | `packages/agent/src/types.ts:341` | `agent-loop.ts:611` | `{ toolCallId, toolName, result, isError }` | No | `Agent` removes pending call id (`agent.ts:513`); tool result message emitted (`agent-loop.ts:628-629`); forwarded (`agent-session.ts:665-671`) |

Listener semantics: `Agent.subscribe(...)` listeners are awaited in order (`packages/agent/src/agent.ts:216, 535`).

---

## 2) Core Agent loop hooks (`packages/agent`)

Definitions: `packages/agent/src/types.ts` (`AgentLoopConfig`, hook signatures)

| Hook | Defined | Invoked at | Inputs | What can be modified | Downstream behavior |
|---|---|---|---|---|---|
| `transformContext` | `types.ts:147` | `agent-loop.ts:247-248` | `AgentMessage[]` | Return replacement message list | Replacement goes into `convertToLlm` path |
| `convertToLlm` | `types.ts:125` | `agent-loop.ts:252` | `AgentMessage[]` | Return provider-facing `Message[]` | Controls exact LLM-visible transcript |
| `getApiKey` | `types.ts:157` | `agent-loop.ts:265` | provider string | Return per-call key | Overrides static key for this request |
| `getSteeringMessages` | `types.ts:170` | `agent-loop.ts:165, 216` | none | Return injected mid-run messages | Injected before next assistant response |
| `getFollowUpMessages` | `types.ts:183` | `agent-loop.ts:220` | none | Return messages after normal stop point | Extends run with another turn |
| `beforeToolCall` | `types.ts:200` (context at `types.ts:69`) | `agent-loop.ts:491-501` | assistant message, tool call, validated args, context | Return `{ block: true, reason? }` to block | If blocked: immediate error tool result (`agent-loop.ts:501-504`), then normal end/result-message emission via `emitToolCallOutcome` (`370/411`, `604`, `611`, `628-629`) |
| `afterToolCall` | `types.ts:213` (context at `types.ts:81`) | `agent-loop.ts:573-584` | assistant message, tool call, args, result, isError, context | Override `content`, `details`, `isError` (field replace, no deep merge) | Final emitted tool result/event uses overridden values (`agent-loop.ts:594, 604, 611`) |

`Agent` wires these into loop config in `packages/agent/src/agent.ts:419-443`.

---

## 3) Extension event surface (`pi.on(...)`) in `packages/coding-agent`

Event type definitions: `packages/coding-agent/src/core/extensions/types.ts:427-931`  
Subscription overloads: `types.ts:991-1026`.

### 3.1 Session & resource lifecycle events

| Event (`pi.on`) | Defined | Emitted/invoked at | What handlers can modify | What it triggers downstream |
|---|---|---|---|---|
| `resources_discover` | `types.ts:427-433` | Runner method `runner.ts:837`; called from `agent-session.ts:2033` (inside `extendResourcesFromExtensions`) after session start/reload (`agent-session.ts:2023-2024, 2369`) | Return `{ skillPaths?, promptPaths?, themePaths? }` | All extension results aggregated (`runner.ts:861-867`), then `ResourceLoader.extendResources(...)` and base system prompt rebuilt (`agent-session.ts:2048-2050`) |
| `session_start` | `types.ts:445-451` | Emitted at bind: `agent-session.ts:2023`; reload: `agent-session.ts:2368`; reason source configured at `agent-session.ts:311` and runtime creation paths `agent-session-runtime.ts:143,171,209,227,245,281` | Notification only | Extensions receive startup/new/resume/fork/reload session context |
| `session_before_switch` | `types.ts:454-459`, result `types.ts:917-919` | Runtime preflight emit `agent-session-runtime.ts:83-98`; called by `switchSession/newSession/import` at `129,153,263` | Return `{ cancel: true }` | Runtime aborts switch and returns `{cancelled:true}` |
| `session_before_fork` | `types.ts:461-464`, result `types.ts:921-924` | Runtime preflight emit `agent-session-runtime.ts:100-111`; called by `fork` at `182` | Return `{ cancel: true }` | Runtime aborts fork and returns `{cancelled:true}` |
| `session_before_compact` | `types.ts:467-474`, result `types.ts:926-929` | Manual compact emit: `agent-session.ts:1615`; auto compact emit: `agent-session.ts:1872` | Return `{ cancel?: boolean, compaction?: CompactionResult }` | `cancel` aborts compaction; `compaction` bypasses default summarizer and is persisted as extension-sourced compaction |
| `session_compact` | `types.ts:476-481` | Emitted after append compaction: manual `agent-session.ts:1675`, auto `1946` | Notification only | Post-compaction notification with saved `compactionEntry` + `fromExtension` |
| `session_shutdown` | `types.ts:483-485` | Emitted on reload (`agent-session.ts:2352`) and runtime teardown/dispose via helper (`agent-session-runtime.ts:114,288`; helper in `runner.ts:160-171`) | Notification only | Extension cleanup hook |
| `session_before_tree` | `types.ts:503-507`, result `types.ts:931-945` | Emit in tree navigation at `agent-session.ts:2698` | Can `cancel`, supply `summary`, and override `customInstructions`, `replaceInstructions`, `label` | May short-circuit navigation, replace summary generation, or override summary metadata |
| `session_tree` | `types.ts:510-516` | Emitted after navigation/summary commit at `agent-session.ts:2812` | Notification only | Post-navigation notification with old/new leaf and optional summary entry |

### 3.2 Prompt/provider/input/model hooks

| Event (`pi.on`) | Defined | Emitted/invoked at | What handlers can modify | What it triggers downstream |
|---|---|---|---|---|
| `input` | `types.ts:660-676` | Emitted from prompt flow `agent-session.ts:946`; runner logic `runner.ts:886-905` | Return: `continue` / `transform` / `handled` | `handled` skips prompt processing (`agent-session.ts:951`); `transform` rewrites text/images before skill/template expansion (`agent-session.ts:954`) |
| `before_agent_start` | `types.ts:545-551`, result `types.ts:911-915` | Emitted before loop start at `agent-session.ts:1037`; runner combiner `runner.ts:780-834` | Can return custom `message`(s) and/or replacement `systemPrompt` | Custom messages are appended (`agent-session.ts:1043+`); system prompt overridden for turn or reset to base (`agent-session.ts:1056-1060`) |
| `context` | `types.ts:533-537`, result `types.ts:885-887` | Triggered via SDK transformContext bridge: `sdk.ts:318-321`; runner logic `runner.ts:714-743` | Return replacement `messages` | Modified messages become the model context before `convertToLlm` |
| `before_provider_request` | `types.ts:539-542` | Triggered via SDK payload hook: `sdk.ts:310-315`; runner logic `runner.ts:746-777` | Return replacement provider payload (`unknown`) | Final payload sent to provider is replaced/modified |
| `model_select` | `types.ts:630-636` | Emitted in `_emitModelSelect` (`agent-session.ts:1356-1368`), called from set/cycle paths `1390,1430,1455` | Notification only | Model-change notifications with `{model, previousModel, source}` |
| `user_bash` | `types.ts:642-651`, result `types.ts:898-903` | Emitted in interactive bash command handler `interactive-mode.ts:4659`; runner logic `runner.ts:685-711` | Return `{ operations? }` or full replacement `{ result? }` | If `result` is returned, built-in execution is bypassed and provided result is used; otherwise custom operations are used for normal execution |

### 3.3 Agent lifecycle mirror events (extension side)

These are extension-visible mirrors of core `AgentEvent`s.

| Event (`pi.on`) | Defined | Bridged from core at | Original core emit sites | Mutability |
|---|---|---|---|---|
| `agent_start` | `types.ts:553-556` | `agent-session.ts:608` | `packages/agent/src/agent-loop.ts:109,138` | Notification only |
| `agent_end` | `types.ts:558-562` | `agent-session.ts:610` | `agent-loop.ts:196,231` (+ failure path `packages/agent/src/agent.ts:470`) | Notification only |
| `turn_start` | `types.ts:564-569` | `agent-session.ts:613-617` | `agent-loop.ts:110,139,174` | Notification only |
| `turn_end` | `types.ts:571-577` | `agent-session.ts:620-624` | `agent-loop.ts:195,214` | Notification only |
| `message_start` | `types.ts:579-583` | `agent-session.ts:629-632` | `agent-loop.ts:112,182,282,314,327,628` | Notification only |
| `message_update` | `types.ts:585-590` | `agent-session.ts:635-639` | `agent-loop.ts:298` | Notification only |
| `message_end` | `types.ts:592-596` | `agent-session.ts:642-645` | `agent-loop.ts:113,183,316,329,629` | Notification only |
| `tool_execution_start` | `types.ts:598-604` | `agent-session.ts:648-653` | `agent-loop.ts:362,403` | Notification only |
| `tool_execution_update` | `types.ts:606-613` | `agent-session.ts:656-662` | `agent-loop.ts:540` | Notification only |
| `tool_execution_end` | `types.ts:615-621` | `agent-session.ts:665-671` | `agent-loop.ts:611` | Notification only |

---

## 4) Tool interception hooks (extension layer)

| Hook/Event | Defined | Invoke path | What can be modified | What it emits/triggers |
|---|---|---|---|---|
| `tool_call` | Event union `types.ts:731-739`; contract notes `types.ts:726-729`; result `types.ts:891-895` | Installed on core agent `agent-session.ts:363-383`; runner handling `runner.ts:662-682`; called from core pre-exec hook path (`packages/agent/src/agent-loop.ts:491`) | Mutate `event.input` **in place**; can return `{ block: true, reason? }` | Blocked call becomes immediate error result (`agent-loop.ts:501-504`) and still follows normal tool outcome emission (`370/411`, `604`, `611`, `628-629`). If handler throws, `agent-session` converts that to a blocking error (`agent-session.ts:379-383`). |
| `tool_result` | Event union `types.ts:790-798`; result `types.ts:905-909` | Installed on core agent `agent-session.ts:387-409`; runner handling `runner.ts:612-659`; called from post-exec hook path (`packages/agent/src/agent-loop.ts:573`) | Runner can chain overrides for `content/details/isError` (`runner.ts:626-634`) | Final tool result is emitted after `afterToolCall` stage (`agent-loop.ts:594,604,611`). **Current bridge only applies `content/details`, and skips overrides on error results** (`agent-session.ts:399,403,408-409`). |

---

## 5) Additional non-`pi.on` hooks/events in this area

| Surface | Defined | Triggered at | What can be modified | Notes |
|---|---|---|---|---|
| `AgentSessionEvent` stream (`subscribe`) | `packages/coding-agent/src/core/agent-session.ts:112-129`; subscribe at `680` | Emitted via `_emit` (`419`) | Observer only | Includes extra events beyond core agent: `queue_update`, `compaction_start/end`, `auto_retry_start/end` |
| `queue_update` | `agent-session.ts:115` | `_emitQueueUpdate()` (`425`) called when queued messages are consumed/enqueued (`495,501,1176,1193,1320`) | No | Exposes current steering/follow-up queue snapshots |
| `compaction_start/end` | `agent-session.ts:119-127` | Manual: `1588/1688+`; Auto: `1825/1958+` | No | Internal session notifications for UI/runtime observers |
| `auto_retry_start/end` | `agent-session.ts:128-129` | Emitted around retry loop (`2432`, `2419`, `2455`) | No | Internal retry telemetry |
| Extension event bus (`pi.events`) | Bus interface `packages/coding-agent/src/core/event-bus.ts:3-5`; API exposure `packages/coding-agent/src/core/extensions/types.ts:1210` | `createEventBus()` impl `event-bus.ts:12+` | Any payload on named channels | Cross-extension pub/sub channel, separate from lifecycle hooks |
| Bash spawn hook (`spawnHook`) | `packages/coding-agent/src/core/tools/bash.ts:130-149` | Applied in bash tool execute path via `resolveSpawnContext(...)` at `bash.ts:283` | Can replace `{ command, cwd, env }` before spawn | Host-level hook for command/env rewriting prior to execution |

---

## 6) Behavior caveats (important)

1. `tool_call` argument mutation happens **after validation** and is **not re-validated**.
   - Contract note: `packages/coding-agent/src/core/extensions/types.ts:729`
   - Core validation occurs before hook: `packages/agent/src/agent-loop.ts:490-492`

2. `tool_result.isError` is part of extension result contract (`types.ts:905-909`), and runner supports it (`runner.ts:634`), but current `AgentSession` bridge does not propagate `isError` override back to core tool result object (`agent-session.ts:403, 408-409`).

3. `SessionBeforeForkResult` includes `skipConversationRestore` in types (`types.ts:923`), but this field is not consumed in the runtime paths inspected (`packages/coding-agent/src/core/agent-session-runtime.ts`).

4. `ModelSelectSource` includes `"restore"` (`types.ts:627-636`), while concrete emit calls found here use `"set"` and `"cycle"` (`agent-session.ts:1390,1430,1455`).
