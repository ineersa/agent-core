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

**Update (EXT-HOOK-03 pivot):** `RegistryBackedToolbox` now dispatches Symfony AI lifecycle events internally. The extension bridge (`ExtensionToolHookEventSubscriber`) adapts between public ExtensionApi DTOs and Symfony AI events. Extension authors never see Symfony AI types.

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

### Phase 3 — Symfony AI lifecycle events in RegistryBackedToolbox (pivoted from custom AgentCore interceptor)

> **Pivot note:** Originally planned as a custom `ToolCallInterceptorInterface` in AgentCore.
> Pivoted to reusing Symfony AI's native `ToolCallRequested`/`ToolCallSucceeded`/`ToolCallFailed`
> events inside `RegistryBackedToolbox`. `ToolCallRequested` supports `deny()` and `setResult()`
> for pre-execution interception, which is sufficient for SafeGuard's needs.

Files:

```text
src/CodingAgent/Tool/RegistryBackedToolbox.php
config/services.yaml
src/AgentCore/Domain/Tool/ToolContext.php  (added orderIndex)
```

Acceptance:

- `RegistryBackedToolbox::execute()` dispatches `ToolCallRequested` before execution.
- `ToolCallRequested::deny()` skips handler and returns denial result.
- `ToolCallRequested::setResult()` skips handler and returns custom result.
- `ToolCallSucceeded` and `ToolCallFailed` dispatch after execution for observability.
- `ToolExecutor` is **not** modified — events flow through the existing toolbox path.

Validation:

```bash
castor test --filter RegistryBackedToolbox
castor test --filter ToolExecutor
castor deptrac
```

### Phase 4 — CodingAgent adapter from ExtensionApi hooks to Symfony AI events

> **Update:** Instead of implementing an AgentCore `ToolCallInterceptorInterface`,
> this phase created `ExtensionToolHookEventSubscriber` that subscribes to Symfony AI
> `ToolCallRequested`/`ToolCallSucceeded`/`ToolCallFailed` events and iterates
> `ExtensionHookRegistry` hooks, converting between ExtensionApi DTOs and Symfony AI event types.

Files:

```text
src/CodingAgent/Extension/ExtensionToolHookEventSubscriber.php
config/services.yaml
depfile.yaml
src/AgentCore/Application/Handler/ToolExecutor.php  (orderIndex propagation)
src/AgentCore/Domain/Tool/ToolContext.php
```

Acceptance:

- Public `ToolCallContextDTO` receives correct `toolCallId`, `toolName`, arguments, order index, run id, turn number, and cwd.
- Public hook `Block` maps to `ToolCallRequested::setResult()` with denied result.
- Public hook `ReplaceResult` maps to `ToolCallRequested::setResult()` with custom result.
- Multiple hooks run in registration order.
- First non-allow before-hook decision wins.
- Result hooks (`ToolCallSucceeded`/`ToolCallFailed`) are observational only — cannot mutate the Symfony AI result.

Validation:

```bash
castor test --filter ExtensionToolHookEventSubscriber
castor test
castor deptrac
```

### Phase 5 — SafeGuard extension MVP

Files:

```text
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardExtension.php
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardToolCallHook.php
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardClassifier.php
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardConfig.php
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardDecision.php
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardDecisionKind.php
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardPathMatcher.php
src/CodingAgent/Extension/Builtin/SafeGuard/SafeGuardCommandMatcher.php
```

> **Update:** SafeGuard reads settings from `extensions.settings.safe_guard` in YAML
> via `$api->getSettings('safe_guard')`, not from a separate JSON policy file.
> CWD comes from `$api->getCwd()`. Config DTO lives in the extension namespace
> (`AppExtensionBuiltin` deptrac layer), not in `CodingAgent\Config`.

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

### Phase 6 — Settings/docs enablement and cross-process extension loading

> **Update:** SafeGuard is enabled by default in `hatfield.defaults.yaml`.
> Extension loading moved from `AgentCommand` to `ExtensionLoaderSubscriber` (ConsoleEvents::COMMAND)
> to ensure extensions load in all processes including `messenger:consume` workers.

Files:

```text
config/hatfield.defaults.yaml
src/CodingAgent/Extension/ExtensionLoaderSubscriber.php
src/CodingAgent/CLI/AgentCommand.php  (loadExtensions call removed)
config/services.yaml
src/CodingAgent/Kernel.php  (extends HttpKernel\Kernel)
bin/console  (uses FrameworkBundle\Console\Application)
```

Acceptance:

- SafeGuard blocks writes outside CWD in real agent sessions.
- Extensions load in `agent`, `messenger:consume`, and all `bin/console` commands.
- `bin/console cache:clear` works.
- `castor check` passes.

## Later work intentionally out of scope

### Interactive approval prompts

Pi SafeGuard supports Block / Allow once / Always allow through interactive UI.

**Update (EXT-HOOK-05):** `ToolCallDecisionKindEnum::RequireApproval` was added. The `ExtensionToolHookEventSubscriber` converts `RequireApproval` decisions to `setResult()` with an interrupt payload shape (`{kind: 'interrupt', question_id, prompt, schema, tool_name, tool_call_id, approval_context}`). This reuses Hatfield's existing HITL interrupt flow:

```
ExtensionToolHookEventSubscriber → setResult(interrupt payload)
  → RegistryBackedToolbox returns ToolResult
  → ToolExecutor::toDomainResult() detects kind=interrupt
  → ToolCallResultHandler sets WaitingHuman
  → HitlMappingSubscriber maps to runtime event
  → TUI shows prompt (requires QH-01/QH-02/QH-03)
  → User approves/denies
  → ApplyCommandHandler resumes run
  → LLM retries tool call
  → SafeGuard auto-allows via ApprovalSessionTracker (pending entry consumed)
```

SAFE-04 will implement the SafeGuard-specific `ApprovalSessionTracker` and map classification categories to approval decisions. **Blocked on QH-01/QH-02/QH-03** because the TUI currently has no question/answer widget or input routing.

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

## Resolved questions and post-implementation notes

### SafeGuard enablement
Enabled by default in `hatfield.defaults.yaml`.

### Policy merge rules
Project settings override home settings (not merge). Both fall back to built-in defaults when absent.

### Builtin extension location
Bundled under `src/CodingAgent/Extension/Builtin/SafeGuard/` with `AppExtensionBuiltin` deptrac layer restricted to `AppExtensionApi` dependency only.

### RegistryBackedToolbox Symfony events
**Resolved (EXT-HOOK-03 pivot):** `RegistryBackedToolbox` now dispatches Symfony AI lifecycle events (`ToolCallRequested`, `ToolCallArgumentsResolved`, `ToolCallSucceeded`, `ToolCallFailed`). No custom AgentCore interceptor was needed.

### ExtensionApi settings exposure
`ExtensionApiInterface` now provides `getSettings(string $key): array` and `getCwd(): string`. Extensions read config through the API without accessing `AppConfig` directly.

### Result hook mutability
Per-tool `ToolCallSucceeded`/`ToolCallFailed` events are readonly/observational. `ToolCallsExecuted` (batch-level, in `AgentProcessor`) supports `setResult()` but Hatfield doesn't use `AgentProcessor`. Mutable after-result hooks deferred until needed.

## Post-implementation: SafeGuard not blocking — root cause analysis (2026-05-30)

### Symptom
After all extension hook tasks (EXT-HOOK-01 through EXT-HOOK-05, SAFE-01, SAFE-02) were merged, SafeGuard did not block writes outside CWD. Agent successfully wrote to `~/claw/hello.md` without any interception.

### Root cause
**Extensions were loaded only in the main `agent` command process, not in Messenger worker processes.**

Hatfield's runtime architecture uses a controller pattern with multiple processes:

```
bin/console agent          (main TUI/headless process)
  └─ bin/console agent --controller   (controller event loop)
       ├─ messenger:consume run_control
       ├─ messenger:consume llm
       └─ messenger:consume tool       ← tool execution happens HERE
```

`ExtensionManager::loadExtensions()` was called only in `AgentCommand::__invoke()`. The `messenger:consume tool` worker processes have their own Symfony container and never call `loadExtensions()`. This means:

1. `ExtensionHookRegistry` is empty in tool workers
2. `ExtensionToolHookEventSubscriber` fires (it's registered) but finds zero hooks
3. Tool executes unguarded — SafeGuard never runs

### Additional issue: bin/console couldn't run FrameworkBundle commands

The old `bin/console` used `Symfony\Component\Console\Application` directly, and the old `Kernel` extended `DependencyInjection\AbstractKernel` + `KernelTrait` instead of `HttpKernel\Kernel`. FrameworkBundle commands like `cache:clear` call `$application->getKernel()` which didn't exist, producing:

```
Call to undefined method Symfony\Component\Console\Application::getKernel()
```

### Fixes applied (commit 4dc56474)

1. **Kernel extends `HttpKernel\Kernel`** — standard Symfony pattern. Provides `kernel.charset`, `kernel.default_locale`, and proper bundle integration. Keeps Hatfield-specific `getCacheDir()`, `getBuildDir()`, `getLogDir()`, CWD boot, and `getConfigDir()`.

2. **`bin/console` uses `FrameworkBundle\Console\Application`** — provides `getKernel()`, event dispatcher injection, and bundle command registration. `cache:clear` and other FrameworkBundle commands now work.

3. **`ExtensionLoaderSubscriber`** — new `EventSubscriberInterface` that listens to `ConsoleEvents::COMMAND` and calls `ExtensionManager::loadExtensions()`. Fires in **every** `bin/console` invocation: `agent`, `messenger:consume`, `cache:clear`, etc. Idempotent (`$loaded` flag) so `loadExtensions()` is called exactly once per process.

4. **Removed `loadExtensions()` from `AgentCommand`** — no longer needed since the subscriber handles it for all processes.

### Architecture lesson

When tool execution runs in a separate process (Messenger workers), any extension registration that happens in the main process is invisible to workers. Extension loading must happen at a lifecycle point shared by all processes — kernel boot, console event, or a dedicated compiler pass. The `ConsoleEvents::COMMAND` subscriber ensures extensions are loaded regardless of which `bin/console` command starts the process.

## Open questions

1. **Result hook mutability**: should result hooks be allowed to flip `isError`, or only content/details? Pi's contract includes `isError`, but previous Pi bridge caveat says error override was imperfect.

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
