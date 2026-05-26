# Toolbox Design Plan

## 1. How tools are wired in agent-core

Symfony AI still provides the integration contract, but Hatfield owns tool registration and execution policy.

```
[Built-in ToolProvider / Extension ToolRegistrationDTO / Dynamic tool source]
    └── ToolRegistry (permanent + dynamic definitions)
        └── RegistryBackedToolbox implements Symfony ToolboxInterface
            └── getTools() converts registry definitions → Symfony Tool[]
            └── DynamicToolDescriptionProcessor filters per-turn active names
            └── execute(ToolCall) invokes registered handler/reference
        └── ToolSetResolverInterface resolves toolsRef → ActiveToolSet
            └── provider schema names and execution allowlist come from same snapshot
```

**Key Symfony contract** (`ToolboxInterface`):

```php
interface ToolboxInterface
{
    /** @return Tool[] */
    public function getTools(): array;
    public function execute(ToolCall $toolCall): ToolResult;
}
```

**Symfony provider-schema model** (`Tool` DTO from symfony/ai-platform):

```php
new Tool(
    ExecutionReference $ref,  // class + method or adapter reference
    string $name,             // e.g. "read"
    string $description,      // provider-schema description
    ?array $parameters,       // JSON Schema supplied by Hatfield definition
);
```

**Hatfield tool definition model** (app-owned, introduced by TOOLS-R02, wired into Toolbox by TOOLS-R03):

```php
final readonly class ToolDefinitionDTO
{
    /**
     * @param array<string, mixed> $parametersJsonSchema
     * @param list<string> $promptGuidelines
     */
    public function __construct(
        public string $name,
        public string $description,          // provider-schema description
        public array $parametersJsonSchema,  // explicit JSON schema
        public mixed $handler,               // callable/object reference/adapter target
        public string $promptLine,           // one-line <available_tools> text
        public array $promptGuidelines = [], // <guidelines> bullets
    ) {}
}

interface HatfieldToolProviderInterface
{
    public function definition(): ToolDefinitionDTO;
}
```

Production built-ins should register through Hatfield definitions, not Symfony `#[AsTool]`, so descriptions, prompt summaries, guidelines, and future settings-driven metadata can be dynamic. Symfony `#[AsTool]` may remain for experiments/backward compatibility, but it is not the primary registration path.

---

## 2. Toolset design

### Read (`read_file`)

**Approach**: Pi-style reader with `cat -n` output, no image handling.

```
Schema:
  path: string        # Absolute or workspace-relative path
  offset?: integer    # 1-indexed line to start reading from
  limit?: integer     # Maximum number of lines to read

Output: ToolResult with content type "text"
  Success: line-prefixed text (cat -n format, original line numbers)
    "     1  import { bar } from './bar';"
    "     2  "
    "     3  function foo(x: number): number {"
  Continuation hint when truncated:
    "[Showing lines 1-2000 of 4832. Use offset=2001 to continue.]"

Implementation:
  - resolvePath() — expand ~, normalize, resolve against cwd
  - Shell out to cat -n + sed + head (no PHP formatting)
  - Full file:  cat -n "$path" | head -2000
  - Offset+limit: cat -n "$path" | sed -n '${offset},$(($offset+$limit-1))p'
  - Offset only:  cat -n "$path" | sed -n '${offset},$p'
  - Head truncate at 2000 lines / 50KB (whichever first) via OutputCap
  - Binary file detection: reject non-UTF-8, suggest bash/hexdump
  - Device path rejection: /dev/*, /proc/*/fd/*

Why shell out: cat -n is C-optimized, pipes are buffered, sub-ms for
source files. Original line numbers preserved because cat -n runs on
full file before sed slices. No LineFormatter.php needed.
```

### View Image (`view_image`)

**Approach**: Separate tool, Codex-style. Reads image, returns as attachment.

```
Schema:
  path: string

Output: ToolResult with content type "image"
  Success: image block with base64 + mimeType
  Error: not a recognized image format, file too large

Implementation:
  - Resolve path (same as read)
  - Detect MIME type via magic bytes (not extension)
    Supported: jpeg, png, gif, webp
  - Read binary buffer
  - Max dimensions/configurable size limit (TBD: 2000×2000? 5MB?)
  - Return as Symfony AI image content block
  - Symfony AI Content: [['type' => 'image', 'source' => ['type' => 'base64', 'data' => ..., 'media_type' => ...]]]

Note: Symfony AI handles image attachments — verify how content blocks map to provider-specific formats.
```

### Bash (`bash`)

**Approach**: Simple command execution with user-controlled backgrounding.

```
Schema:
  command: string     # Shell command
  timeout?: integer   # Timeout in seconds

Output: ToolResult with content type "text"
  stdout output
  Exit code appended on non-zero: "Exit code 1"
  Timeout: "Command timed out after Ns" with partial output
  Cancelled: "Command cancelled" with partial output and cancelled=true details
  Backgrounded: "Moved to background. PID: N, Log: /path/to/log"
  Abort: "Command aborted" with partial output

Implementation:
  - Use Symfony\Component\Process\Process
  - Command runs in configured shell (bash on Unix, fallback to sh)
  - cwd = project working directory
  - Output goes through OutputCap using settings-backed caps (default 20K chars)
  - Full output persisted to .hatfield/tmp/output-cap/ when truncated
  - Register the foreground process in ToolProcessRegistry while running
  - On timeout, ForegroundProcessRunner terminates the registered process tree/process group
  - On run cancellation, the controller terminates registered foreground process groups and Bash observes cancelled state
  - Cancellation is a structured result, not a generic tool failure

Background behavior (user-controlled, NOT model-controlled):
  - Model schema has NO run_in_background parameter
  - At the settings-backed background prompt threshold (default 30 seconds), TUI prompts user:
    "Command still running after 30s. Move to background?"
  - If user says yes:
    - Process unrefs, continues running
    - Output streams to .hatfield/tmp/bg/<session>-<pid>.log
    - Model receives: "Moved to background. PID: N, Log: <path>"
    - On completion: user gets notification, model can read log
  - If user says no:
    - Process keeps running until timeout (if set) or completion
  - If timeout is lower than the background prompt threshold and reached first: normal timeout kill

Companion tool:
  BgStatusTool — same as pi's bg_status
  Schema: { action: "list"|"log"|"stop", pid?: int }
  List: show all background processes with status
  Log:  tail of process log file
  Stop: SIGTERM the process

Shared service:
  BackgroundProcessManager — holds process map, log paths,
  session shutdown cleanup (kill all), stale file cleanup (>24h)

TODO later:
  - ANSI/binary output sanitization
```

Cancellation behavior:
  - Foreground bash is cancellable by run cancellation.
  - Background bash is not implicitly cancelled by turn cancellation; use `bg_status stop` unless a later policy says otherwise.
  - Process termination should be TERM -> grace period -> KILL.
  - Prefer process-group/session termination so child processes do not survive cancellation.

### Write (`write_file`)

**Approach**: Pi as-is. Dead simple `mkdir -p` + write.

```
Schema:
  path: string        # Absolute or workspace-relative path
  content: string     # Content to write

Output: ToolResult
  "Successfully wrote N bytes to <path>"

Implementation:
  - resolvePath() — same as read
  - mkdir(dirname(path), recursive: true)
  - file_put_contents(path, content) — UTF-8
  - Return success message with byte count

No:
  - Read-before-write enforcement (adds friction)
  - Diff generation (model can read + diff if it wants)
  - create/update discrimination (not needed)
  - Append mode (use edit or bash >> for that)
```

### Edit (`edit_file`)

**Approach**: Standard unified diff + GNU `patch` utility. Model produces a diff, we apply it.

```
Schema:
  path: string        # Absolute or workspace-relative path
  patch: string       # Unified diff in standard format

Output: ToolResult
  Success: "Applied patch to <path> (N additions, M deletions)"
  Failure: patch error message (model can correct and retry)
  No-op: "No changes (patch produced identical content)"

Implementation:
  - resolvePath() — same as read
  - File must exist (for existing-file edits; creation = use write)
  - Two-pass approach for safety:
    Pass 1 (validate):  patch -u -F3 -l -N --dry-run --posix -o /dev/null <target> <patch>
    Pass 2 (apply):     patch -u -F3 -l -N -o <temp-out> <target> <patch>
  - If dry-run fails: return patch stderr verbatim, original file untouched
  - If dry-run passes: apply, then diff old vs new for stats
  - Rename temp-out → target

Flags explained:
  -u         Unified diff format
  -F3        3 lines fuzz tolerance for line number drift
  -l         Ignore whitespace changes (handles most common model mistake)
  -N         Forward only — won't reverse-apply a patch
  --dry-run  Validate without mutating (pass 1)
  -o FILE    Output to temp file, never modify in-place
  --posix    Strict conformance, predictable behavior

Multiple hunks in one patch: standard diff behavior — patch
applies all hunks sequentially. Model can edit multiple
locations in one call.

File creation via patch: explicitly NOT supported. Model uses
write for new files. This keeps edit simple (always patch-applies
to existing file) and avoids edge cases.

Why standard unified diff over Codex's custom DSL:
  - Models are trained on unified diffs (git, PRs, code review)
  - No custom parsing → shell out to GNU patch (20 lines of PHP)
  - No fuzzy matching to implement → patch -F3 handles it
  - No streaming parser needed
```

---

## 3. Tool classes and definition sketch

Tool classes remain plain PHP services, but metadata comes from Hatfield definitions rather than attributes.

```php
// src/CodingAgent/Tool/ReadFileTool.php
final class ReadFileTool
{
    public function __invoke(string $path, ?int $offset = null, ?int $limit = null): ToolResult
    {
        /* ... */
    }
}

// src/CodingAgent/Tool/ReadFileToolProvider.php
final readonly class ReadFileToolProvider implements HatfieldToolProviderInterface
{
    public function __construct(private ReadFileTool $tool) {}

    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: 'read',
            description: 'Read file contents with cat -n line numbering',
            parametersJsonSchema: [/* explicit JSON schema */],
            handler: $this->tool,
            promptLine: 'read: Read text files with line numbers.',
            promptGuidelines: ['Use read before editing when you need exact file context.'],
        );
    }
}
```

Repeat the same pattern for `view_image`, `bash`, `bg_status`, `write`, and `edit`. A shared provider base/helper may be introduced if it keeps schemas concise, but avoid hiding model-visible names/descriptions in attributes.

---

## 4. Shared utilities

```
src/AgentCore/Contract/Tool/
  ToolExecutionContextInterface.php         # Generic current run/tool cancellation and timeout context
  ToolExecutionContextAccessorInterface.php # Ambient context contract used by ToolExecutor and app-owned tools
  ToolCancelledException.php                # Domain cancellation exception ToolExecutor can classify

src/AgentCore/Application/Tool/
  StackToolExecutionContextAccessor.php     # Stack-safe ambient context around Symfony Toolbox execution

src/CodingAgent/Tool/
  ToolDefinitionDTO.php       # Hatfield tool definition: schema, handler, prompt line, guidelines (TOOLS-R02)
  HatfieldToolProviderInterface.php # Built-in tool definition provider contract (TOOLS-R02)
  BuiltInToolRegistrar.php    # Registers tagged built-in definitions as permanent registry tools (TOOLS-R02)
  RegistryBackedToolbox.php   # Symfony ToolboxInterface adapter backed by ToolRegistry definitions (TOOLS-R03)
  ToolSettings.php            # Hydrated tool defaults/thresholds from Hatfield settings (TOOLS-R04)
  PathResolver.php            # Static helper: resolvePath(), expand ~, normalize, cwd-relative
  OutputCap.php              # Output capping + temp file persistence
  PatchRunner.php            # Wraps GNU patch subprocess via ForegroundProcessRunner
  CancellationGuard.php       # Cooperative checkpoint helper for short tools, using AgentCore context contracts
  ToolProcessRegistry.php     # Cross-process foreground/background PID registry
  ToolProcessTerminator.php   # TERM -> grace -> KILL process/group termination helper
  ForegroundProcessRunner.php # Shared process starter/waiter with registry + timeout support
  ProcessSpec.php             # Command/cwd/env/timeout/process-group process request DTO
  ProcessRunResult.php        # stdout/stderr/exit/cancel/timeout/duration result DTO
  BackgroundProcessManager.php  # Holds bg process map, log paths, cleanup
```

Image MIME detection:
  Use League\MimeTypeDetection\FinfoMimeTypeDetector (already in project
  via Flysystem). Magic bytes via PHP finfo, no custom code needed.
  Supported: image/jpeg, image/png, image/gif, image/webp.

### Output capping (OutputCap)

Based on the pi-mono `output-cap` extension. Applied to all tools that
produce large text output (read, bash, grep, find).

```
Settings-backed defaults:
  tools.output_cap.max_chars = 20_000       # ~5000 tokens (code files)
  tools.output_cap.max_doc_chars = 50_000   # ~12500 tokens (docs: .md, .txt, etc.)
  tools.output_cap.retention_seconds = 86400 # 24 hours — stale file cleanup threshold

Storage:
  .hatfield/tmp/output-cap/<session-prefix>-<random-hex>.txt

Flow:
  1. Tool produces text output
  2. If output > settings max_chars (or max_doc_chars for doc files):
     a. Save full output to .hatfield/tmp/output-cap/
     b. Return capped notice to model:
        "⛔ Output capped: N chars (~M tokens) exceeds 20000 char limit.
         Full output saved to: <path>
         Use `head -50 <path>` or `grep <pattern> <path>` to inspect."
  3. If output <= cap: return as-is

Cleanup:
  - On session start: delete all files in .hatfield/tmp/output-cap/ older than 24 hours
  - On session shutdown: delete files matching this session's prefix
  - .hatfield/tmp/ is gitignored (already in .hatfield/.gitignore)

Read tool integration:
  - cat -n output goes through OutputCap
  - Truncation hint appended: "[Showing lines 1-N of M. Use offset=N+1 to continue.]"
  - If also capped: both messages shown

Bash tool integration:
  - Full output always saved to temp file via OutputCap
  - Inline output is last N chars (tail) up to cap limit
  - Model gets temp file path for grep/head access
```

---

## 5. What we DON'T need (from other codebases)

| Feature | From | Why skip |
|---------|------|----------|
| Read-before-write enforcement | Claude | Adds friction; model naturally reads before editing |
| Silent fuzzy matching (NFKC, whitespace) | Pi | Confuses models; exact + `patch -F3` is better |
| Image-in-read auto-detection | Pi | Separate view_image is cleaner separation |
| Sandbox integration | Claude | Defer; use Symfony Process + cwd scoping for now |
| Model-controlled run_in_background parameter | Claude | Backgrounding is user-controlled via TUI prompt, not model-controlled |
| Custom patch DSL (***Begin Patch) | Codex | Standard unified diff is simpler + model-native |
| Streaming patch parser | Codex | Not needed — whole diff sent at once |
| LSP integration | Claude | Out of scope for initial implementation |
| Dedup logic | Claude | Nice optimization, defer |
| PDF/notebook support | Claude | Out of scope |

---

## 6. System prompt and ToolRegistry design

Current code reality:

- `config/SYSTEM.md` is the intended built-in default system prompt template. It is XML-ish text, not Markdown that needs Markdown processing.
- `StartRunInput::$systemPrompt` and `StartRunPayload::$systemPrompt` exist, but `InProcessAgentSessionClient` currently passes an empty system prompt.
- `StartRunHandler` stores only the user/assistant/tool `messages` list in `RunState`; the payload `systemPrompt` is only visible in the normalized `run_started` event payload.
- `AgentMessageConverter` already supports `role: system`, so the lowest-risk integration path is to prepend the assembled system prompt as an `AgentMessage(role: 'system', ...)` before the first user message.
- `DynamicToolDescriptionProcessor` currently sends all Symfony Toolbox tools to the provider by default, with optional filtering when `Input` option `tools` is a string list; TOOLS-R00 added a resolver path for `toolsRef` filtering.
- `ToolExecutor` executes by tool name through `FaultTolerantToolbox`; TOOLS-R03 must wire a real `ToolboxInterface` service via `RegistryBackedToolbox` and enforce the same active snapshot's execution allowlist.
- `ToolRegistry` now exists with permanent/dynamic buckets, but TOOLS-R02 must add tool definitions and `HatfieldToolProviderInterface`, and TOOLS-R03 must add registry-backed Symfony Toolbox execution so registry-only extension/dynamic tools are actually callable.

Scout findings to reuse:

- Symfony AI has `SystemMessage(string|Template)`, `Message::forSystem()`, `Template::string()`, `StringTemplateRenderer`, `TemplateRendererListener`, and `MessageBag::withSystemMessage()`.
- Symfony AI `SystemPromptInputProcessor` is useful as a pattern, but should not be used as-is for Hatfield tool prompt assembly because it appends Markdown `# Tools` text from Symfony `Toolbox` descriptions. Hatfield needs the exact `config/SYSTEM.md` XML-ish shape and registry-provided tool snippets/guidelines.
- Pi keeps rich tool metadata at the coding-agent layer (`promptSnippet`, `promptGuidelines`) and wraps tools into a lean runtime execution contract. Its system prompt builder renders an available-tools section and a deduped guidelines section from active registered tools, then appends dynamic context.

### System prompt template resolution

Built-in default template: `config/SYSTEM.md`.

User/project replacement templates:

1. If `{cwd}/.hatfield/SYSTEM.md` exists, it replaces the built-in system prompt completely.
2. Else if `~/.hatfield/SYSTEM.md` exists, it replaces the built-in system prompt completely.
3. Else use `config/SYSTEM.md`.

Append templates:

1. Load `~/.hatfield/APPEND_SYSTEM.md` if it exists.
2. Load `{cwd}/.hatfield/APPEND_SYSTEM.md` if it exists.
3. Concatenate append templates in that order with a blank-line separator.
4. Render the concatenated append content with the same template renderer and variables, except `{%appends_part%}` is treated as empty to avoid recursion.
5. Render the result into the base template at `{%appends_part%}`.

Template placeholders in `config/SYSTEM.md` and replacement `SYSTEM.md` files:

- `{%available_tools_list%}` → permanent registered tool summaries, one stable deduped line per tool, e.g. `- read: Read file contents`.
- `{%registered_guidelines%}` → stable deduped guidelines from permanent registered tools.
- `{%appends_part%}` → resolved append template contents, or empty string.
- `{%date%}` → current date.
- `{%cwd%}` → current working directory.

Rendering implementation:

- Render built-in, home override, project override `SYSTEM.md`, and append prompt files through the exact same template renderer and variable map.
- User-provided `SYSTEM.md` and `APPEND_SYSTEM.md` files may use any subset of the supported placeholders above; they must not be treated as already-rendered raw text.
- Prefer reusing Symfony AI's `StringTemplateRenderer`/`TemplateRendererRegistry` rather than inventing a new templating engine. The variable keys can match the current placeholders exactly: `%available_tools_list%`, `%registered_guidelines%`, `%appends_part%`, `%date%`, and `%cwd%`.
- If direct reuse is awkward, a tiny deterministic renderer limited to the placeholders above is acceptable. Do not introduce Twig or Markdown processing for the system prompt.
- The final rendered system prompt should be injected once per run/turn as the first `system` message before user content.

### AGENTS.md project context discovery

Hatfield should support repository instruction files as model-visible context, but **not** by splicing them into `SYSTEM.md` or the rendered system prompt. They are conversational context loaded only for new sessions.

Discovery intentionally mirrors Pi's path behavior, but not its `CLAUDE.md` compatibility:

- Supported filenames: `AGENTS.md`, `AGENTS.MD` only, checked in that order per directory. First match wins for a directory.
- Global file: check the Hatfield user config directory first, i.e. `~/.hatfield/AGENTS.md` or `~/.hatfield/AGENTS.MD`.
- Project files: walk upward from `{cwd}` to filesystem root, checking each directory. Do not scan downward.
- Ordering: global file first, then discovered project/ancestor files nearest-to-farthest from `{cwd}`.
- Deduplicate by resolved absolute path.
- Add a no-context-files option/setting later only if needed; initial implementation can always load when present.

Injection behavior:

- On a new session only, inject one synthetic context message before the first real user message.
- Do not inject on session resume/replay if the session already has canonical messages, to avoid duplicate context.
- The message should be model-visible user context, not `role: system`; use a custom `AgentMessage` role such as `user-context` if practical, or a normal user-role message with metadata marking it as project context.
- Render content as XML-ish text:

```xml
<project_context>
Project-specific instructions and guidelines:

<project_instructions path="/absolute/path/AGENTS.md">
...
</project_instructions>
</project_context>
```

- If multiple files are loaded, include multiple `<project_instructions path="...">` blocks in the single context message in discovery order.
- `config/SYSTEM.md` should only describe this context channel; it should not contain a `{%project_context%}` placeholder.

### Skills registry, discovery, and preload context

Hatfield should support Pi-style skills as model-visible context, but like AGENTS.md this must **not** be spliced into the rendered system prompt. Skills are exposed in the same synthetic initial user-context message that SYSTEM-02 creates, before the first real user message and only for new sessions.

Skill file format:

- A skill root is a directory containing `SKILL.md`.
- `SKILL.md` uses YAML frontmatter followed by Markdown/body instructions.
- `description` is required for registry visibility.
- `name` defaults to the parent directory name when missing.
- Optional `disable-model-invocation: true` keeps the skill available for explicit preload but excludes it from `<available_skills>`.
- When preloading a skill body, strip frontmatter and preserve the remaining body verbatim inside a `<skill>` block.

Discovery and precedence:

1. CLI `--skills-path <path>` entries have highest priority. Each path may be a skill root containing `SKILL.md` or a directory recursively containing skill roots.
2. Auto-discover skills from `{cwd}/.hatfield/skills`.
3. Auto-discover skills from `{cwd}/.agents/skills`.
4. Auto-discover skills from `~/.hatfield/skills`.
5. Auto-discover skills from `~/.agents/skills`.
6. Later extension/package/settings-provided skill paths can be appended through the same loader unless a future priority flag is introduced.

First wins on skill-name collision. Collisions should be recorded as startup diagnostics with the winning path and ignored path, not silently discarded.

CLI flags:

- `--no-skills` disables auto-discovery, but does not disable explicit `--skills-path` entries.
- `--skills-path <path>` is repeatable and defines highest-priority skill search roots/skill files.
- `--skills <name>` is repeatable and preloads the resolved skill body into the initial user-context message. A comma-separated value may be accepted as a convenience, but repeatable flags are the semantic model.

Initial user-context message ordering:

1. `<project_context>` from SYSTEM-02 AGENTS.md discovery, when present.
2. `<skills_instructions>` and `<available_skills>` registry summary, when any model-invocable skills are registered.
3. Preloaded `<skill name="..." location="...">` bodies requested by `--skills`, in CLI order.

Skills instruction block content should be stable and close to:

```xml
<skills_instructions>
The following skills provide specialized instructions for specific tasks.
Use the read tool to load a skill's file when the task matches its description.
When a skill file references a relative path, resolve it against the skill directory (parent of SKILL.md / dirname of the path) and use that absolute path in tool commands.

<available_skills>
  <skill>
    <name>castor</name>
    <description>Runs and discovers project tasks via Castor...</description>
    <location>/absolute/path/to/SKILL.md</location>
  </skill>
</available_skills>
</skills_instructions>
```

Preloaded skill body format:

```xml
<skill name="castor" location="/absolute/path/to/SKILL.md">
References are relative to /absolute/path/to/skill-directory.

...frontmatter-stripped skill body...
</skill>
```

TUI surfacing:

- Startup/header/status area should show a stable skills line below the logo or in the existing status area, e.g. `skills   skill:castor  skill:subagents`.
- Startup diagnostics should expose where skills were loaded from and any collisions.
- Rich transcript rendering should recognize preloaded `<skill ...>` blocks as skill/context blocks rather than ordinary read-tool output.

### ToolRegistry model

CodingAgent owns `ToolRegistry`; AgentCore and TUI must not depend on CodingAgent registry internals.

The registry has exactly two model-callable tool buckets:

1. **Permanent tools**
   - Registered through `registerTool()`.
   - Active by default.
   - Included in provider schemas unless disabled by active-set policy.
   - Included in the system prompt via:
     - one small description line for `<available_tools>`;
     - zero or more guideline strings for `<guidelines>`.
   - Available-tool lines and guideline strings must be deduped while preserving deterministic registration order.

2. **Dynamic tools**
   - Stored separately, e.g. `ToolRegistry::$dynamicTools`.
   - Managed with `addDynamicTool()`, `removeDynamicTool()`, `setDynamicTools()`, and `getDynamicTools()` methods.
   - May be added/removed per request/turn through CodingAgent registry APIs and resolved by AgentCore's per-turn `toolsRef`/toolset resolution path.
   - Included in provider schemas and execution allowlists when present in the active request snapshot.
   - Never included in the stable system prompt and have no `<available_tools>`/`<guidelines>` record.

Registry responsibilities:

- Be the source of truth for model-callable tool definitions. Symfony Toolbox is an adapter boundary, not the source of tool metadata.
- Maintain deterministic permanent and dynamic tool maps keyed by model-visible tool name.
- Store enough definition data for both provider schemas and execution: name, provider description, explicit JSON schema, handler/reference, prompt line, and prompt guidelines.
- Expose read-only definition lookup/snapshot methods needed by `RegistryBackedToolbox` (for example `activeToolDefinitions()` and `toolDefinition($name)`), without exposing mutable registry internals.
- Reject conflicting duplicate tool names deterministically; treat identical re-registration as idempotent.
- Produce snapshots for:
  - permanent prompt lines;
  - permanent prompt guidelines;
  - active provider-schema tools = permanent active tools + current dynamic tools;
  - active execution allowlist using the same names as the provider schema snapshot.
- Let extension tools register as permanent tools unless an explicit dynamic registration path is introduced later.
- Ensure registry-only dynamic/extension tools never appear in provider schemas unless `RegistryBackedToolbox` can execute their handler.

### Tool settings

Tool defaults and thresholds should live in Hatfield settings rather than hard-coded service arguments, so users/projects can tune behavior without code changes.

Proposed settings shape (exact names can be refined during implementation):

```yaml
tools:
    execution:
        default_timeout_seconds: 300
        max_parallelism: 4
    process:
        termination_grace_seconds: 2
    bash:
        default_timeout_seconds: 300
        background_prompt_after_seconds: 30
    output_cap:
        max_chars: 20000
        max_doc_chars: 50000
        retention_seconds: 86400
    view_image:
        max_bytes: 5242880
        max_width: 2000
        max_height: 2000
```

Implementation notes:

- Hydrate these into an app-owned `ToolSettings`/`ToolSettingsDTO` from `AppConfig::raw['tools']` with safe defaults (TOOLS-R04).
- Existing service arguments such as `ToolExecutor` timeout/max parallelism should be wired from settings-derived services/factories instead of fixed YAML literals (TOOLS-R04).
- Tasks that introduce or consume a key must update `.hatfield/settings.yaml` comments and `docs/settings.md` together.
- User-provided tool-call parameters (for example Bash `timeout`) may override settings within safe bounds, but schema should stay minimal and no policy-only knobs should be model-visible unless intentionally designed.

### AgentCore per-turn toolset resolution

AgentCore already has two useful pieces for per-request dynamic tools:

- `BeforeProviderRequestHookInterface` for late provider request mutation. This is useful for provider/model quirks, but it fires after `LlmPlatformAdapter` has already run `DynamicToolDescriptionProcessor`, so it is too late to be the primary dynamic-tool selector.
- `toolsRef` already exists on `ExecuteLlmStep`/`ModelInvocationInput` and is generated per turn by `AdvanceRunHandler` as `toolset:run:{runId}:turn:{turnNo}`. It is currently not resolved by `LlmPlatformAdapter` or `DynamicToolDescriptionProcessor`.

Use this existing `toolsRef` path rather than adding a separate dynamic-tool hook.

Required design:

1. Add an AgentCore-owned semantic interface such as `ToolSetResolverInterface` that resolves a per-turn tool reference into active toolset data. It must not depend on CodingAgent registry classes.
2. The resolved data must include at least the provider-visible tool names and execution allowlist names. A small DTO/value object is preferred over raw arrays.
3. `LlmPlatformAdapter` passes `ModelInvocationInput::$toolsRef`, `runId`, and `turnNo` into `Input` options before `DynamicToolDescriptionProcessor::processInput()` runs.
4. `DynamicToolDescriptionProcessor` uses `ToolSetResolverInterface` when a `toolsRef` option is present, then filters/constructs `options['tools']` from that resolved active toolset. It can keep the current fallback to all registry-backed Toolbox tools when no resolver/ref is available.
5. CodingAgent provides the concrete resolver implementation that maps AgentCore `toolsRef` to the CodingAgent `ToolRegistry` active snapshot, including current dynamic tools.
6. Propagate the `toolsRef`/run/turn identity into tool execution messages so execution can validate against the same turn snapshot used for provider schema exposure.
7. Tool execution rejects any tool name not present in the same resolved snapshot's execution allowlist.

This keeps AgentCore generic: AgentCore owns `toolsRef`, `ToolSetResolverInterface`, and option propagation; CodingAgent owns registry policy and the concrete resolver.

### Registration/runtime flow

1. Built-in tools are normal Symfony services, paired with Hatfield tool definition providers (tagged/autoconfigured) rather than relying on `#[AsTool]` metadata.
2. CodingAgent boot registers built-in permanent tools through a `BuiltInToolRegistrar` with explicit metadata: name, provider description, JSON schema, handler/reference, small prompt line, guideline strings.
3. Extension loader calls `ExtensionApiInterface::registerTool()`; EXT-02 maps public `ToolRegistrationDTO` into permanent registry entries with executable handlers.
4. Dynamic tools use `ToolRegistry` dynamic APIs; they never affect the stable system prompt but must use the same executable definition shape.
5. `RegistryBackedToolbox` is wired as `Symfony\AI\Agent\Toolbox\ToolboxInterface`; it converts registry definitions to Symfony `Tool` DTOs and executes registry handlers.
6. At run start, `SystemPromptService` resolves `SYSTEM.md`, resolves append templates, asks `ToolRegistry` for permanent prompt metadata, renders the final prompt, and prepends it as a system `AgentMessage` before the user prompt.
7. Before each provider request, AgentCore passes the per-turn `toolsRef` through `LlmPlatformAdapter`; `ToolSetResolverInterface` resolves it to an active tool snapshot supplied by CodingAgent's concrete resolver.
8. Provider tool schemas and execution allowlist are derived from that same resolved snapshot; `ToolExecutor` rejects tool calls outside the allowlist before invoking the toolbox.

---

## 7. Implementation task breakdown

Tasks are intentionally small and prefixed with `TOOLS-` so smaller models can implement them safely. Each task should include tests and use Castor for validation (`castor test` for focused tests, `castor check` when practical).

### Tasks

- **SYSTEM-01** — System prompt template resolution, append prompts, rendering, and run-start injection.
  - Depends on: TOOLS-R00 for permanent tool prompt metadata.
  - Can parallelize with: concrete tool implementation after TOOLS-R02 definition/registry-backed toolbox conventions are stable.

- **SYSTEM-02** — `AGENTS.md` project context discovery and new-session context message injection.
  - Depends on: none; can reuse SYSTEM-01 prompt/channel wording when available, but must not splice AGENTS content into the system prompt.
  - Can parallelize with: SYSTEM-01, TOOLS-R00/TOOLS-R02 foundation work, and concrete tool implementation after TOOLS-R02 conventions are stable.

- **SYSTEM-03** — Skills registry/discovery, `--skills-path`/`--skills`/`--no-skills`, and new-session skills context injection.
  - Depends on: SYSTEM-02 for the shared initial user-context message placement/order.
  - Can parallelize with: concrete tool implementation after the context-message boundary is stable.

- **TOOLS-R00** — CodingAgent `ToolRegistry` with permanent tools, dynamic tools, snapshots, and active allowlists.
  - Depends on: none.
  - Can parallelize with: TOOLS-00, TOOLS-01, TOOLS-02, EXT-00.

- **TOOLS-R02** — Tool definitions, `HatfieldToolProviderInterface`, `BuiltInToolRegistrar`, and registry definition lookups.
  - Depends on: TOOLS-R00.
  - Can parallelize with: TOOLS-00, TOOLS-01, TOOLS-02 after TOOLS-R00 has landed. Concrete tool tasks can start registering definitions after this lands.

- **TOOLS-R03** — Registry-backed Symfony Toolbox and execution allowlist enforcement.
  - Depends on: TOOLS-R02.
  - Can parallelize with: TOOLS-00, TOOLS-01, TOOLS-02, TOOLS-R04.

- **TOOLS-R04** — Tool settings hydration from Hatfield settings/defaults.
  - Depends on: TOOLS-R02 (so settings-derived registration defaults are available).
  - Can parallelize with: TOOLS-R03, TOOLS-00, TOOLS-01, TOOLS-02.

- **TOOLS-00** — Tool cancellation context, guard, foreground process registry, terminator, and runner.
  - Depends on: none.
  - Can parallelize with: TOOLS-R00, TOOLS-R02, TOOLS-R03, TOOLS-01, TOOLS-02.

- **TOOLS-01** — Static `PathResolver` helper for file tools.
  - Depends on: none.
  - Can parallelize with: TOOLS-R00, TOOLS-R02, TOOLS-R03, TOOLS-00, TOOLS-02.

- **TOOLS-02** — `OutputCap` service for text output persistence and cleanup.
  - Depends on: none.
  - Can parallelize with: TOOLS-R00, TOOLS-R02, TOOLS-R03, TOOLS-00, TOOLS-01.

- **TOOLS-03** — Simple `write` tool.
  - Depends on: TOOLS-R02, TOOLS-R03, TOOLS-00, TOOLS-01.
  - Can parallelize with: TOOLS-04, TOOLS-05, TOOLS-07, TOOLS-08, SYSTEM-01.

- **TOOLS-04** — `view_image` tool using `FinfoMimeTypeDetector`.
  - Depends on: TOOLS-R02, TOOLS-R03, TOOLS-00, TOOLS-01.
  - Can parallelize with: TOOLS-03, TOOLS-05, TOOLS-07, TOOLS-08, SYSTEM-01.

- **TOOLS-05** — `PatchRunner` utility wrapping GNU `patch` dry-run/apply.
  - Depends on: TOOLS-00.
  - Can parallelize with: TOOLS-03, TOOLS-04, TOOLS-08, SYSTEM-01.

- **TOOLS-06** — `edit` tool using `PatchRunner`.
  - Depends on: TOOLS-R02, TOOLS-R03, TOOLS-00, TOOLS-01, TOOLS-05.
  - Can parallelize with: TOOLS-07, TOOLS-08, SYSTEM-01.

- **TOOLS-07** — `read` tool using `cat -n`, `sed`, `head`, and `OutputCap`.
  - Depends on: TOOLS-R02, TOOLS-R03, TOOLS-00, TOOLS-01, TOOLS-02.
  - Can parallelize with: TOOLS-06, TOOLS-08, SYSTEM-01.

- **TOOLS-08** — `BackgroundProcessManager` and `bg_status` companion tool.
  - Depends on: TOOLS-R02, TOOLS-R03, TOOLS-00.
  - Can parallelize with: TOOLS-03, TOOLS-04, TOOLS-05, TOOLS-06, TOOLS-07, SYSTEM-01.

- **TOOLS-09** — `bash` tool with OutputCap and user-controlled background prompt.
  - Depends on: TOOLS-R02, TOOLS-R03, TOOLS-00, TOOLS-02, TOOLS-08.
  - Can parallelize with: SYSTEM-01 and early TOOLS-10 prep after schemas stabilize.

- **TOOLS-10** — Prompt/docs integration for final toolset.
  - Depends on: TOOLS-R00, TOOLS-R02, TOOLS-R03, TOOLS-R04, SYSTEM-01, SYSTEM-02, SYSTEM-03, TOOLS-03, TOOLS-04, TOOLS-06, TOOLS-07, TOOLS-08, TOOLS-09.
  - Can parallelize with: none; it should close the loop after final names/schemas/context/skills behavior stabilize.

### Dependency waves

1. **Registry and utility foundation:** TOOLS-R00, TOOLS-00, TOOLS-01, and TOOLS-02 can start immediately and in parallel.
2. **Definition foundation:** TOOLS-R02 starts after TOOLS-R00. Concrete tool tasks can register definitions (HatfieldToolProviderInterface) after this lands, even before the Toolbox is wired.
3. **Toolbox + settings foundation:** TOOLS-R03 (registry-backed Toolbox + allowlist) and TOOLS-R04 (settings hydration) can run in parallel after TOOLS-R02. Concrete tools need TOOLS-R03 for actual execution through the Symfony AI pipeline.
4. **Prompt/context foundation:** SYSTEM-01 starts after TOOLS-R00 snapshot APIs are stable. SYSTEM-02 can start independently because AGENTS.md context is a separate first-message channel, not a system prompt placeholder. SYSTEM-03 follows SYSTEM-02 so skills share the same first-message injection boundary.
5. **Process/background foundation:** TOOLS-05 depends on TOOLS-00 and can run after it lands. TOOLS-08 depends on TOOLS-R02 + TOOLS-R03 + TOOLS-00.
6. **Independent tools:** TOOLS-03 and TOOLS-04 depend on TOOLS-R02 + TOOLS-R03 + TOOLS-00 + TOOLS-01 and can run in parallel after those land.
7. **Patch/read tools:** TOOLS-06 depends on TOOLS-R02 + TOOLS-R03 + TOOLS-00 + TOOLS-01 + TOOLS-05. TOOLS-07 depends on TOOLS-R02 + TOOLS-R03 + TOOLS-00 + TOOLS-01 + TOOLS-02. They can run in parallel with each other.
8. **Bash:** TOOLS-09 depends on TOOLS-R02 + TOOLS-R03 + TOOLS-00 + TOOLS-02 + TOOLS-08.
9. **Prompt/docs:** TOOLS-10 should be last so prompts match the final registered tool names, schemas, background tool behavior, AGENTS.md context behavior, and skills behavior.

### Notes for implementers

- Do not implement `GrepTool` or `FindTool` in this rollout.
- `ToolRegistry` is implemented by TOOLS-R00 and owns permanent/dynamic tool policy and metadata. TOOLS-R02 owns tool definitions and the built-in registrar. TOOLS-R03 owns the registry-backed Toolbox adapter and execution allowlist. TOOLS-R04 owns settings hydration.
- Concrete tools should provide Hatfield tool definitions through TOOLS-R02 conventions; do not add new production reliance on Symfony `#[AsTool]` metadata.
- Do not add model-controlled `run_in_background`; backgrounding is controlled by the TUI/user prompt.
- Prefer Symfony DI/autowiring and tagged providers for built-in tool definitions.
- Tool timeouts, process thresholds, output caps, and image limits should come from Hatfield settings/defaults, not hard-coded service arguments.
- Runtime files belong under `.hatfield/tmp/` so they remain ignored by `.hatfield/.gitignore`.

---

## 8. Cancellation-aware tool execution

AgentCore already models run cancellation:

- `AgentSessionClient::cancel(string $runId)`
- `AgentRunnerInterface::cancel()`
- `CoreCommandKind::Cancel`
- `RunStatus::Cancelling` / `Cancelled`
- `RunCancellationToken` polling `RunStore`
- `LlmPlatformAdapter` aborting streamed model consumption when cancellation is requested

Tool execution should build on that existing run-level cancellation and the async-runtime cancel ladder. Do not introduce model-visible cancellation parameters in tool schemas.

### Key decision

Tools owned by CodingAgent can remain Symfony AI toolbox tools and still be cancellable.

There are two cancellation paths:

1. **Cooperative PHP checkpoints** for short app-owned tools.
2. **Runtime-owned process termination** for foreground shell/subprocess tools. The controller reacts to accepted cancellation and kills registered foreground tool process groups; individual tools do not own their own cancellation polling loops.

Killing the Messenger `tool` consumer is a future hard-cancel fallback, not the primary foreground-tool cancellation mechanism. It is coarser, may interact with message retry semantics, and may not kill grandchildren spawned by bash.

### ToolExecutionContextAccessor

Keep `ToolboxInterface` as the low-level provider/execution adapter, but make it registry-backed and add an ambient execution context around each toolbox invocation.

Layering requirement: `ToolExecutor` lives in `AgentCore`, so the context contract/accessor it uses must be AgentCore-owned. CodingAgent tools may depend on AgentCore contracts, but AgentCore must not import `Ineersa\CodingAgent\Tool\*`.

```php
interface ToolExecutionContextInterface
{
    public function runId(): string;
    public function turnNo(): int;
    public function toolCallId(): string;
    public function toolName(): string;
    public function cancellationToken(): CancellationTokenInterface;
    public function timeoutSeconds(): int;
    public function throwIfCancellationRequested(): void;
}

interface ToolExecutionContextAccessorInterface
{
    public function current(): ?ToolExecutionContextInterface;
    public function requireCurrent(): ToolExecutionContextInterface;
    public function with(ToolExecutionContextInterface $context, callable $callback): mixed;
}

final class StackToolExecutionContextAccessor implements ToolExecutionContextAccessorInterface
{
    /** @var list<ToolExecutionContextInterface> */
    private array $stack = [];

    public function current(): ?ToolExecutionContextInterface
    {
        return $this->stack[array_key_last($this->stack)] ?? null;
    }

    public function requireCurrent(): ToolExecutionContextInterface
    {
        return $this->current()
            ?? throw new \LogicException('A tool execution context is required.');
    }

    public function with(ToolExecutionContextInterface $context, callable $callback): mixed
    {
        $this->stack[] = $context;

        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }
}
```

`ToolExecutor` creates the context from the current `ToolCall`/`ExecuteToolCall` message and wraps Symfony Toolbox execution:

```php
return $this->contextAccessor->with(
    $context,
    fn () => $this->faultTolerantToolbox->execute(new SymfonyToolCall(...)),
);
```

Tool services that need run/tool metadata inject `ToolExecutionContextAccessorInterface` and call `requireCurrent()`.

This keeps cancellation out of the model-facing schema while letting app-owned tools access run/tool metadata and the existing cancellation token.

### Concurrency note

The accessor stack is acceptable for synchronous CLI/Messenger tool execution. If future in-process parallel/fiber execution is introduced, replace the simple stack with an operation-id/fiber-local context strategy or use a native execution registry that passes context explicitly.

### Cooperative cancellation helpers

Use `CancellationGuard` for cheap checkpoints in non-process tools.

```php
final readonly class CancellationGuard
{
    public function checkpoint(ToolExecutionContextInterface $context): void
    {
        if ($context->cancellationToken()->isCancellationRequested()) {
            throw new ToolCancelledException();
        }
    }
}
```

Short tools (`read` setup, `write`, `view_image`, `edit` validation steps) call checkpoints before starting and at safe boundaries. They should not each own custom cancellation loops. `ToolCancelledException` should be AgentCore-owned so `ToolExecutor` can classify it as a structured `cancelled=true` tool result instead of a generic tool failure.

### Foreground process tracking and termination

TOOLS-00 should introduce shared process primitives that foreground tools and future background tooling can reuse.

Core services/value objects:

```php
enum ToolProcessKindEnum: string
{
    case ForegroundTool = 'foreground_tool';
    case BackgroundTool = 'background_tool';
}

final readonly class ToolProcessRecordDTO
{
    public function __construct(
        public string $runId,
        public int $turnNo,
        public string $toolCallId,
        public ToolProcessKindEnum $kind,
        public int $pid,
        public ?int $processGroupId,
        public string $commandPreview,
        public string $cwd,
        public ?string $logPath,
        public \DateTimeImmutable $startedAt,
    ) {}
}

interface ToolProcessRegistry
{
    public function register(ToolProcessRecordDTO $record): void;
    public function unregister(string $runId, string $toolCallId): void;

    /** @return list<ToolProcessRecordDTO> */
    public function foregroundForRun(string $runId): array;
}
```

`ToolProcessRegistry` must be cross-process, because the controller process must be able to see PIDs registered by the Messenger `tool` consumer. Use a locked JSON/JSONL store under `.hatfield/tmp/` or another project-local ignored runtime location; do not rely on in-memory state only.

`ToolProcessTerminator` owns the termination ladder for a registered process:

- prefer process-group termination on Unix (`posix_kill(-$pgid, SIGTERM)` then `SIGKILL` after the settings-backed grace period);
- fallback to direct process PID termination;
- treat missing/exited processes as already stopped;
- keep Windows support as a deferred/simple fallback unless already easy through Symfony Process/taskkill.

### ForegroundProcessRunner

`ForegroundProcessRunner` replaces the previous monolithic `CancellableProcessRunner` idea. It should start a subprocess, register its PID/process group, wait for completion, and unregister in `finally`.

Example contracts:

```php
final readonly class ProcessSpec
{
    /** @param list<string> $command */
    public function __construct(
        public array $command,
        public string $cwd,
        public array $env = [],
        public ?int $timeoutSeconds = null,
        public bool $createProcessGroup = true,
        public ?string $commandPreview = null,
    ) {}
}

final readonly class ProcessRunResult
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public ?int $exitCode,
        public bool $cancelled,
        public bool $timedOut,
        public ?string $outputPath,
        public int $durationMs,
    ) {}
}
```

`ForegroundProcessRunner` owns:

- starting `Symfony\Component\Process\Process`;
- creating a process group/session where practical (for bash/process-tree safety);
- registering `ToolProcessKindEnum::ForegroundTool` in `ToolProcessRegistry` once PID/PGID are known;
- output capture hooks, with OutputCap/log persistence supplied by later tasks;
- an observer/decision hook so Bash can ask the TUI/user at the settings-backed threshold and request `Continue`, `Terminate`, or `DetachToBackground` without duplicating process lifecycle code;
- timeout enforcement by delegating termination to `ToolProcessTerminator`;
- detecting cancellation after process exit by checking the context token/run state and returning `cancelled=true` rather than a generic failure;
- unregistering in `finally` unless ownership is explicitly transferred to `BackgroundProcessManager` via `DetachToBackground`.

It should not be the primary cancellation signal loop. On user cancellation, the controller/runtime side terminates registered foreground processes; the runner merely observes that the process ended under a cancelled token.

### Bash cancellation contract

Bash is the first tool that must support true mid-execution interruption, but it should not own low-level cancellation polling or process-tree kill logic. Bash should build a `ProcessSpec`, call `ForegroundProcessRunner`, and format the `ProcessRunResult` into a `ToolResult`.

Sketch:

```php
public function __invoke(string $command, ?int $timeout = null): ToolResult
{
    $context = $this->contexts->requireCurrent();

    $result = $this->processRunner->run(
        ProcessSpec::shell($command, $this->cwd, timeoutSeconds: $timeout),
        $context,
    );

    return $this->formatter->toToolResult($result);
}
```

Structured cancellation details from bash should include:

```php
[
    'cancelled' => true,
    'reason' => 'run_cancelled',
    'tool_call_id' => $context->toolCallId(),
    'duration_ms' => $result->durationMs,
    'partial_output_path' => $result->outputPath,
]
```

### Controller cancellation hook

When a cancel command is accepted and projected as `cancellation.requested`, the controller/runtime process should:

1. query `ToolProcessRegistry::foregroundForRun($runId)`;
2. call `ToolProcessTerminator` for each foreground record;
3. leave background processes alone unless explicitly stopped through `bg_status` or future policy;
4. let the existing `ToolCallResultHandler`/`LlmStepResultHandler` transition `RunStatus::Cancelling` to terminal `Cancelled`.

This matches the async runtime ladder: cooperative checks are level 1; registered foreground PID/process-group termination is level 2. Consumer SIGTERM/SIGKILL remains a future last-resort layer.

### Generic toolbox tools

Not every Symfony Toolbox callable is automatically interruptible. For app-owned tools:

- short pure-PHP tools check cancellation before starting and at safe boundaries;
- foreground subprocess tools register their PIDs/process groups and rely on controller/runtime termination on cancellation;
- background processes use the same registry/terminator primitives but are not killed by ordinary run cancellation;
- unknown third-party tools remain pre/post cancellable only unless they opt into the context accessor or process registry.

### Pipeline semantics

A cancelled tool should not be treated like an ordinary model-visible tool error.

Preferred behavior:

- foreground subprocess exits promptly because the controller killed the registered process group;
- the tool returns structured `cancelled=true` details when it observes a cancelled token/run state;
- `ToolCallResultHandler` sees `RunStatus::Cancelling` and transitions the run to `Cancelled`;
- cancelled tool output is not fed back into the model for another turn.
