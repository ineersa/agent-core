# EDITOR-05 Submission routing for prompts vs commands

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: yes.

Scope:
- Integrate command parsing/registry into the TUI submit path.
- Route normal prompt submissions to the runtime exactly as before.
- Route slash command submissions to the local command executor.
- Apply built-in command effects: help transcript/status message, clear transcript, exit app.
- Ensure command submissions are handled consistently with prompt clearing/history hooks.

Exclusions:
- No prompt history persistence; EDITOR-07 owns history.
- No slash completion UI; EDITOR-08 owns completion.
- No !/!! shell execution; EDITOR-11 owns shell prefixes.
- Do not import AgentCore internals into Tui.

Dependencies: EDITOR-02, EDITOR-04.
Parallelizable with: none after dependencies; serialize InteractiveMode/input routing edits.

## Acceptance criteria
- Normal prompts still submit to AgentSessionClient/runtime path.
- /help, /clear, and /exit work from the editor submit path.
- Slash commands do not create user prompt runtime events unless the command explicitly dispatches a runtime command.
- Unknown commands render a friendly local message/status.
- Composer/editor state is cleared or preserved according to command result semantics and covered by tests.
- castor deptrac passes.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/editor-05-submission-routing-prompts-vs-commands
Worktree: /home/ineersa/projects/agent-core-worktrees/editor-05-submission-routing-prompts-vs-commands
Fork run: hc0ewcxbrjlg
PR URL:
PR Status:
Started: 2026-05-18T17:52:40.003Z
Completed:

## Work log
- Created: 2026-05-18T00:15:36.255Z

## Task workflow update - 2026-05-18T17:52:40.003Z
- Moved TODO → IN-PROGRESS.
- Created branch task/editor-05-submission-routing-prompts-vs-commands.
- Created worktree /home/ineersa/projects/agent-core-worktrees/editor-05-submission-routing-prompts-vs-commands.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/editor-05-submission-routing-prompts-vs-commands.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/editor-05-submission-routing-prompts-vs-commands.

## Task workflow update - 2026-05-18T17:52:59.013Z
- Recorded fork run: hc0ewcxbrjlg
- Summary: Launched background fork to implement EDITOR-05 in worktree /home/ineersa/projects/agent-core-worktrees/editor-05-submission-routing-prompts-vs-commands.
