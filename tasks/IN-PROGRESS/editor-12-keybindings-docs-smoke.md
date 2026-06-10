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
Status: IN-PROGRESS
Branch: task/editor-12-keybindings-docs-smoke
Worktree: /home/ineersa/projects/agent-core-worktrees/editor-12-keybindings-docs-smoke
Fork run: x76yjmx0yw0f
PR URL:
PR Status:
Started: 2026-06-10T19:50:55.944Z
Completed:

## Work log
- Created: 2026-05-18T00:16:39.944Z
- Updated: 2026-05-18 — Scope simplified: reuse Symfony TUI Keybindings class, build only YAML loader + conflict detection. Removed EditorKeymap/EditorAction/EditorInputRouter from scope.

## Task workflow update - 2026-06-10T19:50:47.082Z
- Summary: Scope revised after planning discussion. Drop the original configurable YAML keybinding loader/conflict-detection scope as premature overhead. New scope: implement reliable multiline prompt newline with Ctrl+J (keep Shift+Enter only if Symfony already supports it), add an internal display/catalog HotkeyRegistry for core app/editor/completion/history/model hotkeys, add a `/hotkeys` slash command rendering a nice table of registered hotkeys, and optionally expose display-only extension hotkey hint registration if it fits current extension/TUI context. Do not build a full input router or user-configurable keymap. Execution remains in existing Symfony TUI widgets/listeners; registry is metadata/documentation/display only. Must include real TmuxHarness + test LLM E2E proof for Ctrl+J multiline input and `/hotkeys` visibility, plus docs updates.

## Task workflow update - 2026-06-10T19:50:55.945Z
- Moved TODO → IN-PROGRESS.
- Created branch task/editor-12-keybindings-docs-smoke.
- Created worktree /home/ineersa/projects/agent-core-worktrees/editor-12-keybindings-docs-smoke.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/editor-12-keybindings-docs-smoke.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/editor-12-keybindings-docs-smoke.
- Summary: Starting revised EDITOR-12 scope: Ctrl+J multiline prompt newline, internal metadata HotkeyRegistry, `/hotkeys` command table, docs, and real TmuxHarness E2E proof. Original YAML keybinding loader/conflict detection is explicitly dropped as premature overhead. Main agent remains orchestrator; implementation will be delegated to a fork.

## Task workflow update - 2026-06-10T19:52:22.922Z
- Recorded fork run: x76yjmx0yw0f
- Summary: Started implementation fork x76yjmx0yw0f in worktree /home/ineersa/projects/agent-core-worktrees/editor-12-keybindings-docs-smoke. Fork scope: revise task body to new scope, add Ctrl+J multiline newline support, add display-only HotkeyRegistry/catalog, add `/hotkeys` slash command table, optionally expose non-breaking display-only extension hotkey hints, update docs, add mandatory real TmuxHarness E2E proof for Ctrl+J multiline and `/hotkeys`, run Castor validation, commit changes, leave worktree clean. Explicitly excluded: YAML keybinding loader, conflict detection config system, full input router, consumable extension hotkeys.
