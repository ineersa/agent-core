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

## 6. Prompt integration

The system prompt should:

1. **Read**: "Use `read` to examine files. Files are shown with line numbers — use these to construct accurate `@@` headers in edit diffs. Truncation hints tell you where to resume."

2. **Edit**: "Use `edit` with unified diff format. The `@@` header must reference the line numbers shown by `read`. Context lines (` ` prefix) help `patch` disambiguate. Removed lines start with `-`, added lines with `+`. Multiple hunks in one patch are supported."

3. **Write**: "Use `write` for new files or complete rewrites. Use `edit` for targeted changes to existing files."

4. **Bash**: "Use `bash` for file operations, build commands, tests, git operations, and any command-line work."

---

## 7. Implementation task breakdown

Tasks are intentionally small and prefixed with `TOOLS-` so smaller models can implement them safely. Each task should include tests and use Castor for validation (`castor test` for focused tests, `castor check` when practical).

### Tasks

| Task | Scope | Depends on | Can parallelize with |
|------|-------|------------|----------------------|
| TOOLS-00 | Tool cancellation context, guard, and cancellable process runner | none | TOOLS-01, TOOLS-02 |
| TOOLS-01 | Static `PathResolver` helper for file tools | none | TOOLS-00, TOOLS-02 |
| TOOLS-02 | `OutputCap` service for text output persistence and cleanup | none | TOOLS-00, TOOLS-01 |
| TOOLS-03 | Simple `write` tool | TOOLS-00, TOOLS-01 | TOOLS-04, TOOLS-05, TOOLS-07, TOOLS-08 |
| TOOLS-04 | `view_image` tool using `FinfoMimeTypeDetector` | TOOLS-00, TOOLS-01 | TOOLS-03, TOOLS-05, TOOLS-07, TOOLS-08 |
| TOOLS-05 | `PatchRunner` utility wrapping GNU `patch` dry-run/apply | TOOLS-00 | TOOLS-03, TOOLS-04, TOOLS-08 |
| TOOLS-06 | `edit` tool using `PatchRunner` | TOOLS-00, TOOLS-01, TOOLS-05 | TOOLS-07, TOOLS-08 |
| TOOLS-07 | `read` tool using `cat -n`, `sed`, `head`, and `OutputCap` | TOOLS-00, TOOLS-01, TOOLS-02 | TOOLS-06, TOOLS-08 |
| TOOLS-08 | `BackgroundProcessManager` and `bg_status` companion tool | TOOLS-00 | TOOLS-03, TOOLS-04, TOOLS-05, TOOLS-06, TOOLS-07 |
| TOOLS-09 | `bash` tool with OutputCap and user-controlled background prompt | TOOLS-00, TOOLS-02, TOOLS-08 | TOOLS-10 after schemas stabilize |
| TOOLS-10 | Prompt/docs integration for final toolset | TOOLS-03, TOOLS-04, TOOLS-06, TOOLS-07, TOOLS-09 | none |

### Dependency waves

1. **Foundation:** TOOLS-00, TOOLS-01, and TOOLS-02 can start immediately and in parallel.
2. **Process/background foundation:** TOOLS-05 and TOOLS-08 depend on TOOLS-00 and can run after it lands.
3. **Independent tools:** TOOLS-03 and TOOLS-04 depend on TOOLS-00 + TOOLS-01 and can run in parallel after those land.
4. **Patch/read tools:** TOOLS-06 depends on TOOLS-00 + TOOLS-01 + TOOLS-05. TOOLS-07 depends on TOOLS-00 + TOOLS-01 + TOOLS-02. They can run in parallel with each other.
5. **Bash:** TOOLS-09 depends on TOOLS-00 + TOOLS-02 + TOOLS-08.
6. **Prompt/docs:** TOOLS-10 should be last so prompts match the final registered tool names and schemas.

### Notes for implementers

- Do not implement `GrepTool`, `FindTool`, or a custom `ToolRegistry` in this rollout.
- Do not add model-controlled `run_in_background`; backgrounding is controlled by the TUI/user prompt.
- Prefer Symfony DI/autowiring and `#[AsTool]` on tool classes.
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
