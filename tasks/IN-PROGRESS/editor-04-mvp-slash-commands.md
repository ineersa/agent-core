# EDITOR-04 MVP slash command registry and built-in commands

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: yes.

Scope:
- Add a small slash command registry/executor.
- Implement /help, /clear, and /exit.
- Expose command metadata: name, aliases, one-line description, and usage text for /help and future completion.
- Provide an extension seam so later AI/model tasks can add commands such as /model without rewriting editor/submission routing.

Exclusions:
- No InteractiveMode routing; EDITOR-05 owns invoking the registry from submitted text.
- No slash completion menu; EDITOR-08 owns completion.
- No /model implementation unless already explicitly requested by an AI/model task.
- No shell command execution.

Dependencies: EDITOR-03.
Parallelizable with: EDITOR-02.

## Acceptance criteria
- Registry can register and look up slash commands by name and aliases.
- /help returns command help using registry metadata.
- /clear and /exit return typed effects that the application layer can apply.
- Unknown command returns a friendly typed error/result, not an exception path for normal user input.
- Tests cover registration, aliases, built-ins, help output, and unknown command behavior.
- castor deptrac passes.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/editor-04-mvp-slash-commands
Worktree: /home/ineersa/projects/agent-core-worktrees/editor-04-mvp-slash-commands
Fork run:
PR URL:
PR Status:
Started: 2026-05-18T17:10:23.254Z
Completed:

## Work log
- Created: 2026-05-18T00:15:27.554Z

## Task workflow update - 2026-05-18T17:10:23.254Z
- Moved TODO → IN-PROGRESS.
- Created branch task/editor-04-mvp-slash-commands.
- Created worktree /home/ineersa/projects/agent-core-worktrees/editor-04-mvp-slash-commands.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/editor-04-mvp-slash-commands.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/editor-04-mvp-slash-commands.
