# Extension tool hooks and SafeGuard extension plan

Date: 2026-05-29

## Purpose

Start extending Hatfield's extension capability toward Pi parity by implementing only the smallest useful slice first:

1. Public extension API support for Pi-like `tool_call` and `tool_result` hooks.
2. Internal CodingAgent/AgentCore bridging so extension hooks run on the real tool execution path.
3. A proper SafeGuard extension implemented only through the public `Ineersa\Hatfield\ExtensionApi` surface.

This intentionally starts with tool interception only. Session lifecycle hooks, input hooks, slash commands, UI widgets, approval prompts, resource discovery, model selection, and cross-extension event bus are later work.

## Source references

Relevant existing docs:

- `docs/archive/implementation/events_and_hooks_report.md`
  - marks `tool_call` and `tool_result` as partial: AgentCore has indirect Symfony Toolbox events, but no first-class native/public hook contract.
- `docs/archive/implementation/15-pi-mono-hooks-events-report.md`
  - Pi `tool_call` can mutate input in place and/or block.
  - Pi `tool_result` can override result fields.
- `.pi/plans/extension-api-phar-plan.md`
  - `src/CodingAgent/ExtensionApi/` is a public compatibility boundary using namespace `Ineersa\Hatfield\ExtensionApi`.
  - ExtensionApi must not depend on Symfony, AgentCore, CodingAgent internals, TUI, settings, runtime, or DI.

Relevant current code facts:

- `src/CodingAgent/ExtensionApi/ExtensionApiInterface.php` currently exposes only `registerTool()`.
- `src/CodingAgent/Extension/ExtensionToolRegistryBridge.php` implements `ExtensionApiInterface` and forwards public tool registrations into `ToolRegistryInterface`.
- `src/CodingAgent/Extension/ExtensionManager.php` loads configured extension classes from `extensions.enabled`, instantiates them, and calls `register($api)` before runtime startup.
- `src/AgentCore/Application/Handler/ToolExecutor.php` is the central execution choke point for tool calls, including policy, idempotency, cancellation, allowlist checks, and Symfony Toolbox execution.
- Symfony AI's native `Toolbox` has `ToolCallRequested`, `ToolCallSucceeded`, and `ToolCallFailed` events, but Hatfield's production `src/CodingAgent/Tool/RegistryBackedToolbox.php` directly invokes registered handlers and currently does not dispatch those Symfony events.
- `tests/AgentCore/Application/Handler/ToolExecutorTest.php::testSymfonyToolboxRequestedEventCanDenyExecution()` proves the lower-level concept works with native Symfony `Toolbox`, but extension authors cannot use it today.

## Design constraints

### Extension API boundary stays pure

New public hook contracts must live under:

```text
src/CodingAgent/ExtensionApi/
namespace Ineersa\Hatfield\ExtensionApi;
```

They may use only PHP-native types and API-local DTOs/enums/interfaces. They must not import Symfony AI event classes, AgentCore `ToolCall`, CodingAgent `ToolRegistry`, TUI widgets, settings providers, or runtime DTOs.

### SafeGuard must be a real extension

SafeGuard should not be hardwired as a special service inside `ToolExecutor`.

It should be implemented as a class implementing:

```php
Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface
```

and should register its tool hooks through `ExtensionApiInterface`.

### Tool hooks are enough for the first SafeGuard slice

SafeGuard's core policy maps to tool calls:

| Policy concern | Tool call surface |
|---|---|
| Dangerous shell commands | `bash` |
| Environment exposure | `bash` |
| Writes outside project cwd | `write`, `edit` |
| Destructive file changes | `edit`, future patch tools |
| Sensitive file reads | `read`, possibly `view_image` |

The first SafeGuard version can allow or block. Interactive allow/block prompts are explicitly later work.

## Public API v2: tool hooks only

### ExtensionApiInterface additions

Extend the public API with two registration methods:

```php
interface ExtensionApiInterface
{
    public function registerTool(ToolRegistrationDTO $tool): void;

    public function registerToolCallHook(ToolCallHookInterface $hook): void;

    public function registerToolResultHook(ToolResultHookInterface $hook): void;
}
```

### ToolCallHookInterface

```php
interface ToolCallHookInterface
{
    public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO;
}
```

`ToolCallContextDTO` should contain only public, serializable-ish primitives/arrays:

```php
final readonly class ToolCallContextDTO
{
    /** @param array<string, mixed> $arguments */
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $arguments,
        public int $orderIndex,
        public ?string $runId = null,
        public ?int $turnNo = null,
        public ?string $cwd = null,
        public array $metadata = [],
    ) {}
}
```

`cwd` is important so extensions like SafeGuard can make project-relative decisions without depending on `AppConfig`.

### ToolCallDecisionDTO

Keep this first version small:

```php
enum ToolCallDecisionKindEnum: string
{
    case Allow = 'allow';
    case Block = 'block';
    case ReplaceResult = 'replace_result';
}

final readonly class ToolCallDecisionDTO
{
    /** @param mixed $result */
    /** @param array<string, mixed> $details */
    private function __construct(
        public ToolCallDecisionKindEnum $kind,
        public ?string $reason = null,
        public mixed $result = null,
        public array $details = [],
    ) {}

    public static function allow(): self;

    public static function block(string $reason, array $details = []): self;

    public static function replaceResult(mixed $result, array $details = []): self;
}
```

Notes:

- `Allow` means continue to the next hook or execute the tool.
- `Block` means no later hook or tool handler should execute.
- `ReplaceResult` means return a custom tool result without invoking the handler. It can support cache-like extensions later, but SafeGuard probably only needs `Block`.
- Do **not** add `RequireApproval` yet. Approval needs a runtime/TUI tool-approval control channel and original-tool-call resume semantics. That is a separate slice.

### ToolResultHookInterface

```php
interface ToolResultHookInterface
{
    public function onToolResult(ToolResultContextDTO $context): ToolResultDecisionDTO;
}
```

`ToolResultContextDTO`:

```php
final readonly class ToolResultContextDTO
{
    /** @param array<string, mixed> $arguments */
    /** @param array<int, array<string, mixed>> $content */
    /** @param array<string, mixed> $details */
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $arguments,
        public bool $isError,
        public array $content,
        public array $details,
        public ?string $runId = null,
        public ?int $turnNo = null,
        public ?string $cwd = null,
        public array $metadata = [],
    ) {}
}
```

`ToolResultDecisionDTO`:

```php
enum ToolResultDecisionKindEnum: string
{
    case Keep = 'keep';
    case Replace = 'replace';
}

final readonly class ToolResultDecisionDTO
{
    /** @param array<int, array<string, mixed>>|null $content */
    /** @param array<string, mixed>|null $details */
    private function __construct(
        public ToolResultDecisionKindEnum $kind,
        public ?bool $isError = null,
        public ?array $content = null,
        public ?array $details = null,
    ) {}

    public static function keep(): self;

    public static function replace(?bool $isError = null, ?array $content = null, ?array $details = null): self;
}
```

SafeGuard does not need result hooks for enforcement, but adding both `tool_call` and `tool_result` gives us the Pi tool-interception pair and supports later audit/redaction without another API bump.

## Internal bridge design

### Hook registry bridge

Add an app-internal registry/bridge in `src/CodingAgent/Extension/`, for example:

```text
ExtensionHookRegistry.php
ExtensionApiBridge.php or extend ExtensionToolRegistryBridge.php
```

Suggested shape:

```php
final class ExtensionHookRegistry
{
    /** @var list<ToolCallHookInterface> */
    private array $toolCallHooks = [];

    /** @var list<ToolResultHookInterface> */
    private array $toolResultHooks = [];

    public function addToolCallHook(ToolCallHookInterface $hook): void;

    /** @return list<ToolCallHookInterface> */
    public function toolCallHooks(): array;

    public function addToolResultHook(ToolResultHookInterface $hook): void;

    /** @return list<ToolResultHookInterface> */
    public function toolResultHooks(): array;
}
```

Then `ExtensionToolRegistryBridge` can become the full public API implementation, holding both:

```php
private ToolRegistryInterface $toolRegistry;
private ExtensionHookRegistry $hookRegistry;
```

and implementing:

```php
registerTool()
registerToolCallHook()
registerToolResultHook()
```

Alternative: rename `ExtensionToolRegistryBridge` to `ExtensionApiRuntimeBridge`. Renaming is optional; avoid churn unless the name becomes actively misleading.

### ToolExecutor integration

Add an internal adapter service, e.g.:

```text
src/CodingAgent/Extension/ToolHookDispatcher.php
```

This service depends on `ExtensionHookRegistry` and converts between internal AgentCore/CodingAgent tool structures and public ExtensionApi DTOs.

`ToolExecutor` should not depend directly on `ExtensionApi` or `CodingAgent` classes because it lives in `src/AgentCore/`. Instead add a small AgentCore contract:

```text
src/AgentCore/Contract/Tool/ToolCallInterceptorInterface.php
```

Suggested AgentCore-side contract:

```php
interface ToolCallInterceptorInterface
{
    public function beforeToolCall(ToolCall $toolCall): ToolCallInterceptionResult;

    public function afterToolCall(ToolCall $toolCall, ToolResult $toolResult): ToolResult;
}
```

AgentCore-owned result DTOs should live under `src/AgentCore/Domain/Tool/` or `src/AgentCore/Contract/Tool/` and use AgentCore types. CodingAgent's `ToolHookDispatcher` implements this contract and adapts extension hooks.

Wire it into `ToolExecutor` as an optional dependency:

```php
private readonly ?ToolCallInterceptorInterface $toolCallInterceptor = null
```

Execution order:

```text
ToolExecutor::execute()
  -> resolve policy
  -> idempotency/cache reuse checks
  -> cancellation before start
  -> executeToolCall()
       -> interrupt mode / ask_user check
       -> toolbox availability check
       -> active tools_ref allowlist check
       -> BEFORE extension tool hooks
       -> execute real toolbox handler
       -> AFTER extension result hooks
  -> duration/timeout/cancellation stale handling
  -> rememberAndReturn()
```

Important ordering choice:

- Run extension hooks **after** the active tool allowlist check so an extension cannot resurrect a tool that was not exposed to the model.
- Run extension hooks **before** the actual handler invocation so SafeGuard can block dangerous calls.
- Apply result hooks before final `rememberAndReturn()` so cached/idempotent result metadata wraps the final result.

### Why not expose Symfony AI events directly?

Do not expose `Symfony\AI\Agent\Toolbox\Event\ToolCallRequested` through `ExtensionApi` because the public API boundary must remain extraction-safe and Symfony-free.

Also, Hatfield's production `RegistryBackedToolbox` currently bypasses Symfony AI event dispatch. We can either add Symfony event dispatch to `RegistryBackedToolbox` later or not; the ExtensionApi hook bridge should not depend on that detail.

## SafeGuard extension design

### Location

Prefer a bundled extension namespace inside the app while still using only public ExtensionApi contracts:

```text
src/CodingAgent/Extension/Builtin/SafeGuard/
```

or, if we want to model external packages more strictly:

```text
extensions/safe-guard/src/
```

Recommendation for first implementation: use `src/CodingAgent/Extension/Builtin/SafeGuard/` but require the extension class itself to interact with Hatfield only through `ExtensionApiInterface`. Internal loading can enable it by FQCN in `extensions.enabled`, just like any third-party extension.

Example class:

```php
namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;

final class SafeGuardExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
        $policyStore = new SafeGuardPolicyStore();
        $classifier = new SafeGuardClassifier($policyStore);

        $api->registerToolCallHook(new SafeGuardToolCallHook($classifier));
    }
}
```

This keeps SafeGuard honest: it registers a public hook like third-party code would.

### Policy files

Use Hatfield paths, not Pi paths:

```text
project: <cwd>/.hatfield/safe-guard.json
home:    ~/.hatfield/safe-guard.json
```

First version can read both, with project policy taking precedence or merging on top of home policy. Because project-local policy may include sensitive absolute paths, document that teams should avoid committing private allowlists.

Policy structure based on Pi, adjusted for Hatfield:

```json
{
  "allowCommandPatterns": [],
  "allowWriteOutsideCwd": [],
  "allowDestructiveInPaths": [],
  "protectedReadPatterns": [],
  "dangerousCommandPatterns": []
}
```

Default protected/dangerous patterns are always active even if the policy file is absent.

### Classification rules

Port the safe, deterministic subset first:

#### Bash hard blocks

Always block:

- `sudo`
- `su`
- commands attempting privilege escalation that cannot be made safe by allowlist

#### Bash dangerous commands

Block unless allowed by policy:

- `rm`
- `rmdir`
- `git clean`
- `git reset --hard`
- `git push --force`
- `git push -f`
- `chmod -R 777`
- shell redirection into protected paths if detectable

#### Environment exposure

Block unless allowed by policy:

- `env`
- `printenv`
- broad environment dumps such as `export` with no filtering

#### Writes outside cwd

For `write` and `edit`, resolve target paths against `cwd` and block writes outside the project unless policy allows them.

#### Sensitive reads

For `read` and path-reading tools, block unless allowed by policy:

- `.env`
- `.env.local`
- `.env.*.local`
- `auth.json`
- `credentials.json`
- `.ssh/id_*`
- `.aws/credentials`
- `.kube/config`
- `*.pem`
- `*.key`

### MVP behavior

Because this slice has no UI approval flow:

- `Allow` means the tool executes normally.
- Protected/dangerous operations return `Block` with a clear reason.
- The blocked tool result should be structured and LLM-visible, e.g.:

```json
{
  "denied": true,
  "reason": "safe_guard_policy",
  "message": "SafeGuard blocked bash command because it matches dangerous pattern: rm",
  "category": "dangerous_command"
}
```

No prompting, no allow-once, no always-allow in this first slice.

## Implementation phases

### Phase 1 — Public ExtensionApi tool hook contracts

Files:

```text
src/CodingAgent/ExtensionApi/ToolCallHookInterface.php
src/CodingAgent/ExtensionApi/ToolResultHookInterface.php
src/CodingAgent/ExtensionApi/ToolCallContextDTO.php
src/CodingAgent/ExtensionApi/ToolResultContextDTO.php
src/CodingAgent/ExtensionApi/ToolCallDecisionDTO.php
src/CodingAgent/ExtensionApi/ToolCallDecisionKindEnum.php
src/CodingAgent/ExtensionApi/ToolResultDecisionDTO.php
src/CodingAgent/ExtensionApi/ToolResultDecisionKindEnum.php
src/CodingAgent/ExtensionApi/ExtensionApiInterface.php
```

Acceptance:

- ExtensionApi remains dependency-free and deptrac-clean.
- Existing extensions using only `registerTool()` remain source-compatible.
- Contract tests cover default factory methods and immutable DTO behavior.

Validation:

```bash
castor deptrac
castor test --filter ExtensionApi
```

### Phase 2 — App-internal hook registry and API bridge

Files:

```text
src/CodingAgent/Extension/ExtensionHookRegistry.php
src/CodingAgent/Extension/ExtensionToolRegistryBridge.php
config/services.yaml
```

Acceptance:

- `registerToolCallHook()` and `registerToolResultHook()` append hooks in extension registration order.
- Misbehaving registration is logged/isolated consistently with current extension loading policy.
- Existing `registerTool()` behavior is unchanged.

Validation:

```bash
castor test --filter Extension
castor deptrac
```

### Phase 3 — AgentCore interception seam

Files:

```text
src/AgentCore/Contract/Tool/ToolCallInterceptorInterface.php
src/AgentCore/Domain/Tool/ToolCallInterceptionResult.php
src/AgentCore/Domain/Tool/ToolCallInterceptionKind.php
src/AgentCore/Application/Handler/ToolExecutor.php
```

Acceptance:

- `ToolExecutor` invokes `beforeToolCall()` after allowlist checks and before handler execution.
- Blocked calls do not invoke the underlying handler.
- Replaced results do not invoke the underlying handler.
- `afterToolCall()` can replace/adjust the final result after successful/failed toolbox execution.
- Interceptor exceptions are converted to safe blocked/error results, not process crashes.

Validation:

```bash
castor test --filter ToolExecutor
castor test
```

### Phase 4 — CodingAgent adapter from ExtensionApi hooks to AgentCore seam

Files:

```text
src/CodingAgent/Extension/ToolHookDispatcher.php
config/services.yaml
```

Acceptance:

- Public `ToolCallContextDTO` receives correct `toolCallId`, `toolName`, arguments, order index, run id, turn number, and cwd.
- Public hook `Block` maps to an AgentCore error `ToolResult` with structured details.
- Public hook `ReplaceResult` maps to a normal non-handler result.
- Multiple hooks run in registration order.
- First non-allow before-hook decision wins.
- Result hooks run in registration order, each seeing the latest result state.

Validation:

```bash
castor test --filter ToolHookDispatcher
castor test --filter ToolExecutor
castor deptrac
```

### Phase 5 — SafeGuard extension MVP

Files:

```text
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardExtension.php
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardToolCallHook.php
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardClassifier.php
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardPolicy.php
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardPolicyStore.php
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardPathMatcher.php
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardCommandMatcher.php
```

Acceptance:

- SafeGuard class implements `HatfieldExtensionInterface`.
- SafeGuard registers only through `ExtensionApiInterface`.
- SafeGuard blocks dangerous bash commands according to default rules.
- SafeGuard blocks protected reads according to default rules.
- SafeGuard blocks writes/edits outside cwd by default.
- Policy files can relax configured categories without disabling hard blocks like `sudo`.
- Unit tests cover classifier rules without requiring actual tool execution.
- Integration tests prove the extension blocks a registered fake tool call through the real hook bridge.

Validation:

```bash
castor test --filter SafeGuard
castor test --filter ToolHookDispatcher
castor test
castor deptrac
```

### Phase 6 — Settings/docs enablement

Files:

```text
config/hatfield.defaults.yaml
.hatfield/settings.yaml
docs/settings.md
```

Decision needed before implementation:

- Should SafeGuard be enabled by default?

Recommended first choice:

- Keep `extensions.enabled: []` by default.
- Document enabling SafeGuard explicitly:

```yaml
extensions:
  enabled:
    - Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
```

This avoids surprising existing development workflows while the guard is new.

Acceptance:

- Settings docs show how to enable the built-in extension.
- Docs explain policy file locations and MVP noninteractive behavior.

Validation:

```bash
castor test --filter Config
castor check
```

## Later work intentionally out of scope

### Interactive approval prompts

Pi SafeGuard supports Block / Allow once / Always allow through interactive UI. Hatfield needs a runtime/TUI approval path before this can be copied safely.

Future extension API might add:

```php
ToolCallDecisionDTO::requireApproval(ApprovalRequestDTO $request)
```

but that requires:

- a local runtime approval queue,
- TUI approval widgets/input routing,
- original tool call resume semantics,
- noninteractive timeout/deny behavior,
- policy update semantics for "always allow".

This should use a common TUI approval component, but it should not depend on the QH/HITL flow. SafeGuard approvals are local runtime control prompts for tool execution, not model-visible `ask_human` transcript HITL.

### More Pi parity hooks

After tool hooks prove the public API/bridge pattern, consider adding additional Pi-like surfaces in separate small slices:

1. `input` hook for prompt rewriting/handling.
2. `before_agent_start` for turn-local system prompt/message injection.
3. `context` hook as public wrapper around existing `TransformContextHookInterface`.
4. `before_provider_request` as public wrapper around existing provider-boundary hook.
5. lifecycle observer hooks: `agent_start`, `agent_end`, `turn_start`, `turn_end`.
6. resource discovery: skill/prompt/theme paths.
7. extension event bus.

Do not bundle these into the SafeGuard MVP.

## Open questions

1. **SafeGuard enablement**: explicit opt-in first, or enabled by default once bash/read/write/edit are real?
2. **Policy merge rules**: project overrides home, or project and home lists merge?
3. **Builtin extension location**: keep bundled SafeGuard under `src/CodingAgent/Extension/Builtin/`, or create a package-like `extensions/safe-guard/` directory now?
4. **Result hook mutability**: should result hooks be allowed to flip `isError`, or only content/details? Pi's contract includes `isError`, but previous Pi bridge caveat says error override was imperfect.
5. **RegistryBackedToolbox Symfony events**: should we also dispatch Symfony AI Toolbox events there for compatibility/observability, or keep only Hatfield ExtensionApi hooks for now?

## Recommended first task split

1. `EXT-HOOK-01 Public ExtensionApi tool hook contracts`
2. `EXT-HOOK-02 Extension hook registry and bridge wiring`
3. `EXT-HOOK-03 AgentCore ToolExecutor interception seam`
4. `EXT-HOOK-04 CodingAgent adapter from ExtensionApi hooks to ToolExecutor`
5. `SAFE-01 SafeGuard classifier and policy store`
6. `SAFE-02 SafeGuard extension hook integration`
7. `SAFE-03 SafeGuard docs/settings/tests`
8. `EXT-HOOK-05 Extension tool approval via existing HITL interrupt flow`
9. `SAFE-04 SafeGuard approval flow over existing HITL interrupt`

> **Note**: TUI-APPROVAL-01 was removed. The existing HITL `waiting_human` → `answer_human` flow
> handles approval prompts natively. No new TUI component or runtime plumbing needed.
