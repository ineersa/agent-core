# EDITOR-12 Configurable keybindings, docs, and full editor smoke

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: no.

Scope:
- Add EditorKeymap loading from Hatfield settings.
- Add keybinding conflict detection and clear error/status reporting.
- Generate footer key hints from active keymap instead of hardcoded text where applicable.
- Update docs/tui-architecture.md, docs/tui-testing.md, docs/settings.md, and AGENTS.md if behavior/source layout changed.
- Add/refresh tmux e2e scenarios for editor interactions.

Exclusions:
- Do not implement missing editor features solely for documentation.
- Do not add tmux e2e tests to castor check.
- Do not reintroduce FrameworkBundle or HTTP app assumptions.

Dependencies: EDITOR-05, EDITOR-06, EDITOR-07, EDITOR-08, EDITOR-10.
Parallelizable with: none after dependencies.

## Acceptance criteria
- Keybindings can be configured through Hatfield settings with documented defaults.
- Conflicting keybindings are detected and reported clearly.
- Footer/help hints reflect active keymap where practical.
- Docs are updated in all relevant locations.
- castor test:tui passes or snapshot update steps are documented when snapshots intentionally change.
- castor check passes.

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
- Created: 2026-05-18T00:16:39.944Z
