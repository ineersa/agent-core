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
| `subagent` | Launch one discovered named role. |
| `fork` | Launch a child with inherited context; does not create a Git worktree. |
| `agent_resume` | Continue an existing parent-owned child/fork. |
| `agent_retrieve` | Read existing handoff/metadata/history, not launch work. |
| `agent_steer` / `notify_parent` | Native active-child guidance/notices; not human input. |
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

## code_mode

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
| `timeout_seconds` | `1800` | `3600` |
| `memory_limit_mb` | `256` | `1024` |
| `backgrounded` | `false` | Boolean |

The remaining parent tool budget can shorten the script deadline. Nested handlers
receive a cooperative budget, not a guarantee that synchronous code is preempted.
Stdout/stderr accompany the return value; large diagnostics use saved artifacts
and normal output caps. Prefer a small final `return` to dumping intermediates.

### Trust and unsupported operations

Raw PHP filesystem/process functions bypass toolbox hooks and approvals. Use
`tool()` for audited operations. Code mode inherits a launcher sandbox such as
bubblewrap; it does not create one. Checkout/PHAR use their launching PHP CLI;
fused native builds require `php` on PATH.

`subagent`, `fork`, `agent_resume`, `agent_steer`, `notify_parent` and `ask_human`
are unsupported inside code mode. Call them directly. Nested Bash/code-mode calls
cannot detach; use a direct `backgrounded: true` call for background work.
A background PHP script still depends on its original host worker and cannot
restore its stack after worker loss.

Related: [settings](settings.md), [human input](human-input.md),
[approvals](approvals.md), [sessions](session-storage.md).
