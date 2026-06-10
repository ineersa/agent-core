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
Fork run: vb78x9cy1wdm
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

## Task workflow update - 2026-06-10T20:21:54.747Z
- Recorded fork run: x76yjmx0yw0f
- Validation: Fork x76yjmx0yw0f reported `castor test` passed (2303 tests, 6727 assertions); Fork x76yjmx0yw0f reported `castor deptrac` passed (0 violations), `castor phpstan` passed (0 errors), `castor cs-check` clean; Fork x76yjmx0yw0f reported focused Hotkey/Slash/Completion tests passed; Fork x76yjmx0yw0f reported `castor test:tui --filter=HotkeySmoke` failed on Ctrl+J multiline E2E due LLM exploration timeout; required TUI proof remains incomplete; Orchestrator verified worktree dirty/uncommitted after fork, so follow-up implementation fork required
- Summary: Implementation fork x76yjmx0yw0f returned partially complete and uncommitted. Core code/docs/tests are present in the worktree but dirty at HEAD 86966046. Implemented pieces reported by fork: Ctrl+J keybinding via PromptEditor/InteractiveMode, display-only HotkeyRegistry/HotkeyBindingDTO/HotkeyProviderInterface, app/editor hotkey registrars, `/hotkeys` slash command, docs, unit tests, and initial TUI E2E. Blocking issue: required TmuxHarness E2E proof is not passing — Ctrl+J multiline test waits for assistant output and times out due test LLM exploration loop. Additional cleanup needed: task file was modified under `tasks/TODO/...` in the implementation branch even though workflow state is IN-PROGRESS; follow-up should revert task-file changes from the branch and leave task metadata to workflow tools.

## Task workflow update - 2026-06-10T20:22:26.177Z
- Recorded fork run: vb78x9cy1wdm
- Summary: Launched follow-up fork vb78x9cy1wdm to finish revised EDITOR-12 after partial fork x76yjmx0yw0f. Scope: keep previous core implementation, revert unintended task-file changes from the task branch, fix HotkeySmoke TmuxHarness E2E so Ctrl+J multiline proof avoids LLM exploration by using shell-prefix output/canonical events, validate `/hotkeys` E2E, run Castor validation, commit intended source/docs/tests, leave worktree clean. Parent will verify fork result before any CODE-REVIEW workflow.

## Task workflow update - 2026-06-10T20:28:59.495Z
- Recorded fork run: vb78x9cy1wdm
- Validation: Fork vb78x9cy1wdm validation: `castor deptrac` passed (0 violations, 0 errors); Fork vb78x9cy1wdm validation: `castor phpstan` passed (0 errors); Fork vb78x9cy1wdm validation: `castor cs-check` clean; Fork vb78x9cy1wdm validation: focused `castor test --filter='HotkeyRegistry|SlashCommandRegistry|SlashCommandCompletion|CompletionListener|PromptEditor'` passed (127 tests, 235 assertions); Fork vb78x9cy1wdm validation: `castor test:tui --filter=HotkeySmoke` passed (2 tests, 6 assertions); Fork vb78x9cy1wdm validation: full `castor test` passed (2303 tests, 6727 assertions); Fork vb78x9cy1wdm validation: full `castor test:tui` passed (13 tests, 36 assertions); E2E proof: Ctrl+J test uses multiline shell-prefix command where marker only appears if Ctrl+J inserted a newline and entire multiline shell command executed; verifies pane output and `tool_execution_end.payload.result` in events.jsonl; E2E proof: `/hotkeys` test asserts table renders with Ctrl+J, Submit prompt, Clear editor, and Insert newline
- Summary: Implementation complete at commit 4dc9b352. Revised EDITOR-12 scope delivered: Ctrl+J newline support (with Shift+Enter preserved), display-only HotkeyRegistry/HotkeyBindingDTO/HotkeyProviderInterface catalog, app/editor hotkey registrars, `/hotkeys` slash command table, docs updates, and real TmuxHarness E2E proof. Original YAML keybinding loader/conflict-detection/footer-hints/full-router scope remains excluded. Extension hotkey support is non-breaking display-only via provider seam; no ExtensionApi interface changes. Task-file and ChatScreen accidental changes were reverted from the branch. Orchestrator verified worktree clean at 4dc9b352 and integration checkout clean at bfc3d5f6. Diff stat: 17 files changed, 1105 insertions, 16 deletions.
