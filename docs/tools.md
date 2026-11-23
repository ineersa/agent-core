---
builtin: true
description: Choose workspace tools, use code mode safely, recover capped output, and find optional tools.
---

# Tools

Use the current tool schemas for exact arguments and availability. Agent policy,
extensions and MCP configuration can change the catalog; listing a tool does not
bypass approvals or sandbox it.

## Choose the right tool

| Tool | Use |
|---|---|
| `read` | Text files; use `offset` + `limit` for a range. Not images/PDFs. |
| `write` | New files or intentional full replacement; creates parent directories. |
| `edit` | Context-matched changes to existing files; read current context first. |
| `view_image` | Inspect JPEG/PNG/GIF/WebP content; size/dimension limits apply. |
| `bash` | Shell commands with local permissions, timeout and cancellation limits. |
| `bg_status` | List/log/stop accepted session-owned background Bash. |
| `ask_human` | Ask for text, confirmation or a choice; cancellation is not approval. |
| `code_mode` | Opt-in PHP scripts calling tools through `tool(name, arguments)`. |
| `hatfield_docs` | `list` available product guides, then `read` by logical ID. |
| `get_goal` / `set_goal` / `clear_goal` | Main-only tools to read, replace, or complete/remove the session objective. |
| `subagent` | Launch one discovered named role. |
| `fork` | Launch a child with inherited context; does not create a Git worktree. |
| `agent_resume` | Continue an existing parent-owned child/fork. |
| `agent_retrieve` | Read existing handoff/metadata/history, not launch work. |
| `agent_steer` / `notify_parent` | Native active-child guidance/notices; not human input. |
| `agent_cancel` | Stop a running subagent or fork. |
| `schedule` | Optional recurring Bash/code-mode work; enable Scheduling first. |

Task-board and observational-memory tools come from optional extensions, not every
Hatfield installation. MCP tools use server-advertised runtime names/schemas.
See [agents](agents.md), [MCP](mcp.md) and [background/scheduled work](background-processes.md).

## Output and failures

Oversized text returns a bounded preview plus a saved-file inspection reference.
Follow the returned range-reading instructions; do not repeat the full original
read. Saved tool-output files are temporary and can expire while their notices
remain in session history. Copy important results into an intentional project
artifact if they must survive restart.

A tool failure can happen after side effects. Inspect the workspace, logs and
returned artifact references before retrying; failure does not imply rollback.
Error redaction is not a reason to include prompts, credentials or environment
values in diagnostics.

`edit` waits at most five seconds for exclusive access to the target inode. If
contention persists, it fails with `E_PATCH_LOCK_TIMEOUT` without modifying the
file and logs a structured error; it does not schedule a delayed edit or retry
automatically. Investigate the competing writer before retrying. This limit also
applies to edits called from `code_mode`; it is an edit-lock limit, not a general
nested-tool deadline. Maintainer diagnostics are described in the repository's
`docs/datadog.md`, under “Edit lock contention”.

## Bash timeouts

Foreground Bash uses its configured default when `timeout` is omitted, capped
by any configured maximum. Once backgrounded, Bash honors an explicit timeout
from launch. If `timeout` is omitted, the configured maximum applies from launch;
without a configured maximum there is no background deadline.

## code_mode

Broker delivery can repeat a nested tool request. Individual tools own idempotency if they need it.

`tool()` preserves native tool values: strings stay strings (even if they look
like JSON), and scalars and arrays keep their types. A status field inside a
successful value is data, not an exception.

For MCP tools, `tool()` returns explicitly supplied structured content (including
`null`); if it is absent, `tool()` returns MCP text. The output schema shown in a
tool's description applies only to structured content, not to fallback text.
Hatfield validates the original JSON against the declared schema, if any, before
converting objects to associative PHP arrays. For example, read a returned object
field as `$result['name']`. After conversion, `{}` and `[]` both become `[]`, and
numeric object keys may become integer array keys, making an object look like a list.
Model-facing MCP text remains separate from the value returned to the script.

Tool invocation failures, including MCP-reported errors, transport failures,
cancellation, and schema mismatches, are catchable as `RuntimeException` inside
the script. An invalid structured result never falls back to text.

A script's final return is rendered as text, subject to normal output capping.
Attachment-shaped fields in that value are data, not instructions to attach files.
Even `return tool('view_image', ...)` returns metadata without attaching the image;
call `view_image` directly when the model needs to see it.

Enable `tools.code_mode.enabled: true` in settings and restart Hatfield. The tool
accepts a PHP **function body** in `script`, not a file path. It is useful for
multi-step tool work whose intermediate results do not need model interpretation.
For a single call, image, human question or child-agent operation, use direct tools.

Example script to collect small excerpts without returning every intermediate:

```php
$excerpts = [];
foreach (['composer.json', 'config/bundles.php'] as $path) {
    $text = tool('read', ['path' => $path, 'offset' => 1, 'limit' => 10]);
    $excerpts[$path] = $text;
}
return $excerpts;
```

`tool()` accepts a registered runtime name and argument array, including MCP names.
Values stay as returned; strings are not implicitly decoded. Use `json_decode()`,
`toon_decode()` or `toon_encode()` explicitly when appropriate. Nested failures
throw `RuntimeException`; catch only when you can handle/report the failure.
Return JSON-compatible scalars/arrays. Objects, resources, closures, non-finite
floats, invalid UTF-8 and cycles fail. Missing/null returns show `null`;
`exit`/`die` without a return is not successful completion.

| Argument | Default | Maximum |
|---|---|---|
| `timeout_seconds` | `1800` | No tool-specific cap |
| `memory_limit_mb` | `256` | `1024` |
| `backgrounded` | `false` | Boolean |

The foreground default and remaining parent tool budget apply until background
acceptance. After acceptance only an explicit deadline applies, measured from
launch. Nested handlers observe the current script deadline through cooperative
cancellation, not a fixed per-call timeout. Cancellation does not preempt
synchronously blocking code.
Stdout/stderr accompany the return value; large diagnostics use saved artifacts
and normal output caps. Prefer a small final `return` to dumping intermediates.

Use `notify_owner(string $message)` to submit an intermediate notice from a
running script. The message must be nonblank UTF-8 and at most 8,192 bytes.
The helper returns `['status' => 'submitted', 'notice_id' => ...]` or a
structured refusal with `status: refused` and a `reason`. Submission does not
promise delivery, consumption, or a response. It does not interrupt a
foreground tool wait or replace the script's final result.

The owner comes from the invocation, including for background, scheduled,
and child-owned scripts. This is not `notify_parent`: existing mailbox
rules determine when the owner sees the notice and whether it is forwarded.

### Trust and unsupported operations

Raw PHP filesystem/process functions bypass toolbox hooks and approvals. Use
`tool()` for audited operations. Code mode inherits a launcher sandbox such as
bubblewrap; it does not create one. Checkout/PHAR use their launching PHP CLI;
fused native builds require `php` on PATH.

`code_mode`, `subagent`, `fork`, `agent_resume`, `agent_cancel`, `agent_steer`,
`notify_parent` and `ask_human` are unsupported inside code mode. Call them directly. Nested
Bash calls cannot detach; use a direct `backgrounded: true` call for background work.
Canonical PHP scripts in the session runtime use the broker for nested tool
calls and do not depend on the worker that launched them. A script that loses
its PHP process cannot resume, and an interrupted tool call may have an unknown
outcome.

Related: [settings](settings.md), [human input](human-input.md),
[approvals](approvals.md), [sessions](session-storage.md).
