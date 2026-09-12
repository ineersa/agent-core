---
builtin: true
description: Built-in tool catalog, file and command operations, child agents, and extension or MCP tools.
---

# Tools

Hatfield exposes tools to the model so it can inspect and change your workspace.
The available set depends on your configuration, agent role, extensions, and MCP
servers. Tool availability does not bypass approval policy.

## Built-in catalog

| Tool | Purpose | Important limits |
|---|---|---|
| `read` | Read a text file, optionally by line range | Use `offset` and `limit` for long files. Not an image or PDF reader. |
| `write` | Create or overwrite a text file | Creates parent directories. Overwrites existing content. |
| `edit` | Apply context-matched patches to an existing file | Requires matching file context. Read the target before editing. |
| `view_image` | Attach an image for model inspection | JPEG, PNG, GIF, and WebP. Size and dimension limits apply. |
| `bash` | Run a shell command in the workspace | Local permissions apply. Timeout and cancellation do not undo completed effects. |
| `bg_status` | List, inspect logs, or stop accepted background processes | Session-scoped. Does not expose private foreground supervision. |
| `ask_human` | Ask for text, confirmation, or a choice | Waits for a human response. Cancellation is not approval. |
| `code_mode` | Run a PHP script that calls other tools through `tool(name, arguments)` | Disabled by default (`tools.code_mode.enabled`). Raw PHP bypasses toolbox hooks. Requires a PHP CLI interpreter. |
| `settings` | Read effective settings, set overrides, or remove overrides | Mutations specify user or project scope and pass approval checks. |
| `hatfield_docs` | List and read packaged Hatfield documentation | Does not automatically discover extension-package READMEs. |
| `subagent` | Launch a named child agent, singly or in parallel | Uses discovered agent definitions and child tool policy. |
| `agent_resume` | Continue an existing child or fork with a follow-up task | Artifacts must belong to the current parent session. |
| `agent_retrieve` | Read child handoffs, metadata, or bounded history | Retrieves existing artifacts; does not launch work. |
| `fork` | Launch a child with inherited conversation context | Blocks for a handoff. Not a Git worktree creation tool. |

Tool argument schemas supplied to the model are the authoritative call format.
The catalog above explains purpose rather than duplicating every argument.

## File changes and commands

Use `read` to inspect existing content, `edit` for a targeted change, and `write`
for a new file or an intentional full replacement. `view_image` supplies image
content to the model instead of treating binary data as text.

Shell commands can modify files and contact external services. Hatfield is not a
sandbox. Inspect changes before committing them. [SafeGuard approvals](approvals.md)
can require your decision or block a call.

Oversized text results are capped. The response can include a saved-file path and
instructions for reading the omitted content. Those files are temporary; a path
preserved in session history can outlive the actual saved output.

## code_mode

`code_mode` is off by default. Enable it with `tools.code_mode.enabled: true` in
user or project settings, then restart Hatfield.

The script can call registered tools, including MCP tools, through
`tool(name, arguments)` using each tool's runtime name. Values returned by
`tool()` stay as JSON-compatible PHP values. Strings stay strings. Use
`toon_encode()` and `toon_decode()` when you need TOON conversion. Nested tool
failures throw `RuntimeException`.

Raw PHP filesystem and process functions also work inside the script. Those
calls bypass toolbox hooks and approvals. If you launch Hatfield under
`hatfield-safe` or another bubblewrap wrapper, the script inherits that sandbox.
`code_mode` does not create a separate sandbox.

`tool()` rejects `subagent`, `fork`, `agent_resume`, and `ask_human` before the
handler runs. Those tools need deferred child ownership or interactive pause
flow that this bridge cannot complete. Script return values and nested tool results must be JSON-compatible scalars
or arrays. Closures, resources, objects, non-finite floats, invalid UTF-8, and
cyclic graphs fail instead of becoming empty or substituted data. Each script
gets a 60-second wall budget (or the remaining parent tool budget when smaller)
and a 256 MiB PHP memory limit. Nested tool calls receive the remaining script
budget as cooperative ToolContext metadata. The host can enforce that budget
only while it is polling the script; a nested handler that blocks synchronously
can still overrun until it returns.

## Background work

There is no `bg_start` tool or model-selected background flag. A long-running `bash`
call can offer backgrounding, and the user chooses. Accepted jobs can then be
inspected or stopped through `bg_status`. See [background processes](background-processes.md).

## Child agents

Use named [subagents](agents.md) for role-specific tasks, `agent_resume` for follow-up
work on an existing child or fork, and `agent_retrieve` to inspect their artifacts. A
fork inherits parent context rather than starting only with a named role prompt.
Resume an eligible fork instead of launching a duplicate when its context still
applies. Nested child launches are blocked.

Children do not automatically receive every parent tool or optional extension.
MCP inheritance and explicit selectors are described in [MCP](mcp.md).

## Extension and MCP tools

Enabled extensions can register additional tools. For example, task-workflow adds
task-board operations, while observational-memory provides `recall`. These are
extension tools, not universally available built-ins. Observer and reflector jobs
also have private tools that are not the main session's catalog.

MCP servers advertise their own tool names and schemas. `/mcp` shows configured
servers and discovered tools. [MCP configuration](mcp.md) controls availability.
IDE tools are supplied by the configured integration, not by the fixed built-in list.

## Failure diagnostics

Tool failures retain a failed outcome in runtime events and the transcript.
Handler exceptions expose a bounded cause instead of a generic execution error.
Common credential patterns are redacted before display. Redaction cannot identify
arbitrary secrets embedded in prose; tool handlers must not include sensitive
arguments or environment values in exception messages.

Results retain the tool-call identity. Available task, log, and status references
describe where to inspect partial work before retrying. A failed tool call does
not imply that earlier side effects were rolled back. Bash supervision failure
does not establish the workload's exit status. See [Background processes](background-processes.md).

## Related

- [Terminal usage](terminal-usage.md)
- [Human input](human-input.md)
- [Settings](settings.md)
- [Sessions and temporary outputs](session-storage.md)
