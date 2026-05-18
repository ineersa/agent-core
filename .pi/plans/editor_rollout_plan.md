# Hatfield TUI Editor Rollout Plan

## Goal

Build a dedicated Hatfield prompt editor for the Symfony TUI application instead of relying forever on the stock `EditorWidget` behavior. The editor should support normal coding-agent workflows: multiline input, reliable terminal key handling, prompt history, slash commands, file mentions, shell-command prefixes, paste handling, and later configurable keybindings.

This plan intentionally separates **editor mechanics** from **application commands**. The editor owns text state, cursor movement, viewport/scrolling, paste markers, completion UI, and key dispatch. The TUI/application layer interprets submitted text (`/`, `!`, `!!`) and sends runtime commands.

## Current state

- `src/Tui/Application/InteractiveMode.php` now uses Symfony TUI's real `Tui::run()` loop.
- The current prompt input uses Symfony `EditorWidget` directly.
- Session/history persistence is being implemented separately.
- Tmux e2e harness exists under `tests/Tui/E2E/` and explicit tasks exist:
  - `castor test:tui`
  - `castor test:tui-update`
- Normal QA excludes tmux e2e via `castor test` / `castor check`.

## Design principles

1. **Editor state is owned by Hatfield.** Use a first-class state model instead of a single text string plus opaque widget internals.
2. **Application semantics stay outside the editor.** Slash commands, shell commands, and runtime submission are interpreted after submit.
3. **Terminal behavior must be testable.** Most editor logic should be pure PHP unit-testable without tmux; tmux e2e validates real terminal integration.
4. **Start small, keep extension seams.** Hardcode defaults first, but structure keybindings/actions so user config can override them later.
5. **No fragile Shift+Enter requirement.** `Enter` submits, `Ctrl+J` inserts newline.

## Target module layout

Revised after Symfony TUI audit (2026-05-18). Items struck through are provided by Symfony TUI and will NOT be built.

```text
src/Tui/Editor/
  EditorState.php              ✅ BUILT — lightweight snapshot DTO for session persistence
  PromptEditor.php             ✅ BUILT — thin facade over Symfony EditorWidget (DI service)
  PromptEditorWidget.php       ✅ BUILT — static TuiWidget renderable for ChatLayout
~~EditorViewport.php~~           ❌ NOT NEEDED — Symfony EditorViewport handles scroll/growth/indicators
~~EditorAction.php~~             ❌ NOT NEEDED — EditorWidget dispatches 36 actions internally
~~EditorKeymap.php~~             ❌ NOT NEEDED — reuse Symfony\Component\Tui\Input\Keybindings
~~EditorInputRouter.php~~        ❌ NOT NEEDED — EditorWidget::handleInput() is the router
  PromptHistory.php            # in-memory/session-backed prompt history navigation
~~Paste/PasteStore.php~~         ❌ NOT NEEDED — EditorDocument::handlePaste() + getText() handles all paste
~~Paste/PasteMarker.php~~        ❌ NOT NEEDED — EditorDocument creates [paste #N +M lines <hex>]
  Completion/
    CompletionProvider.php
    CompletionSuggestion.php
    SlashCommandCompletionProvider.php
    FileMentionCompletionProvider.php
    CompletionState.php
```

Tests:

```text
tests/Tui/Editor/
  EditorStateTest.php          ✅ BUILT
  PromptEditorTest.php         ✅ BUILT
  PromptEditorWidgetTest.php   ✅ BUILT
  PromptEditorHistoryTest.php  # TODO: EDITOR-07
  PromptEditorCompletionTest.php # TODO: EDITOR-08

tests/Tui/E2E/
  TuiEditorInteractionTest.php   # tmux opt-in group
```

## Core editor state

**IMPLEMENTED (EDITOR-01).** `EditorState` is a lightweight immutable snapshot DTO:

```php
final readonly class EditorState
{
    private array $lines;  // exposed via getLines()
    // cursorLine/cursorColumn removed — EditorDocument is @internal so cursor can't be captured
    // @todo EDITOR-07: restore cursor fields when session persistence captures live cursor
}
```

`PromptEditor` is a thin facade over Symfony TUI's `EditorWidget`:
- Constructor creates `EditorWidget` internally (autowireable DI service)
- `extract()` returns text + clears (renamed from `submit()` to avoid SubmitEvent confusion)
- `getState()` returns `EditorState` snapshot
- All text mutation, cursor movement, undo/redo, kill ring, word navigation handled by `EditorWidget`/`EditorDocument`
- **Do NOT reimplement any text buffer or cursor operations.**

## Viewport and growth

**COVERED BY SYMFONY TUI — NO CUSTOM CODE NEEDED.**

Symfony TUI provides:
- `EditorWidget::setMinVisibleLines(1)` / `setMaxVisibleLines(10)` for growth bounds
- `EditorViewport::computeViewport()` for scroll offset and cursor visibility
- `EditorViewport::pageScroll()` for PageUp/PageDown
- `EditorRenderer::render()` for `─── ↑ 3 more ───` / `─── ↓ 2 more ───` scroll indicators
- `EditorWidget::render()` uses `(int) floor($terminalRows * 0.3)` formula

Configuration during DI wiring:
```php
$promptEditor->setMinVisibleLines(1)->setMaxVisibleLines(10);
```

~EDITOR-06 CANCELLED~ — task eliminated, viewport config folded into EDITOR-02.

## Keybinding defaults

Initial hardcoded defaults, later configurable through `.hatfield/settings.yaml`.

| Key | Action |
|-----|--------|
| Printable text | insert at cursor |
| Enter | submit prompt |
| Ctrl+J | insert newline |
| Backspace | delete before cursor |
| Delete | delete after cursor |
| Left/Right | move cursor by grapheme/column |
| Up/Down | move cursor vertically; if editor empty, navigate prompt history |
| Home | move to start of current logical row |
| End | move to end of current logical row |
| PageUp/PageDown | scroll editor viewport if editor focused |
| Tab | trigger/accept completion |
| Escape | close completion/cancel current editor mode |
| Ctrl+C | clear editor / cancel current input |
| Ctrl+D | exit TUI when editor is empty; otherwise delete forward or ask? final behavior TBD |

Global TUI behavior remains:

- Ctrl+D exits cleanly.
- Ctrl+C twice exits.
- Single Ctrl+C clears editor/cancels state.

Open decision: whether Ctrl+D should always exit globally or only exit when editor is empty. Current app behavior is always exit.

## Prompt history

Prompt history should be session-aware.

Behavior:

- Submitted prompts are appended to session history.
- Empty editor + Up loads previous prompt.
- Empty editor + Down moves forward through history.
- If editing a multiline history entry, Up/Down navigates inside it until cursor is at first/last visual line.
- Once user types normal input, history navigation mode exits.

MVP can implement only:

- empty editor + Up/Down cycles submitted prompts.
- later: visual-line-aware history behavior.

## Paste handling

**COVERED BY SYMFONY TUI — NO CUSTOM CODE NEEDED.**

Symfony TUI provides:
- `BracketedPasteTrait` — ESC[200~/ESC[201~ detection and chunk buffering
- `EditorDocument::handlePaste()` — small paste inserts at cursor; large paste (>10 lines) creates `[paste #N +M lines <hex>]` marker
- `EditorDocument::getText()` — auto-expands paste markers via `strtr()` on submit

~EDITOR-10 CANCELLED~ — task eliminated. Only session-attachment storage of paste payloads might be needed later (tiny follow-up if ever required).

### Image paste

Later phase — not covered by Symfony TUI. Would need custom handling if implemented.

## Completion

### Slash commands

Trigger when `/` appears at start of editor text or after a newline at column 0.

Examples:

```text
/help
/clear
/exit
/sessions
/resume <id>
/theme <name>
```

Editor only provides suggestions and replacement. Application layer executes command after submit.

### File mentions with `@`

Trigger when `@` appears at a token boundary anywhere in editor text.

Behavior:

- `@src/Tui` opens fuzzy suggestions over CWD.
- Prefer `fd` if installed, respecting `.gitignore`.
- Fallback to PHP filesystem traversal.
- Insert quoted paths if necessary:

```text
@"path with spaces/file.php"
```

### Tab

- If completion menu open: accept selected item.
- If slash/file context detected: force completion.
- Otherwise later: indent? For coding agent prompt, prefer completion/no-op.

## Command prefixes

Parsed after submit by application layer, not editor.

| Prefix | Meaning |
|--------|---------|
| `/` | slash command |
| `!` | run bash and include output in context |
| `!!` | run bash but do not include output in context |
| `@` | file mention/completion inside prompt |

This keeps editor reusable and prevents command semantics from leaking into text-editing logic.

## Phase plan

### Phase 1 — Pure editor model ✅ DONE

Delivered:
- `EditorState` — lightweight immutable snapshot DTO
- `PromptEditor` — thin facade over Symfony TUI `EditorWidget`
- `PromptEditorWidget` — static TuiWidget renderable
- 52 unit tests, deptrac/CS/PHPStan clean

### Phase 2 — DI wiring and integration (EDITOR-02)

Deliver:
- Register `PromptEditor` in `services.yaml`
- Inject into `ChatScreen` instead of inline `new EditorWidget()`
- Wire `SubmitListener`, `CancelListener`, `CtrlCInputInterceptor` through `PromptEditor`
- Configure viewport defaults: min 1 line, max 10 lines

~~### Phase 3 — Viewport/growth/scrolling~~ ❌ ELIMINATED

Covered 100% by Symfony TUI: EditorViewport, EditorRenderer, EditorWidget.
Viewport config (setMinVisibleLines/setMaxVisibleLines) folded into EDITOR-02.

### Phase 3 (was 4) — Prompt history (EDITOR-07)

Deliver:
- session-backed prompt history loading.
- empty editor Up/Down history navigation (intercept before EditorWidget).
- prompt history persisted alongside session transcript.
- e2e: submit prompt, exit, resume, Up restores prompt.

### Phase 4 (was 5) — Completion foundation (EDITOR-08)

Deliver:
- `CompletionProvider` interface.
- completion state/menu rendering.
- slash command provider.
- file mention provider for `@` using `fd` fallback.
- Tab accept/trigger.

~~### Phase 6 — Paste markers~~ ❌ ELIMINATED

Covered 95% by Symfony TUI: BracketedPasteTrait, EditorDocument::handlePaste(),
EditorDocument::getText() marker expansion.

### Phase 5 (was 7) — App command handling (EDITOR-04 + EDITOR-05 + EDITOR-11)

Deliver:
- `/help`, `/clear`, `/exit` (EDITOR-04).
- Submission routing: prompts → runtime, slash commands → registry (EDITOR-05).
- `!` / `!!` bash execution routing (EDITOR-11).

### Phase 6 (was 8) — Configurable keybindings (EDITOR-12)

Deliver:
- YAML → `Symfony\Component\Tui\Input\Keybindings` loader from Hatfield settings.
- Conflict detection.
- Footer key hints from active keymap.
- Documentation updates.

Do NOT build EditorKeymap — reuse Symfony TUI Keybindings class.

## Implementation order and task graph

Tasks are intentionally small enough for smaller models. Use the `EDITOR-*` prefix for task files.

### Minimal MVP scope with commands

The MVP should be useful for upcoming AI/TUI tasks without waiting for the full editor roadmap.

MVP includes:

- owned prompt text state and cursor mechanics;
- a `PromptEditorWidget` replacing direct Symfony `EditorWidget` usage;
- current visible behavior preserved: `Enter` submits, `Ctrl+J` newline, `Ctrl+C` clears/cancels input, `Ctrl+D` exits;
- after-submit command parsing for `/` commands;
- a small slash command registry/executor;
- MVP commands: `/help`, `/clear`, `/exit`;
- command extension seam so later AI/model tasks can add commands such as `/model` without rewriting submission routing;
- deterministic unit tests and at least one opt-in tmux smoke/e2e scenario if rendering changes.

MVP explicitly excludes:

- file mention completion;
- slash command completion UI;
- prompt history persistence;
- paste markers;
- shell prefixes `!` / `!!`;
- configurable keybindings;
- full command palette/modal UI.

### Task list

| Task | Title | Depends on | Can run in parallel with | MVP? | Notes |
|------|-------|------------|--------------------------|------|-------|
| EDITOR-01 | Editor state snapshot and PromptEditor facade | none | EDITOR-03 | yes | ✅ DONE. Thin facade over Symfony EditorWidget. |
| EDITOR-02 | Wire PromptEditor facade into ChatScreen/Listeners via DI | EDITOR-01 | EDITOR-04 | yes | DI registration, listener wiring, viewport config. |
| EDITOR-03 | App command parser and command result contracts | none | EDITOR-01 | yes | ✅ DONE. Pure after-submit parser for `/`, `!`, `!!`. |
| EDITOR-04 | MVP slash command registry and built-in commands | EDITOR-03 | EDITOR-02 | yes | `/help`, `/clear`, `/exit`; extension seam for AI/model commands. |
| EDITOR-05 | Submission routing for prompts vs commands | EDITOR-02, EDITOR-04 | none after deps | yes | Normal prompts go to runtime; slash commands execute locally. |
| ~~EDITOR-06~~ | ~~Editor viewport, growth, and internal scrolling~~ | — | — | — | ❌ CANCELLED. 100% covered by Symfony TUI EditorViewport/EditorRenderer. |
| EDITOR-07 | Prompt history navigation and session persistence | EDITOR-05 | EDITOR-08 | no | Empty editor Up/Down, session-aware history. |
| EDITOR-08 | Completion foundation and slash command completion | EDITOR-03, EDITOR-04 | EDITOR-07 | no | Provider API, completion state/menu, Tab accept/trigger for slash commands. |
| EDITOR-09 | File mention completion and resolution | EDITOR-08 | none | no | `@` provider, `fd` fallback, quoted path insertion. |
| ~~EDITOR-10~~ | ~~Paste store and paste marker handling~~ | — | — | — | ❌ CANCELLED. 95% covered by Symfony TUI BracketedPasteTrait + EditorDocument. |
| EDITOR-11 | Shell command prefixes `!` and `!!` | EDITOR-03, EDITOR-05, TOOLS-09, RTVS-04, RTVS-07 | EDITOR-08, EDITOR-09 | no | Route submitted shell prefixes through bash/tool transcript flow. |
| EDITOR-12 | Hatfield keybinding loader, conflict detection, and editor smoke | EDITOR-02, EDITOR-05, EDITOR-07 | none after deps | no | YAML → Keybindings loader, conflict detection, footer hints, docs. |

### Dependency waves

1. **MVP foundation** ✅ DONE
   - EDITOR-01 and EDITOR-03 completed.

2. **MVP wiring**
   - EDITOR-02 and EDITOR-04 can run in parallel.
   - EDITOR-05 waits for both.

3. **History and completion**
   - EDITOR-07 waits for EDITOR-05 (records submitted prompts).
   - EDITOR-08 waits for EDITOR-03/04 (command provider for completion).
   - EDITOR-07 and EDITOR-08 can run in parallel.

4. **File mentions**
   - EDITOR-09 waits for EDITOR-08.

5. **Shell prefixes**
   - EDITOR-11 waits for EDITOR-05 + TOOLS-09 + RTVS-04 + RTVS-07.

6. **Configuration and final docs**
   - EDITOR-12 waits for EDITOR-02, EDITOR-05, EDITOR-07.

~~EDITOR-06 (viewport) and EDITOR-10 (paste) eliminated — covered by Symfony TUI.~~

### Parallelization guidance

Safe parallel tracks:

- EDITOR-02 and EDITOR-04 (Wave 2).
- EDITOR-07 and EDITOR-08 (Wave 3).

Avoid parallel edits to:

- `InteractiveMode` / `SubmitListener`: serialize EDITOR-02 and EDITOR-05 changes.
- session persistence/history files: serialize EDITOR-07 with any session storage changes.

### AI-task command seam

AI/model tasks should not have to modify low-level editor mechanics. They should register commands through the command registry introduced by EDITOR-04 and routed by EDITOR-05.

Initial command extension shape should support:

- command name and aliases;
- one-line description for `/help` and future completion;
- argument string passed after the command name;
- result type such as no-op, transcript message, status update, clear transcript, exit app, or dispatch runtime command;
- dependency-safe implementation: `src/Tui/` may depend only on runtime contracts/protocols, not AgentCore internals.

Potential follow-up AI/model commands, outside the editor MVP unless a task explicitly adds them:

- `/model` or `/models` for model selection;
- `/thinking` for thinking level;
- `/provider` if multiple providers are exposed;
- `/cost` or `/usage` once runtime usage metadata exists;
- `/resume` and `/sessions` once session browsing is ready.

## Testing strategy

### Unit tests first

Most editor behavior should be unit-tested without tmux:

- insert/delete
- newline split/merge
- cursor movement
- viewport scroll
- history navigation
- completion filtering
- paste marker behavior

### Tmux e2e tests second

Use `#[Group('tui-e2e')]` and `castor test:tui` only.

Scenarios:

1. startup snapshot renders editor.
2. type single-line prompt, Enter submits, transcript updates.
3. type multiline prompt using Ctrl+J, Enter submits.
4. Up on empty editor recalls prompt history.
5. Ctrl+C clears editor.
6. Ctrl+D exits cleanly.
7. PageUp/PageDown scroll editor with long input.
8. `@` opens file completion after provider exists.

Do not add tmux e2e to `castor check` until stable across environments.

## Documentation updates required

Update as phases land:

- `docs/tui-architecture.md`
  - editor state model
  - keybindings
  - completion/paste model
- `docs/tui-testing.md`
  - new tmux e2e scenarios
  - snapshot update instructions
- `docs/settings.md`
  - keybinding config once added
- `AGENTS.md`
  - source layout if new editor module added
  - default keybinding summary if behavior changes

## Open questions

1. Should Ctrl+D always exit, or exit only when editor is empty?
2. Should Ctrl+C clear editor immediately, or first show confirmation if editor has content?
3. Should Enter always submit, or should future config allow Enter newline / Ctrl+Enter submit?
4. Where should paste/image attachments live: session `attachments/` directory or global `.hatfield/tmp/attachments/` before session exists?
5. Should file mention search include hidden files by default? pi uses `fd --hidden --exclude .git`.
6. Should prompt history be per session, global, or both?

## Recommended next implementation step

After session persistence lands, start with **Phase 1 + Phase 2** in one fork:

- create `src/Tui/Editor/EditorState.php`
- create `src/Tui/Editor/PromptEditor.php`
- create `src/Tui/Editor/PromptEditorWidget.php`
- replace Symfony `EditorWidget` in `InteractiveMode`
- preserve current visible behavior and keybindings
- add focused unit tests
- update startup golden snapshot only if visible output changes

Avoid completions/paste/bash commands in the first editor fork. Get the owned editor state into the app first, then iterate.
