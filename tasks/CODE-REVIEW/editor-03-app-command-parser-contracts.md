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
Status: CODE-REVIEW
Branch: task/editor-03-app-command-parser-contracts
Worktree: /home/ineersa/projects/agent-core-worktrees/editor-03-app-command-parser-contracts
Fork run: 247idonc6pda
PR URL: https://github.com/ineersa/agent-core/pull/22
PR Status: open
Started: 2026-05-18T01:41:16.433Z
Completed:

## Work log
- Created: 2026-05-18T00:15:20.183Z

## Task workflow update - 2026-05-18T01:41:16.433Z
- Moved TODO → IN-PROGRESS.
- Created branch task/editor-03-app-command-parser-contracts.
- Created worktree /home/ineersa/projects/agent-core-worktrees/editor-03-app-command-parser-contracts.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/editor-03-app-command-parser-contracts.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/editor-03-app-command-parser-contracts.

## Task workflow update - 2026-05-18T01:54:58.390Z
- Recorded fork run: 247idonc6pda
- Validation: phpunit: 22 tests, 60 assertions — all pass; deptrac: 0 violations, 0 errors
- Summary: EDITOR-03 complete. Created pure command parser and result contracts under src/Tui/Command/:
- CommandParseResult interface + 3 variants (NormalPrompt, SlashCommand, ShellCommand)
- CommandParser with parse(string): CommandParseResult — handles /, !, !! prefixes
- CommandResult interface + 6 variants (NoOp, TranscriptMessage, ClearTranscript, ExitApplication, StatusUpdate, DispatchRuntime)
- 22 unit tests all passing, deptrac clean (0 violations)
- Added TuiCommand deptrac layer to depfile.yaml

## Task workflow update - 2026-05-18T01:55:12.616Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/editor-03-app-command-parser-contracts to origin.
- branch 'task/editor-03-app-command-parser-contracts' set up to track 'origin/task/editor-03-app-command-parser-contracts'.
- Created PR: https://github.com/ineersa/agent-core/pull/22
