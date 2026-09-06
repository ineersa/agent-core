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

Type a request in the editor and press Enter to submit it. For example:

> Explain this project's entry point. Do not change files.

Use `/hotkeys` to display the active editor bindings, including the multiline newline
binding. Tab opens or accepts completion suggestions. When the editor is empty,
Up and Down navigate submitted prompt history. Open completion menus use those keys
to navigate suggestions instead.

Enter accepts a highlighted completion and submits it. Escape dismisses completion
or the current overlay before normal run cancellation applies.

## Read tool activity

Tool calls and their results appear in the transcript. Press Ctrl+O to expand or
collapse tool-result and diff previews for the current session. This does not change
your saved settings.

The agent can read and edit files and execute commands with your local permissions.
Inspect changes before committing them. Approval policy can block a call or ask you
to authorize it, but tool access is not a sandbox. See [approvals](approvals.md).

If a bash command offers to continue in the background, you decide whether to accept.
The agent can use `bg_status` to inspect or stop accepted background jobs. See
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
| `/model` | Select an enabled model. |
| `/settings-show` | Inspect settings through the session UI. |
| `/compact` | Compact model-visible conversation context. |
| `/mcp` | Inspect MCP server status and discovered tools. |
| `/agents-live` | Select a child agent's live view. |
| `/agents-main` | Return from a child view to the parent. |

Extensions and discovered prompt templates can add commands. `/help` reflects the
commands available in your installation. For reusable instructions, see
[skills](skills.md) and [prompt templates](prompt-templates.md).

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
conversation position, not files on disk. File rewind requires a separate extension.
See [sessions](session-storage.md) for storage locations, backups, recovery limits,
and explicit repair safety. For long conversations, see [compaction](compaction.md).
