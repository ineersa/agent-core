# EDITOR-12 Hatfield keybinding loader, conflict detection, and editor smoke

## Goal
Plan: .pi/plans/editor_rollout_plan.md

MVP: no.

Scope:
- Build a YAML → `Symfony\Component\Tui\Input\Keybindings` loader that reads Hatfield settings.
- Add keybinding conflict detection (duplicate bindings across actions).
- Generate footer key hints from active keymap instead of hardcoded text where applicable.
- Apply loaded keybindings to `PromptEditor`'s `EditorWidget` via `setKeybindings()`.
- Update docs: `docs/tui-architecture.md`, `docs/tui-testing.md`, `docs/settings.md`, `AGENTS.md`.
- Add/refresh tmux e2e scenarios for editor interactions.

Rationale: Symfony TUI already has a full keybinding engine (`Keybindings` class, `KeybindingsTrait`, 36 default actions). We do NOT need to build `EditorKeymap` or `EditorInputRouter`. Only Hatfield YAML integration is new.

Exclusions:
- Do not build `EditorKeymap`, `EditorAction`, or `EditorInputRouter` — reuse Symfony TUI's `Keybindings`.
- Do not add tmux e2e tests to `castor check`.
- Do not reintroduce FrameworkBundle or HTTP app assumptions.

Dependencies: EDITOR-02, EDITOR-05, EDITOR-07.
Parallelizable with: none after dependencies.

## Acceptance criteria
- Keybindings can be configured through Hatfield settings with documented defaults.
- Conflicting keybindings are detected and reported clearly.
- Footer/help hints reflect active keymap where practical.
- Docs are updated in all relevant locations.
- `castor test:tui` passes or snapshot update steps are documented.
- `castor check` passes.

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
- Updated: 2026-05-18 — Scope simplified: reuse Symfony TUI Keybindings class, build only YAML loader + conflict detection. Removed EditorKeymap/EditorAction/EditorInputRouter from scope.
