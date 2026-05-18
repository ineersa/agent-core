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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-18T00:15:27.554Z
