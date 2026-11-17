---
builtin: true
description: Terminal editor, session commands, model selection, tool results, cancellation, and exit behavior.
---

# Terminal usage

Hatfield's interactive terminal contains a conversation transcript, status information,
a prompt editor, and a footer. The transcript shows your prompts, streamed responses,
and tool calls with their results. Questions and approvals appear in the session.

For installation and provider setup, see [installation and upgrades](installation.md).

## Start and enter a prompt

Start in the repository you want the agent to work on:

```bash
cd /path/to/your/project
hatfield agent
```

Before the session opens, installed extensions are checked for updates. When
changes are available, review the version/dependency and command preview and
answer Yes or No (default No). Check/update errors stop startup. See
[extension updates](installation.md#extension-updates-at-interactive-startup).

Type a request in the editor and press Enter to submit it. For example:

> Explain this project's entry point. Do not change files.

Use `/hotkeys` to display the active editor bindings, including the multiline newline
binding. Tab opens or accepts completion suggestions. When the editor is empty,
Up and Down navigate submitted prompt history. Open completion menus use those keys
to navigate suggestions instead.

Enter accepts a highlighted completion and submits it. Escape dismisses completion
or the current overlay before normal run cancellation applies.

## Paste text and images

Ctrl+V attaches an image from the system clipboard when the platform clipboard
reader is available. Bracketed paste containing only an absolute path to a readable
supported image also becomes an `[Image #N]` attachment. This supports terminals
that save clipboard images to files and paste their paths. Hatfield stages its own
copy; it does not delete the source image. Existing image size/dimension limits
still apply. Other pasted content remains text.

An existing absolute file/directory path submitted as a message is a normal
prompt, not a slash command. Missing paths can still parse as slash commands.
Ordinary prose containing a path does not automatically attach that file.

## Read tool activity

Tool calls and their results appear in the transcript. Press Ctrl+O to expand or
collapse tool-result and diff previews and automatic messages for the current
session. Automatic messages use a non-human glyph and a one-line preview. Expand
them to read their full text and source details. Human messages always appear in
full. Automatic messages do not appear in the pending steering queue. This does
not change your saved settings.

The agent can read and edit files and execute commands with your local permissions.
Inspect changes before committing them. Approval policy can block a call or ask you
to authorize it, but tool access is not a sandbox. See [approvals](approvals.md).

Press Ctrl+B during a `bash`, `code_mode`, `subagent`, `fork` or `agent_resume` call to background it.
Open `/bg` to inspect or cancel background work without a model call, or ask the agent
to use `bg_status` for background Bash jobs. See
[background processes](background-processes.md) for ownership and cleanup.

## Session commands

| Command | Purpose |
|---|---|
| `/help` | Display available commands. |
| `/hotkeys` | Display keyboard shortcuts. |
| `/new` | Start a new session. |
| `/resume` | Select a saved session and continue it. |
| `/rename` | Change the session's display name. |
| `/history` | Navigate conversation history, not file restore. |
| `/goal [objective]` | Show or replace the session objective; `edit` prefills it, `clear` or `cancel` removes it. |
| `/model` | Select an enabled model. |
| `/settings-show` | Inspect settings through the session UI. |
| `/compact` | Compact model-visible conversation context. |
| `/bg` | Inspect and stop background processes in the visible session. |
| `/mcp` | Inspect MCP server status and discovered tools. |
| `/agents-live` | Select a child agent's live view. |
| `/agents-main` | Return from a child view to the parent. |
| `/code-review [request]` | Run skill-orchestrated [code review](code-review.md) when its skill is discovered. |

Extensions and discovered prompt templates can add commands. `/help` reflects the
commands available in your installation. For reusable instructions, see
[skills](skills.md) and [prompt templates](prompt-templates.md).

## Session goals

`/goal <objective>` saves one objective for the current main session and submits
ordinary agent work. It also works in a fresh draft. `/goal` displays the current
objective without a model call; `/goal edit` puts it back in the editor for revision.
Only exact control words are special: `/goal cancel the migration` stores that
whole phrase as the objective. Objectives are limited to 4,000 Unicode characters.

After a successful main-agent exchange, a saved goal queues an internal follow-up
through the normal message path. The agent checks the current objective and its
evidence, clears it when complete, or continues unfinished work. Intermediate
model/tool steps, standalone `!` shell commands, child completions, failures and cancellation do not trigger this
follow-up. Goals survive compaction and session resume; resuming alone does not
start work. Forks and subagents do not own or change the main session's goal.

`/goal clear` and `/goal cancel` remove the objective immediately, including while
the agent is busy. They do not stop already executing tools; use normal cancellation
for that. A previously queued goal reminder instructs the agent to read the current
goal first and do nothing if it has been cleared. Goals are mutable session metadata,
not a versioned history timeline, and have no separate pause state or budget.

While the owning main session has a goal, `ask_human` remains in the tool catalog but
returns a tool-call error instead of opening a question, including in its forks and
subagents. This does not change its
schema or grant missing permissions/authorization. The agent must not clear a goal
merely to ask a question. A question already pending when a goal is set is not
automatically cancelled; goal commands during that existing question retain the
normal question-input behavior.

Agent completion uses `clear_goal` with the exact objective it verified. If the
user has replaced it meanwhile, the compare-and-clear leaves the replacement
intact and returns `cleared: false` with the current goal for re-evaluation.
Explicit user `/goal clear` or `/goal cancel` remains unconditional.

## Choose a model

`/model` lists selectable models from your effective configuration. Provider definitions
ship disabled, so configure them with `hatfield providers:setup` before model use.
Ctrl+P cycles available models. Shift+Tab cycles reasoning levels.

Available reasoning levels depend on the model. See [model settings](settings-models.md)
and [the provider catalog](ai-catalog.md). Changing a selection does not install a
local model server or supply credentials.

## Answer questions and approvals

Answer the question shown in the current session. Model questions and policy approvals
are separate flows, described in [human input](human-input.md) and [approvals](approvals.md).
Cancelling a question is not approval.

In a child live view, answers and Escape apply to that child, not the parent.
Use `/agents-main` or Ctrl+\ to return to the parent. See [agents](agents.md)
for child navigation and tool access.

## Cancel work or exit

- Escape requests cancellation of active work when no completion menu or question
  overlay handles the key. The status can remain cancelling until the runtime confirms it.
- While idle, Escape clears the editor. In a child live view it does not cancel the parent.
- A single Ctrl+C clears editor text. With an empty editor, it displays an exit hint.
  It is not the active-run cancellation shortcut.
- Two Ctrl+C presses within 1.5 seconds exit the TUI.
- Ctrl+D exits the TUI. It is not a substitute for Escape when you want to cancel work
  and remain in the session.

Cancellation does not roll back edits or external effects already performed.
An in-flight MCP call may not stop immediately because the integration cannot enforce
arbitrary call-level cancellation. See [MCP](mcp.md).

## Continue saved work

Use `/resume` to reopen a session and rebuild its transcript. `/history` changes the
conversation position, not files on disk.
See [sessions](session-storage.md) for storage locations, backups, recovery limits,
and explicit repair safety. For long conversations, see [compaction](compaction.md).
