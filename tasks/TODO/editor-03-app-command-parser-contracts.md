# EDITOR-03 App command parser and command result contracts

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: yes.

Scope:
- Add pure after-submit parser for command prefixes.
- Recognize slash commands at the start of submitted text.
- Define command parse result/value objects for command name, raw args, and original text.
- Define command execution result contracts suitable for local effects such as no-op, transcript message, status update, clear transcript, exit app, or dispatch runtime command.
- Parse ! and !! prefixes enough to reserve their semantics, but do not execute shell commands in MVP.

Exclusions:
- No built-in command implementations; EDITOR-04 owns those.
- No InteractiveMode submission routing; EDITOR-05 owns that.
- No completion UI.
- No AgentCore dependency from Tui.

Dependencies: none.
Parallelizable with: EDITOR-01.

## Acceptance criteria
- Parser distinguishes normal prompts, slash commands, !, and !! prefixes deterministically.
- Slash command parse result includes command name and raw argument string.
- Command result contracts are independent of editor mechanics and AgentCore internals.
- Unit tests cover normal prompts, slash commands, unknown/empty command strings, !, and !!.
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
- Created: 2026-05-18T00:15:20.183Z
