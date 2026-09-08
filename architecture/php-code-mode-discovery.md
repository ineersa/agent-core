# PHP code-mode discovery

Discovery for `2026-09-08-discover-a-php-code-mode-tool-for-programmatic-tool-calls`, dated 2026-09-08. Source baseline is `0175446eca68f3db6aa6f3e07f9d3123524c0d19`. This report proposes behavior for approval. It does not define a supported API or implement code mode.

## Recommendation

Use one PHP script subprocess with one synchronous `tool(name, arguments)` bridge. Keep dispatch, credentials, hooks, and artifact ownership in Hatfield, outside the script process. Run nested calls serially in the current session. Return only the script's selected final value and a bounded execution summary to the model.

Do not implement this as `eval()` in a worker, a direct registry-handler call, or a wrapper around bash. A separate PHP process alone is not sufficient isolation. The script must have no direct access to the checkout, credentials, network, application autoloader, or host processes. Existing SafeGuard hooks inspect tool calls, not arbitrary PHP instructions.

The reusable execution foundation is `ToolExecutor` with `RegistryBackedToolbox`, not individual handlers. It needs a narrow host-side adapter for nested identity, capability checks, typed outcomes, and non-model result delivery. Today neither class provides that complete contract. In particular, MCP loses structured values, approval continuation can repeat script side effects, and normal result projection adds results to model history. [S1–S6]

Recommend fail-closed v1 behavior for nested calls that need human input: stop before executing the protected handler and report `approval_required`. Do not resume the script automatically. Supporting interactive approval while preserving a PHP stack is a separate design choice, not a small reuse of current continuation. This restriction needs product approval because it reduces composition of otherwise available tools.

## Existing execution and result path

Built-ins enter `ToolRegistry` through tagged `HatfieldToolProviderInterface` providers. Definitions contain the schema, handler, execution mode, and timeout metadata. Extension registration wraps public handlers with `ExtensionToolHandlerAdapter`, retains extension ownership, and registers them in the same registry. Contextual extension handlers receive run identity, cancellation, and timeout through the public API. [S1, S7]

`RegistryBackedToolbox` applies argument rewrite hooks, re-reads the definition, then invokes the native Symfony AI toolbox. Native argument resolution and Validator handle typed DTO tools. Raw dynamic tools receive their argument map, so a declared JSON Schema does not imply equivalent host-side validation for every extension or MCP handler. Reuse this path rather than inventing DTO resolution. The proposed bridge must reject non-map arguments and must not claim stronger validation than the selected tool actually has. [S2]

The normal async path sends `ExecuteToolCall` to the tool worker. Middleware routes MCP calls to the MCP transport, and subagent launch/resume calls to the agent transport. The worker adds execution context and calls `ToolExecutor`. The executor checks stored outcomes, cancellation, and the active tool allowlist, then pushes a `ToolContext` around toolbox execution. Crucially, its allowlist check is skipped when `tools_ref` is missing. A nested adapter must require the current run and active tool reference, not rely on this permissive absence behavior. [S3, S8]

MCP discovery creates runtime tool definitions and session catalog entries. Parent availability filtering hides `availability: specific` servers. Code mode must use the current parent's resolved set, not connect to hidden servers or import child selectors. `McpToolInvoker` delegates through the connection manager and maps the result. The SDK adapter currently retains `content` and `isError` but explicitly omits `structuredContent` and metadata. `McpResultMapper` joins text and replaces image, audio, and resource content with diagnostic placeholders. This is a real information-loss boundary, not a TOON decoding problem. [S4, S9]

Symfony tool results initially contain native PHP values. `ToolExecutor::toDomainResult()` stores that value in `details.raw_result`, also renders text, and then runs result processors. Output capping can remove `raw_result`, persist the rendered text, and replace the visible content. Several built-ins already return TOON strings, including settings and background-process listing. Code mode cannot recover native structure universally by reading `raw_result` after execution. [S3, S5, S10]

Finally, `ToolCallResultHandler` commits tool-end events and appends model tool messages. Sending every nested call through the ordinary top-level result handler would defeat the task's context-saving goal. Nested execution needs an internal result destination correlated to the outer call, while only the outer result follows normal model projection. Do not fabricate extra model tool messages without matching assistant tool calls. [S6]

Preserve the MCP consumer's connection ownership. Calling its invoker directly from a script-owning tool worker would bypass Messenger routing and could create another connection through reconnect. Recommend internal nested request/result correlation through the existing execution bus, with MCP requests still handled by the MCP consumer. The script supervisor must not occupy the only worker that can execute its pending built-in request. Controller-owned supervision or an explicit worker handoff must resolve this dependency before implementation. Do not add a second MCP client or increase worker counts to hide a deadlock. The routing middleware and worker currently understand top-level calls only, so this is a required internal change, not an existing bridge. [S8]

## Proposed script and value contract

All names and shapes below are recommendations, not approved public API.

Use one required script-source string as input. Do not add file-path execution, dependency installation, cross-session lookup, a scheduling language, or saved workflow definitions. Tool schemas remain the existing discovery source. A second tool-discovery API is unnecessary for v1.

Execute the source as a PHP body with normal local variables and one final `return`. Provide only `tool(string $name, array $arguments)` as the host bridge. No container, registry, callback objects, or cancellation-token object enters PHP user code. Scripts cannot choose run IDs, tool-call IDs, internal context, or approval answers.

A successful bridge call returns a small value envelope with a result kind and value. Distinguish a structured null from a missing structured value. Preserve arrays, objects-as-data, booleans, numbers, and strings without converting them to model-facing text first. Normalize supported DTOs using the existing Symfony Serializer. Reject unsupported objects, resources, recursive values, non-finite numbers, and invalid text encodings with a bounded diagnostic. Never transfer executable PHP objects or use PHP `unserialize()` on script data.

Process isolation requires serialization. Use a bounded framed IPC protocol with JSON-compatible values and explicit result kinds. One transport encoding is justified. A JSON-to-TOON-to-JSON presentation round trip is not. Preserve list versus object identity and document integer range behavior when implementing the wire codec. Frame lengths, value depth, and byte limits must be enforced before allocation where possible.

Result cases have different behavior:

- Native structured results use the value path before model formatting and output capping. Result hooks and terminal-state classification still run. Do not expose pre-policy data merely to avoid serialization.
- MCP structured results require extending the internal SDK adapter and mapper path to retain `structuredContent`. Keep content blocks separately. Do not infer structure from the first text block when the server supplies structured content.
- Text-only results stay strings. A script may explicitly decode JSON or TOON when it knows the format. No heuristic auto-decoding. A bundled pure TOON decoder would require a decision about the supported script library set. Do not expose the application's entire Composer autoloader for it.
- Binary results return bounded metadata and an inspection reference, not base64 in model context or PHP object handles. Preserve the bytes only in owned artifact storage if retention is approved. Existing MCP placeholders cannot provide this by themselves.
- Oversized intermediate values return an explicit omitted-result reference, never truncated data represented as complete. The script may use an allowed existing read tool for bounded text inspection. Do not silently load a huge artifact back into worker memory. Multi-page or streaming binary processing is not part of this proposal.
- Failure, denial, cancellation, human-input suspension, and deferred completion are control outcomes, not successful application data. Some existing hook blocks are arrays or TOON strings with `denied`, rather than `isError=true`. Normalize these at the owning hook/outcome boundary instead of guessing from arbitrary tool text. [S3–S5, S11]

The final returned value follows the same safe value types. Capture stdout and stderr as bounded diagnostics, not as another implicit final output. The model sees the final value, outer status, completed-call count, failing or uncertain call reference, and an inspection reference. It does not see nested raw arguments, outputs, or stdout by default. Apply the existing output cap to final presentation; enforce a separate hard aggregate storage budget so capping cannot cause unlimited disk growth.

For example, a script can call two already-available MCP data tools, filter rows, sum amounts, and return a CSV string. This requires no new database or export tool. Use fixed existing runtime names and arguments in a future fixture. The only model-visible data would be the CSV and execution summary, not the source rows.

## Isolation and permission boundary

Recommend an OS-enforced sandbox around an owned PHP subprocess, launched with Symfony Process. Mount only the runtime and bridge bootstrap read-only, plus a private bounded scratch directory. Clear inherited environment and descriptors except the bridge channels. Do not mount the checkout, home, session database, credentials, or application vendor tree. Deny outbound networking, host IPC, process creation after startup, tracing, and access to host process information. Constrain CPU, memory, wall time, file size, descriptor count, process count, and total artifact bytes.

PHP settings such as `disable_functions`, `open_basedir`, and `memory_limit` can add restrictions but are not the security boundary. Dynamic extensions, FFI, stream wrappers, includes, and executable-loading paths need explicit containment. Selecting and proving the platform sandbox is implementation work that remains blocked on the platform decision. No sandbox means code mode is unavailable, not an unsandboxed fallback.

The repository has an optional `pi-bwrap` wrapper for developer Castor launches. It depends on an external executable and can be skipped. It is not evidence of a packaged, mandatory per-script sandbox. `ExtensionExecBridge` reuses Symfony Process and cooperative cancellation, but it does not establish the required sandbox or descendant containment. Reuse these facilities where their contracts apply, not their names as proof of isolation. [S12]

The host broker computes the permitted tool set from the current parent execution context. Check each request against that set, current visibility, and exclusions. Recheck after rewrite hooks and before the handler, since registry definitions can change. A snapshot must not preserve a capability that has since been revoked. Run every applicable call hook, including SafeGuard, under host-owned correlation. Keep extension code trusted host code; do not grant the script extension API objects.

Exclude `subagent`, `fork`, and `agent_resume`, recursive code mode, and any alternative child launch or continuation entry. Reject human-input and deferred-agent outcomes before forwarding them to generic completion handling. Name filtering alone is insufficient: extensions can use `agent()` or dispatch extension agent jobs, and bash can invoke the application CLI. The existing child-run tool filters and `RunRelationshipReader` nested-launch guard do not restrict these operations on a normal parent run. [S13]

The exclusion therefore requires execution-purpose context enforced at host child launch and continuation boundaries, including extension jobs. Unreviewed tool handlers or arbitrary shell wrappers that can bypass that boundary must be unavailable through code mode. Remote MCP servers remain trusted external services; Hatfield cannot prove that an arbitrary server never starts an agent internally. Approval must settle whether exclusion means Hatfield child orchestration or all remote computation. Do not promise the latter while allowing arbitrary MCP servers.

## Approval, cancellation, and partial effects

Current approval handling produces `ToolExecutionHumanInputSuspension`. The worker returns, run control records the pending question, and an answer requeues the exact tool call with typed correlation. This is appropriate when the handler has not started. Requeuing an outer PHP script after nested approval can repeat every earlier write. [S8, S11, S14]

For recommended v1, propagate each nested call through normal policy, but translate an approval requirement into a terminal code-mode stop before that handler runs. Preserve the reason and call reference. Do not synthesize approval, copy an outer approval onto later calls, or impersonate a noninteractive child. Do not publish a resumable outer approval request. Tell the caller which earlier calls completed and that a new invocation is a new execution. Reject explicit `ask_human` before dispatch as well.

If interactive nested approval is required, the alternative is an owned live script process paused on the exact nested response, with host approval correlation and a deadline. A crash still terminates the script; it must not restart from the beginning. This adds controller ownership and waiting-state work. Persisting arbitrary PHP locals or replaying earlier calls is a workflow engine and is outside scope.

The outer supervisor owns the script, pipes, scratch directory, and pending broker requests. Cancellation closes admission to further calls, cancels the owned nested request where supported, stops the script, and reaps owned processes. Teardown must not signal unrelated workers, root-owned workers, or active-session workers. No parallel nested calls, background script execution, or detached child processes in v1.

MCP cancellation is presently a blocker for a strong end-to-end guarantee. `McpConnectionManager` and `McpToolInvoker` explicitly do not propagate per-call deadlines or cancellation to the SDK. Killing the script or stopping the wait does not prove remote work stopped. Coordinate with tracked task `add-proper-mcp-tool-call-cancellation`; either require that work or approve an explicit uncertain-remote-outcome contract. Never kill shared MCP workers as a substitute. [S4]

A script failure after two successful calls reports those two completed calls, the failing call, and that subsequent calls were not dispatched. A handler may partially succeed internally, so retain its operation/log references rather than claim every failed call caused no effects. Transport loss after dispatch means outcome unknown. Distinguish this from confirmed failure or cancellation. This follows the related tool-failure task and the executor's bounded actionable error translation. [S2, S3, S15]

Recommend stopping at the first failed nested call. The host closes admission and terminates the script, even if PHP catches a bridge exception. Otherwise a caught denial could silently become outer success. No final value can override the host's failure or cancellation status. Tools that intentionally return ordinary application data remain successful; classify control outcomes at their owning boundary, not by scanning strings for words such as `error`.

No automatic retries, rollback, script resume, or exactly-once claim. Existing result-store reuse prevents some repeated execution after a recorded outcome, not concurrent execution or repeats after a crash before recording. Reserve outer execution identity before side effects and fail closed on duplicate or ambiguous redelivery. Persist only enough attempt/outcome evidence to prevent automatic re-entry and permit inspection, not a resumable program counter. [S3, S15]

## Inspection and sensitive data

Use the outer tool-call ID plus a host-generated nested call ID and monotonic ordinal. Preserve run/session ID, tool identity, start/terminal status, duration, and any handler-provided operation reference. Logs carry those identifiers and bounded sanitized causes. Do not put script source, raw arguments, results, credentials, or stack dumps in runtime logs. Existing redaction is pattern-based, not a guarantee that arbitrary data is non-sensitive. [S2, S4, S16]

Keep the detailed call ledger and permitted payloads in owned local artifacts. Only compact references belong in outer canonical history. Existing `OutputCap` already provides run-hashed temporary text storage, locking, path checks, and cleanup; its 24-hour stale cleanup means these references are not durable session history. It also persists rendered text only and does not supply a bounded structured call ledger. Reuse storage and read facilities, but explicitly implement the missing structured/binary retention contract rather than using subagent artifacts as a general-purpose store. [S5]

Inspection should use existing bounded `read` for the ledger and text artifacts, and `view_image` for supported images under ordinary permissions. Expired or quota-omitted data must say so. The script gets no raw host artifact path mounted into its sandbox. Approval must choose retention and payload privacy: retaining arbitrary outputs locally can retain secrets, even when model history and logs do not contain them. Recommend private owner-only artifacts, short retention, and metadata-only records when payload retention is prohibited.

## Alternatives and decisions for approval

In-process PHP preserves values without IPC but exposes application state and host permissions. Reject it. A separate unsandboxed PHP process limits accidental state corruption but still bypasses SafeGuard. Reject it too. A PHP subset interpreter could constrain capabilities, but it introduces a language/runtime maintenance project and contradicts the small-facility goal. An OS-sandboxed PHP process is the recommended option, subject to platform proof.

The following product decisions remain open:

1. Approve fail-closed nested approval for v1, or require live-process approval waiting. No automatic script restart in either case.
2. Choose supported platforms and sandbox dependency policy. Recommend Linux-first with mandatory containment and no fallback; distribution support has not been approved.
3. Approve a restricted composition set where indirect launch cannot be prevented, and define the remote-MCP trust boundary. Built-ins, extensions, and available MCP tools remain target categories, not unconditional permission to invoke every handler.
4. Choose resource ceilings and artifact retention/privacy. Recommend implementation constants initially, not a new settings family. Numeric budgets need approval and sandbox proof, not arbitrary defaults in this report.
5. Choose the bundled pure-PHP library set, including whether explicit TOON decoding is required in v1.
6. Require MCP cancellation work before release, or approve accurate unknown-remote-outcome reporting as a limitation.

These are recorded decisions for subsequent approval, not reasons to invent product behavior during discovery.

## Implementation slices after approval

First prove the selected sandbox can enforce the permission and teardown contract in supported packaged runtimes. Keep this in CodingAgent process ownership using Symfony Process; no PHP execution feature before containment passes.

Next preserve typed invocation outcomes before presentation. Cover native values, explicit hook denial and suspension, and MCP structured content. Keep App-owned adapters in CodingAgent and neutral execution contracts in AgentCore only where genuinely shared. Do not move extension API or MCP dependencies into AgentCore. Follow `depfile.yaml`, and avoid a new public ExtensionApi contract unless the approved design requires it.

Then add the serial host bridge with required identity, per-call policy, post-rewrite exclusion, cancellation, and duplicate-attempt refusal. Keep nested outcomes out of ordinary model projection. Add artifact ownership, bounded final presentation, and inspectable partial outcomes using existing session and output facilities.

Finally add the one script tool registration and minimal usage documentation. No workflow persistence, parallel scheduling, database/export tools, automatic retries, child orchestration, or new general operation database.

## Deterministic proof plan

This report changes documentation only. Main and the read-only scout loaded the testing skill and `tests/AGENTS.md` before test investigation. No production feature or sandbox correctness is claimed.

After approval, prove value and policy behavior at unit/container level with `castor test`. Use existing `ToolExecutorTest`, `ExtensionToolHookEventSubscriberTest`, `McpResultMapperTest`, and `OutputCapToolResultProcessorContractTest` as contract entry points, not templates for duplicate test layers. DB-touching cases must use the isolated kernel container.

The required cases are:

- Two fixed data-tool responses, deterministic join/filter/sum, and exact CSV. Assert intermediate rows are absent from model messages and present in the permitted inspection artifact. Include structured null, object/list distinction, plain text, and explicit TOON decoding if supported.
- A denied write after one completed call. Assert the write handler never executes, the completed call remains recorded, and later calls never dispatch. Repeat for an approval requirement, hook failure, rewrite to an excluded tool, and indirect child launch.
- A failed nested call after a successful side effect. Assert the bounded cause, nested identity, retained operation reference, terminal outer failure, and no automatic retry. Simulate delivery loss after dispatch and assert unknown rather than failed outcome. Redeliver the outer envelope and prove it does not repeat the side effect.
- Cancellation while a nested request is held at a pipe/socket barrier. Assert admission closes, the owned script exits, the nested request receives cancellation where supported, and all owned resources are reaped. For uncancellable MCP, prove the documented unknown remote outcome without claiming remote termination.
- Oversized text, structured content, binary content, and excessive stdout. Assert limits at transport and storage boundaries, valid inspection references, expiry/quota notices, and no raw payload in logs or canonical model details.
- Real sandbox attempts to read a sentinel outside scratch, access a local network endpoint, load application code, inspect inherited secrets, or spawn a process. Assert denial using deterministic fixtures. Include parent crash and invalid IPC frames. Unit mocks cannot prove OS containment.

Each normal case must finish within 10 seconds. Use readiness barriers, not arbitrary sleeps or timeout increases. Every test owns its processes and temporary directories. Add one controller-replay case for nested-result routing and final-only model history. Use minimal live schema proof only when the tool is implemented; do not use a live LLM to test aggregation or sandbox policy. The eventual CODE-REVIEW transition owns `castor check`; discovery validation is only `castor docs:validate` plus source/citation inspection.

## Source references

Paths and line ranges below refer to the stated baseline. They distinguish observed code from the proposed contract.

- S1: `src/CodingAgent/Tool/ToolRegistry.php:10–110`, provider seeding and definitions.
- S2: `src/CodingAgent/Tool/RegistryBackedToolbox.php:31–61,110–170,194–255`, native resolution, rewrites, and bounded errors.
- S3: `src/AgentCore/Application/Handler/ToolExecutor.php:86–137,199–311,402–529`, dedupe, context, conversion, suspension, processors, and conditional allowlist.
- S4: `src/CodingAgent/Mcp/Client/McpSdkClientAdapter.php:76–94`; `src/CodingAgent/Mcp/Tool/McpResultMapper.php:30–108`; `src/CodingAgent/Mcp/Tool/McpToolInvoker.php:15–109`; `src/CodingAgent/Mcp/Client/McpConnectionManager.php:209–256`.
- S5: `src/CodingAgent/Tool/OutputCapToolResultProcessor.php:35–131`; `src/CodingAgent/Tool/OutputCap.php:14–79,263–327`, rendered-text capping and owned temporary storage.
- S6: `src/AgentCore/Application/Pipeline/ToolCallResultHandler.php:538–554`, tool-end event and model-message projection.
- S7: `src/CodingAgent/Extension/ExtensionToolRegistryBridge.php:75–91,126–129,166–185`; `src/CodingAgent/Extension/ExtensionToolHandlerAdapter.php:13–49`.
- S8: `src/AgentCore/Application/Handler/ExecuteToolCallWorker.php:93–170`; `src/CodingAgent/Mcp/Messenger/McpExecuteToolCallRoutingMiddleware.php:20–115`; `config/services.yaml:1097–1099`; [tool execution topology](../docs/tool-execution.md#scheduling-and-transport); `config/packages/messenger.yaml`.
- S9: `src/CodingAgent/Mcp/Tool/McpParentAvailabilityToolSetResolver.php:14–55`; `src/CodingAgent/Mcp/Tool/McpToolRegistrar.php`; `src/CodingAgent/Mcp/Catalog/SessionFileMcpToolCatalogStore.php`.
- S10: `src/CodingAgent/Tool/SettingsTool.php:40–55`; `src/CodingAgent/Tool/BgStatusTool.php:92–126`.
- S11: `src/CodingAgent/Extension/ExtensionToolHookEventSubscriber.php:118–208,237–329,334–412`, block results and approval correlation.
- S12: `src/CodingAgent/Extension/ExtensionExecBridge.php:33–105`; `.castor/helpers.php:38–106,159–181`; `.castor/run.php:9–13`.
- S13: `src/CodingAgent/Agent/Execution/SubagentToolSetResolver.php:41–111`; `src/CodingAgent/Repository/RunRelationshipReader.php:33–49`; `src/CodingAgent/Extension/ExtensionToolRegistryBridge.php:166–185`.
- S14: `src/AgentCore/Application/Pipeline/ApplyCommandHandler.php:610–680`; `src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardToolCallHook.php:79–217`; `src/AgentCore/Infrastructure/SymfonyAi/RunCancellationToken.php:11–27`.
- S15: [tool result reuse limitations](../docs/tool-execution.md#result-reuse-versus-exclusion); related merged [tool-failure work, PR 483](https://github.com/ineersa/agent-core/pull/483). The related task records bounded causes, completed-step evidence, existing status/log references, and no automatic recovery.
- S16: [runtime logging privacy](../docs/datadog.md#principles); `src/AgentCore/Contract/Tool/DiagnosticMessageSanitizer.php`; `tests/AGENTS.md` and `.agents/skills/testing/SKILL.md` for proof requirements.
