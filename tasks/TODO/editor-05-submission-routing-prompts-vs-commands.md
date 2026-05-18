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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-18T00:15:36.255Z
