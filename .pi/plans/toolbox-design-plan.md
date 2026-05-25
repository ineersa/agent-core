# Toolbox Design Plan

## 1. How tools are wired in agent-core

The Symfony AI stack provides the wiring:

```
[Tool class with #[AsTool] attribute]
    └── autoconfigured via config/services.yaml
        └── injected into Toolbox(array $tools)
            └── ReflectionToolFactory reads #[AsTool] → builds Tool metadata
                └── Toolbox::getTools() → Tool[] (name, description, JSON Schema)
                └── Toolbox::execute(ToolCall) → ToolResult
```

**Key contract** (`ToolboxInterface`):

```php
interface ToolboxInterface
{
    /** @return Tool[] */
    public function getTools(): array;
    public function execute(ToolCall $toolCall): ToolResult;
}
```

**Tool model** (`Tool` DTO from symfony/ai-platform):

```php
new Tool(
    ExecutionReference $ref,  // class + method
    string $name,             // e.g. "read"
    string $description,      // e.g. "Read the contents of a file..."
    ?array $parameters,       // JSON Schema from PHP type hints
);
```

**Tool classes are plain PHP** — each tool is an invokable class in `src/CodingAgent/Tool/`:

```php
#[AsTool('read', description: 'Read the contents of a file.')]
final class ReadFileTool
{
    public function __invoke(
        string $path,
        ?int $offset = null,
        ?int $limit = null,
    ): ToolResult {
        // ...
    }
}
```

Parameters derive JSON Schema automatically from PHP type hints via `ReflectionToolFactory`. Return type `ToolResult` gives structured output.

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
  - Output goes through OutputCap (20K chars)
  - Full output persisted to .hatfield/tmp/output-cap/ when truncated
  - Poll ToolExecutionContext cancellation token while process is running
  - On cancellation or timeout, terminate the process tree/process group
  - Cancellation is a structured result, not a generic tool failure

Background behavior (user-controlled, NOT model-controlled):
  - Model schema has NO run_in_background parameter
  - At 30 seconds of runtime, TUI prompts user:
    "Command still running after 30s. Move to background?"
  - If user says yes:
    - Process unrefs, continues running
    - Output streams to .hatfield/tmp/bg/<session>-<pid>.log
    - Model receives: "Moved to background. PID: N, Log: <path>"
    - On completion: user gets notification, model can read log
  - If user says no:
    - Process keeps running until timeout (if set) or completion
  - If timeout < 30s and reached before prompt: normal timeout kill

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

## 3. Tool classes sketch

```php
// src/CodingAgent/Tool/ReadFileTool.php
#[AsTool('read', description: 'Read file contents with cat -n line numbering')]
final class ReadFileTool {
    public function __invoke(
        string $path,
        ?int $offset = null,
        ?int $limit = null,
    ): ToolResult { /* ... */ }
}

// src/CodingAgent/Tool/ViewImageTool.php
#[AsTool('view_image', description: 'View an image file')]
final class ViewImageTool {
    public function __invoke(
        string $path,
    ): ToolResult { /* ... */ }
}

// src/CodingAgent/Tool/BashTool.php
#[AsTool('bash', description: 'Execute a bash command')]
final class BashTool {
    public function __construct(
        private readonly BackgroundProcessManager $bgManager,
    ) {}

    public function __invoke(
        string $command,
        ?int $timeout = null,
    ): ToolResult { /* ... */ }
}

// src/CodingAgent/Tool/BgStatusTool.php
#[AsTool('bg_status', description: 'Check status, view output, or stop background processes')]
final class BgStatusTool {
    public function __construct(
        private readonly BackgroundProcessManager $bgManager,
    ) {}

    public function __invoke(
        string $action,  // "list" | "log" | "stop"
        ?int $pid = null,
    ): ToolResult { /* ... */ }
}

// src/CodingAgent/Tool/WriteFileTool.php
#[AsTool('write', description: 'Create or overwrite a file')]
final class WriteFileTool {
    public function __invoke(
        string $path,
        string $content,
    ): ToolResult { /* ... */ }
}

// src/CodingAgent/Tool/EditFileTool.php
#[AsTool('edit', description: 'Apply a unified diff patch to a file')]
final class EditFileTool {
    public function __invoke(
        string $path,
        string $patch,
    ): ToolResult { /* ... */ }
}
```

---

## 4. Shared utilities

```
src/CodingAgent/Tool/
  PathResolver.php            # Static helper: resolvePath(), expand ~, normalize, cwd-relative
  OutputCap.php              # Output capping + temp file persistence
  PatchRunner.php            # Wraps GNU patch subprocess via CancellableProcessRunner
  ToolExecutionContext.php    # Current run/tool cancellation and timeout context
  ToolExecutionContextAccessor.php  # Ambient context around Symfony Toolbox execution
  CancellationGuard.php       # Cooperative checkpoint helper for short tools
  CancellableProcessRunner.php # Shared process runner with timeout/cancel/TERM->KILL
  ProcessSpec.php             # Command/cwd/env/timeout/kill-group process request DTO
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
Config:
  MAX_CHARS = 20_000       # ~5000 tokens (code files)
  MAX_CHARS_DOCS = 50_000  # ~12500 tokens (docs: .md, .txt, etc.)
  MAX_AGE_SECONDS = 86400  # 24 hours — stale file cleanup threshold

Storage:
  .hatfield/tmp/output-cap/<session-prefix>-<random-hex>.txt

Flow:
  1. Tool produces text output
  2. If output > MAX_CHARS (or MAX_CHARS_DOCS for doc files):
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
- `DynamicToolDescriptionProcessor` currently sends all Symfony Toolbox tools to the provider by default, with optional filtering when `Input` option `tools` is a string list.
- `ToolExecutor` executes by tool name through `FaultTolerantToolbox`; the same active snapshot that feeds provider schemas must also feed execution allowlist checks.
- `src/CodingAgent/Tool/ToolRegistry.php` is currently only a stub.

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

- Keep Symfony Toolbox/`#[AsTool]` as the low-level PHP invocation adapter.
- Maintain deterministic permanent and dynamic tool maps keyed by model-visible tool name.
- Reject conflicting duplicate tool names deterministically; treat identical re-registration as idempotent.
- Produce snapshots for:
  - permanent prompt lines;
  - permanent prompt guidelines;
  - active provider-schema tools = permanent active tools + current dynamic tools;
  - active execution allowlist using the same names as the provider schema snapshot.
- Let extension tools register as permanent tools unless an explicit dynamic registration path is introduced later.

### AgentCore per-turn toolset resolution

AgentCore already has two useful pieces for per-request dynamic tools:

- `BeforeProviderRequestHookInterface` for late provider request mutation. This is useful for provider/model quirks, but it fires after `LlmPlatformAdapter` has already run `DynamicToolDescriptionProcessor`, so it is too late to be the primary dynamic-tool selector.
- `toolsRef` already exists on `ExecuteLlmStep`/`ModelInvocationInput` and is generated per turn by `AdvanceRunHandler` as `toolset:run:{runId}:turn:{turnNo}`. It is currently not resolved by `LlmPlatformAdapter` or `DynamicToolDescriptionProcessor`.

Use this existing `toolsRef` path rather than adding a separate dynamic-tool hook.

Required design:

1. Add an AgentCore-owned semantic interface such as `ToolSetResolverInterface` that resolves a per-turn tool reference into active toolset data. It must not depend on CodingAgent registry classes.
2. The resolved data must include at least the provider-visible tool names and execution allowlist names. A small DTO/value object is preferred over raw arrays.
3. `LlmPlatformAdapter` passes `ModelInvocationInput::$toolsRef`, `runId`, and `turnNo` into `Input` options before `DynamicToolDescriptionProcessor::processInput()` runs.
4. `DynamicToolDescriptionProcessor` uses `ToolSetResolverInterface` when a `toolsRef` option is present, then filters/constructs `options['tools']` from that resolved active toolset. It can keep the current fallback to all Symfony Toolbox tools when no resolver/ref is available.
5. CodingAgent provides the concrete resolver implementation that maps AgentCore `toolsRef` to the CodingAgent `ToolRegistry` active snapshot, including current dynamic tools.
6. Tool execution rejects any tool name not present in the same resolved snapshot's execution allowlist.

This keeps AgentCore generic: AgentCore owns `toolsRef`, `ToolSetResolverInterface`, and option propagation; CodingAgent owns registry policy and the concrete resolver.

### Registration/runtime flow

1. Built-in tools are normal Symfony services, preferably tagged/attributed with `#[AsTool]` where useful.
2. CodingAgent boot registers built-in permanent tools with explicit metadata: name, provider description/schema reference, handler/reference, small prompt line, guideline strings.
3. Extension loader calls `ExtensionApiInterface::registerTool()`; EXT-02 maps public `ToolRegistrationDTO` into permanent registry entries.
4. At run start, `SystemPromptService` resolves `SYSTEM.md`, resolves append templates, asks `ToolRegistry` for permanent prompt metadata, renders the final prompt, and prepends it as a system `AgentMessage` before the user prompt.
5. Before each provider request, AgentCore passes the per-turn `toolsRef` through `LlmPlatformAdapter`; `ToolSetResolverInterface` resolves it to an active tool snapshot supplied by CodingAgent's concrete resolver.
6. Provider tool schemas and execution allowlist are derived from that same resolved snapshot.

---

## 7. Implementation task breakdown

Tasks are intentionally small and prefixed with `TOOLS-` so smaller models can implement them safely. Each task should include tests and use Castor for validation (`castor test` for focused tests, `castor check` when practical).

### Tasks

- **SYSTEM-01** — System prompt template resolution, append prompts, rendering, and run-start injection.
  - Depends on: TOOLS-R00 for permanent tool prompt metadata.
  - Can parallelize with: concrete tool implementation after TOOLS-R00 snapshot APIs are stable.

- **SYSTEM-02** — `AGENTS.md` project context discovery and new-session context message injection.
  - Depends on: none; can reuse SYSTEM-01 prompt/channel wording when available, but must not splice AGENTS content into the system prompt.
  - Can parallelize with: SYSTEM-01, TOOLS-R00, and concrete tool implementation.

- **SYSTEM-03** — Skills registry/discovery, `--skills-path`/`--skills`/`--no-skills`, and new-session skills context injection.
  - Depends on: SYSTEM-02 for the shared initial user-context message placement/order.
  - Can parallelize with: concrete tool implementation after the context-message boundary is stable.

- **TOOLS-R00** — CodingAgent `ToolRegistry` with permanent tools, dynamic tools, snapshots, and active allowlists.
  - Depends on: none.
  - Can parallelize with: TOOLS-00, TOOLS-01, TOOLS-02, EXT-00.

- **TOOLS-00** — Tool cancellation context, guard, and cancellable process runner.
  - Depends on: none.
  - Can parallelize with: TOOLS-R00, TOOLS-01, TOOLS-02.

- **TOOLS-01** — Static `PathResolver` helper for file tools.
  - Depends on: none.
  - Can parallelize with: TOOLS-R00, TOOLS-00, TOOLS-02.

- **TOOLS-02** — `OutputCap` service for text output persistence and cleanup.
  - Depends on: none.
  - Can parallelize with: TOOLS-R00, TOOLS-00, TOOLS-01.

- **TOOLS-03** — Simple `write` tool.
  - Depends on: TOOLS-R00, TOOLS-00, TOOLS-01.
  - Can parallelize with: TOOLS-04, TOOLS-05, TOOLS-07, TOOLS-08, SYSTEM-01.

- **TOOLS-04** — `view_image` tool using `FinfoMimeTypeDetector`.
  - Depends on: TOOLS-R00, TOOLS-00, TOOLS-01.
  - Can parallelize with: TOOLS-03, TOOLS-05, TOOLS-07, TOOLS-08, SYSTEM-01.

- **TOOLS-05** — `PatchRunner` utility wrapping GNU `patch` dry-run/apply.
  - Depends on: TOOLS-00.
  - Can parallelize with: TOOLS-03, TOOLS-04, TOOLS-08, SYSTEM-01.

- **TOOLS-06** — `edit` tool using `PatchRunner`.
  - Depends on: TOOLS-R00, TOOLS-00, TOOLS-01, TOOLS-05.
  - Can parallelize with: TOOLS-07, TOOLS-08, SYSTEM-01.

- **TOOLS-07** — `read` tool using `cat -n`, `sed`, `head`, and `OutputCap`.
  - Depends on: TOOLS-R00, TOOLS-00, TOOLS-01, TOOLS-02.
  - Can parallelize with: TOOLS-06, TOOLS-08, SYSTEM-01.

- **TOOLS-08** — `BackgroundProcessManager` and `bg_status` companion tool.
  - Depends on: TOOLS-R00, TOOLS-00.
  - Can parallelize with: TOOLS-03, TOOLS-04, TOOLS-05, TOOLS-06, TOOLS-07, SYSTEM-01.

- **TOOLS-09** — `bash` tool with OutputCap and user-controlled background prompt.
  - Depends on: TOOLS-R00, TOOLS-00, TOOLS-02, TOOLS-08.
  - Can parallelize with: SYSTEM-01 and early TOOLS-10 prep after schemas stabilize.

- **TOOLS-10** — Prompt/docs integration for final toolset.
  - Depends on: TOOLS-R00, SYSTEM-01, SYSTEM-02, SYSTEM-03, TOOLS-03, TOOLS-04, TOOLS-06, TOOLS-07, TOOLS-09.
  - Can parallelize with: none; it should close the loop after final names/schemas/context/skills behavior stabilize.

### Dependency waves

1. **Registry and utility foundation:** TOOLS-R00, TOOLS-00, TOOLS-01, and TOOLS-02 can start immediately and in parallel.
2. **Prompt/context foundation:** SYSTEM-01 starts after TOOLS-R00 snapshot APIs are stable. SYSTEM-02 can start independently because AGENTS.md context is a separate first-message channel, not a system prompt placeholder. SYSTEM-03 follows SYSTEM-02 so skills share the same first-message injection boundary.
3. **Process/background foundation:** TOOLS-05 depends on TOOLS-00 and can run after it lands. TOOLS-08 depends on TOOLS-R00 + TOOLS-00.
4. **Independent tools:** TOOLS-03 and TOOLS-04 depend on TOOLS-R00 + TOOLS-00 + TOOLS-01 and can run in parallel after those land.
5. **Patch/read tools:** TOOLS-06 depends on TOOLS-R00 + TOOLS-00 + TOOLS-01 + TOOLS-05. TOOLS-07 depends on TOOLS-R00 + TOOLS-00 + TOOLS-01 + TOOLS-02. They can run in parallel with each other.
6. **Bash:** TOOLS-09 depends on TOOLS-R00 + TOOLS-00 + TOOLS-02 + TOOLS-08.
7. **Prompt/docs:** TOOLS-10 should be last so prompts match the final registered tool names, schemas, AGENTS.md context behavior, and skills behavior.

### Notes for implementers

- Do not implement `GrepTool` or `FindTool` in this rollout.
- `ToolRegistry` is implemented by TOOLS-R00 and owns permanent/dynamic tool policy and metadata. Concrete tools should register permanent prompt metadata through it instead of inventing per-tool prompt/activation wiring.
- Do not add model-controlled `run_in_background`; backgrounding is controlled by the TUI/user prompt.
- Prefer Symfony DI/autowiring and `#[AsTool]` on tool classes where useful, with registry metadata kept explicit.
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

Tool execution should build on that existing run-level cancellation instead of introducing a separate cancellation system.

### Key decision

Tools owned by CodingAgent can remain Symfony AI toolbox tools and still be cancellable.

Because our tool classes are app-owned services, they can inject app-owned execution helpers and check the current run status while executing. We do not need model-visible cancellation parameters in tool schemas.

### Recommended implementation: ToolExecutionContextAccessor

Keep `#[AsTool]` and Symfony Toolbox for schema/discovery/execution, but add an app-owned ambient execution context around each toolbox invocation.

```php
interface ToolExecutionContext
{
    public function runId(): string;
    public function toolCallId(): string;
    public function toolName(): string;
    public function cancellationToken(): CancellationTokenInterface;
    public function timeoutSeconds(): int;
    public function throwIfCancellationRequested(): void;
}

final class ToolExecutionContextAccessor
{
    /** @var list<ToolExecutionContext> */
    private array $stack = [];

    public function current(): ?ToolExecutionContext
    {
        return $this->stack[array_key_last($this->stack)] ?? null;
    }

    public function with(ToolExecutionContext $context, callable $callback): mixed
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

`ToolExecutor` creates the context from the current `ToolCall` and wraps Symfony Toolbox execution:

```php
return $this->contextAccessor->with(
    $context,
    fn () => $this->faultTolerantToolbox->execute(new SymfonyToolCall(...)),
);
```

Tool services that need cancellation inject `ToolExecutionContextAccessor`:

```php
#[AsTool('bash', description: 'Execute a bash command')]
final class BashTool
{
    public function __construct(
        private readonly ToolExecutionContextAccessor $contexts,
        private readonly BashProcessRunner $runner,
    ) {}

    public function __invoke(string $command, ?int $timeout = null): ToolResult
    {
        $context = $this->contexts->current()
            ?? throw new \LogicException('BashTool requires a tool execution context.');

        return $this->runner->run($command, $timeout, $context);
    }
}
```

This keeps cancellation out of the model-facing schema while letting app-owned tools react to cancellation.

### Concurrency note

The accessor stack is acceptable for synchronous CLI/Messenger tool execution. If future in-process parallel/fiber execution is introduced, replace the simple stack with an operation-id/fiber-local context strategy or use a native execution registry that passes context explicitly.

### Generalized cancellation helpers

Do not implement polling loops or process termination separately in each tool. Use shared services:

1. `CancellationGuard` for cheap cooperative checkpoints in non-process tools.
2. `CancellableProcessRunner` for all tools that shell out or spawn subprocesses.

Example guard:

```php
final readonly class CancellationGuard
{
    public function checkpoint(ToolExecutionContext $context): void
    {
        if ($context->cancellationToken()->isCancellationRequested()) {
            throw new ToolCancelledException();
        }
    }
}
```

Short tools (`read`, `write`, `view_image`, `edit` validation steps) call checkpoints at safe boundaries. They should not each own custom cancellation loops.

Example process runner contracts:

```php
final readonly class ProcessSpec
{
    /** @param list<string> $command */
    public function __construct(
        public array $command,
        public string $cwd,
        public array $env = [],
        public ?int $timeoutSeconds = null,
        public bool $killProcessGroup = true,
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

`CancellableProcessRunner` owns:

- starting `Symfony\Component\Process\Process`,
- output streaming and OutputCap/log accumulation,
- timeout enforcement,
- cancellation-token polling,
- process group/tree termination,
- TERM -> grace period -> KILL escalation,
- normalized `ProcessRunResult` output.

Avoid traits for core cancellation behavior. A small trait/helper for `requireCurrentContext()` is acceptable, but process management and polling should live in testable services.

### Bash cancellation contract

Bash is the first tool that must support true mid-execution interruption, but it should not own the low-level polling/kill loop. Bash should build a `ProcessSpec`, call `CancellableProcessRunner`, and format the `ProcessRunResult` into a `ToolResult`.

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

### Process tree termination

Plain `Process::stop()` may not be enough for shell commands that spawn children. The bash runner should own a small process-kill helper.

Unix strategy:

- Prefer starting bash as a process-group/session leader, e.g. via `setsid` when available.
- Kill the process group using negative PID: `posix_kill(-$pid, SIGTERM)` then `SIGKILL` after grace period.
- Fallback to killing the direct process if process-group setup is unavailable.

Windows strategy can be deferred or implemented separately with Symfony Process options / taskkill.

### Generic toolbox tools

Not every Symfony Toolbox callable is automatically interruptible. For app-owned tools:

- short tools (`read`, `write`, `edit`, `view_image`) check cancellation before starting and at safe boundaries;
- long tools (`bash`, future search/indexing/subagents) check during execution;
- unknown third-party tools remain pre/post cancellable only unless they opt into the context accessor.

### Pipeline semantics

A cancelled tool should not be treated like an ordinary model-visible tool error.

Preferred behavior:

- return structured `cancelled=true` details from the tool;
- `ToolCallResultHandler` sees `RunStatus::Cancelling` and transitions the run to `Cancelled`;
- do not continue the turn by feeding cancelled tool output back into the model.

The existing `ToolCallResultHandler` already handles `RunStatus::Cancelling`; bash mainly needs to ensure the process actually stops promptly.
