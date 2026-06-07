# Enforce universal output cap for LLM-visible tool output

## Goal
## Problem

User report: output cap does not reliably work. Any output that can be sent to an LLM must pass through output capping, including bash/read output and outputs from third-party, extension, MCP, or future tools. Do not rely on per-tool opt-in capping alone.

## Scout findings / required context

Three scout subagents inspected the repo and returned these concrete findings:

### Existing output-cap implementation

- `src/CodingAgent/Tool/OutputCap.php`
  - `process(string $text, ?string $path = null): string` persists oversized text under `OutputCapConfig::$storageDir` and returns the model-facing notice:
    - `[Output capped to %d characters, full output saved to %s]`
    - includes full char/token estimate and `head`/`grep` hints.
  - doc-like extensions are `md`, `txt`, `toon` and use `docCap`; null/other paths use `defaultCap`.
- `src/CodingAgent/Config/OutputCapConfig.php`
  - settings key: `tools.output_cap`
  - keys: `path`, `default_cap`, `doc_cap`, `retention`, `session_prefix`.
  - defaults: path `.hatfield/tmp/output-cap`, default cap `20000`, doc cap `50000`, retention `86400`.
- DI: `config/services.yaml` builds `OutputCapConfig` from `AppConfig` and autowires `OutputCap` consumers.

### Current coverage gaps

- Only some built-in tools call `OutputCap` directly:
  - `src/CodingAgent/Tool/BashTool.php` caps normal and timeout output.
  - `src/CodingAgent/Tool/ReadFileTool.php` caps returned file content.
- `src/CodingAgent/Tool/BgStatusTool.php::handleLog()` currently returns a log tail string without `OutputCap`; log tails can be large.
- Short-summary tools (`WriteFileTool`, `EditFileTool`) are low risk but still should be covered by the universal LLM-bound cap if their behavior changes later.
- Extension / third-party tools can bypass per-tool `OutputCap` entirely.
- `ToolExecutor::toDomainResult()` stores handler output in `ToolResult::content` and `details['raw_result']` without a global cap.
- `AgentMessageNormalizer::toolMessage()` JSON-encodes the entire tool result payload into a tool-role `AgentMessage` text part with no length cap. This includes `details.raw_result` and is currently the main path by which large tool output enters `RunState->messages`.
- `AgentMessageConverter::contentToText()` later turns text parts into the actual provider message with no length cap.
- `src/AgentCore/Infrastructure/SymfonyAi/LlmPlatformAdapter.php` already supports `TransformContextHookInterface` implementations before provider conversion:
  - `resolveContextMessages()` -> `applyTransformHooks()` -> `applyConvertHooks()` -> Symfony provider input.
  - `TransformContextHookInterface` is in AgentCore and returns `list<AgentMessage>`.
  - `config/services.yaml` auto-tags implementations with `agent_core.transform_context_hook` and wires them into `LlmPlatformAdapter`.
  - No current implementation exists.
- Architecture boundary: `AgentCore` must not depend on `CodingAgent`. `OutputCap` lives in `CodingAgent`, so a direct dependency from `AgentMessageNormalizer`/other Core classes to `OutputCap` would violate deptrac unless a Core contract/strategy is introduced. A `CodingAgent` transform hook implementing existing `TransformContextHookInterface` is the lowest-friction boundary-safe option.

### Extension hook caveat

- Scouts inspected the output-cap extension docs/code under `/home/ineersa/claw/my-pi` per docs guard.
- `src/CodingAgent/Extension/ExtensionToolHookEventSubscriber.php::runResultHooks()` updates local `$content`/`$currentDetails` variables but returns `void`; Symfony AI `ToolCallSucceeded` / `ToolCallFailed` result data is effectively not mutated.
- Therefore a TypeScript output-cap extension returning a replacement from a `tool_result` hook is currently observational only and must not be the sole enforcement mechanism. If this task touches that path, either make replacement decisions effective before AgentCore receives the result, or document why universal LLM-bound capping supersedes it and add tests that prove extension/third-party output is capped anyway.

## Required implementation shape

1. Add a mandatory, central LLM-bound capping layer for tool-result text so *all* tool outputs are capped before provider invocation:
   - Preferred approach: a `CodingAgent` service implementing `Ineersa\AgentCore\Contract\Hook\TransformContextHookInterface` that transforms tool-role `AgentMessage` text parts using `OutputCap` before `LlmPlatformAdapter` converts messages to Symfony AI messages.
   - Preserve `AgentMessage` metadata: `role`, `timestamp`, `name`, `toolCallId`, `toolName`, `details`, `isError`, and `metadata`.
   - Cap every string text part that can reach the LLM for `role === 'tool'` (and consider whether custom tool-like roles need coverage). Do not cap `image_ref` attachments; preserve image gating behavior.
   - Ensure fallback paths cannot leak `details.raw_result` to LLM. If a converter can fall back to `details`, either keep text content present after capping or cap/disable that fallback for tool messages.
   - Do not create an `AgentCore -> CodingAgent` dependency. If implementation uses a Core contract instead of a transform hook, bind a CodingAgent implementation and verify `castor deptrac`.
2. Keep existing per-tool `OutputCap` use in `BashTool` and `ReadFileTool`; it provides useful persisted-output hints near the tool result and should remain.
3. Add direct `OutputCap` use to `BgStatusTool::handleLog()` or otherwise prove via tests that `bg_status log` cannot send an uncapped large log to the LLM.
4. Preserve existing non-obvious comments. Do not add backward-compatibility shims or test-only production APIs.
5. All caught exceptions must be propagated or logged/documented as intentional degradation; no empty catches.

## Required tests

Add both deterministic regression coverage and the requested focused real-LLM test.

### Deterministic regression coverage

Add a unit/integration test that fails on the current gap and proves third-party/custom tool results are capped centrally:

- Build a `role: 'tool'` `AgentMessage` or simulated `ToolCallResult` whose text/details contain a large unique sentinel after the configured cap, representing an extension/MCP/third-party tool that did not call `OutputCap`.
- Run it through the actual new capping layer and provider-conversion path (`TransformContextHookInterface` + `AgentMessageConverter`/`LlmPlatformAdapter` seam as appropriate).
- Use an isolated temp output-cap directory and low caps (e.g. `default_cap: 500`, `doc_cap: 500`).
- Assert the provider-facing text contains `Output capped`, does not contain the raw sentinel, and a persisted output-cap file contains the full original text/sentinel.
- Also cover existing built-in/log gap if `BgStatusTool` is changed: `bg_status log` over cap should return or become LLM-visible only as the capped notice.

Useful existing test files/patterns:

- `tests/CodingAgent/Tool/OutputCapTest.php`
- `tests/CodingAgent/Tool/ReadFileToolTest.php`
- `tests/AgentCore/Application/Pipeline/ToolCallResultHandlerTest.php`
- `tests/CodingAgent/Tool/ViewImageToolTest.php` around the tool-result -> `AgentMessage` -> `MessageBag` path.

### Focused real-LLM read-large-file test

Add a focused test that asks the real local model to read a large file with a low cap in isolated settings.

Preferred deterministic location: `tests/CodingAgent/Runtime/Controller/E2E/OutputCapReadFileControllerTest.php` extending `ControllerE2eTestCase`.

Existing helpers/patterns:

- `tests/CodingAgent/Runtime/Controller/E2E/ControllerE2eTestCase.php`
  - `createIsolatedProjectDir()` writes a fresh `.hatfield/settings.yaml` under a `var/tmp/test-*` project.
  - `spawnController()` starts `agent --controller --cwd=<temp> --tools-excluded=bash`.
  - `waitForEvent()`, `collectEvents()`, `assertSessionArtifactsExist()`, diagnostics helpers.
- `tests/CodingAgent/Runtime/Controller/E2E/ControllerSmokeTest.php` shows the `start_run` command payload.
- CLI supports `--tools=<allowlist>` and `--tools-excluded=<denylist>` in `src/CodingAgent/CLI/AgentCommand.php`. Consider overriding/adjusting the controller spawn for this test to expose only `read` (e.g. `--tools=read`) so the model is forced toward the requested tool and cannot use bash to inspect the persisted file.

Test requirements:

- Mark with `#[Group('llm-real')]`. If implemented as a TUI/tmux test instead, also mark `#[Group('tui-e2e')]`; controller mode is preferred for less flake.
- Isolated `.hatfield/settings.yaml` must include low caps, setting both defaults because `.txt` uses `doc_cap`:

```yaml
tools:
    output_cap:
        path: .hatfield/tmp/output-cap
        default_cap: 500
        doc_cap: 500
        retention: 86400
        session_prefix: null
```

- Create a large file in the isolated project, e.g. `large-output.txt`, with content far over 500 chars and a unique marker only after the first 500 chars (e.g. `CAP_SHOULD_HIDE_<random>`).
- Prompt the model explicitly, e.g. `Use the read tool to read large-output.txt. After reading, answer whether the tool output was capped. Do not use bash.`
- Assert the run includes a read tool execution (`tool_execution.started` payload has `tool_name: read`) and finishes successfully or with a controlled diagnostic if the model refuses/tool-calling fails.
- Assert cap behavior using session artifacts/provider-facing messages rather than only the final assistant text:
  - output-cap storage dir exists under the isolated project and contains a persisted `.txt` file with the full marker;
  - the tool message/provider-facing content contains `Output capped`;
  - the raw marker does not appear in the content sent to the follow-up LLM step.
- If the test inspects `state.json`, remember current runtime events do not include tool result content in `tool_execution.completed`; inspect `RunState->messages` or the new transform/provider seam instead.

## Validation

Use Castor only. Required commands for implementation handoff:

- Focused deterministic tests, e.g. `castor test --filter=OutputCap` / new test filter(s).
- `castor test:llm-real --filter=OutputCapReadFile` (or equivalent Castor filter supported by the task runner) with llama.cpp test server on port 9052.
- `castor deptrac`
- `castor phpstan`
- `castor cs-check`
- Because this touches LLM-visible execution flow, final CODE-REVIEW gate must run `LLM_MODE=true castor check` via `move_task(to="CODE-REVIEW")`. If tmux or llama.cpp:9052 is unavailable, keep the task IN-PROGRESS and record the blocker; do not mark ready for review.

## Acceptance criteria
- All LLM-bound tool-result text is passed through a central output-cap layer before provider invocation, including outputs from built-in, extension, MCP, third-party, and future tools that do not call OutputCap themselves.
- The implementation respects layer boundaries (`castor deptrac` passes); AgentCore does not depend directly on CodingAgent/OutputCap.
- Existing per-tool OutputCap behavior for bash/read is preserved, and `bg_status log` cannot leak uncapped large log text to the LLM.
- A deterministic regression test proves a simulated third-party/custom over-cap tool result is capped before provider conversion, the raw sentinel is absent from provider-facing text, and the full output is persisted.
- A focused `llm-real` E2E test asks the local llama_cpp test model to read a large file with isolated low `tools.output_cap` settings and verifies cap persistence and LLM-facing capped content.
- All QA/test/lint/static-analysis commands are run via Castor; full `LLM_MODE=true castor check` is required before CODE-REVIEW because this changes LLM-visible flow.

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
- Created: 2026-06-07T23:15:32.094Z
