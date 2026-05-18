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

```text
src/Tui/Editor/
  EditorState.php              # logical lines, cursor line/column, scroll offset
  EditorViewport.php           # visible height/width calculation and scroll state
  EditorAction.php             # enum/action ids for key dispatch
  EditorKeymap.php             # default key/action mapping, future configurable overrides
  EditorInputRouter.php        # raw input -> EditorAction / printable / paste
  PromptEditor.php             # stateful editor model + operations
  PromptEditorWidget.php       # Symfony TUI widget adapter/rendering
  PromptHistory.php            # in-memory/session-backed prompt history navigation
  Paste/
    PasteStore.php             # stores paste payloads and returns paste ids
    PasteMarker.php            # `[paste #1 +123 lines]` metadata
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
  EditorStateTest.php
  PromptEditorTextEditingTest.php
  PromptEditorCursorTest.php
  PromptEditorViewportTest.php
  PromptEditorHistoryTest.php
  PromptEditorPasteTest.php
  PromptEditorCompletionTest.php

tests/Tui/E2E/
  TuiEditorInteractionTest.php   # tmux opt-in group
```

## Core editor state

Use pi-style logical lines:

```php
final class EditorState
{
    /** @var list<string> */
    public array $lines = [''];
    public int $cursorLine = 0;
    public int $cursorColumn = 0;
    public int $scrollOffset = 0;
}
```

Rules:

- Text is stored as logical lines split by `\n`.
- Cursor coordinates are logical `(line, column)`.
- Rendering maps logical lines to visual wrapped lines.
- Cursor movement works on logical coordinates initially; visual-line/sticky-column behavior can be added after basic correctness.
- Public API exposes `getText()`, `setText()`, `insertText()`, `clear()`, `isEmpty()`.

## Viewport and growth

Editor starts as one visible line and grows upward/downward within the layout until max height.

Initial policy:

```php
$maxVisibleLines = min(20, max(3, (int) floor($terminalRows * 0.30)));
```

Behavior:

- If content takes fewer lines than max, render only needed height.
- Once content exceeds max, keep cursor visible by adjusting `scrollOffset`.
- Render scroll hints when hidden content exists:
  - top: `↑ 3 more`
  - bottom: `↓ 2 more`
- PageUp/PageDown scroll the editor while editor is focused.
- Later, when focus is outside the editor, PageUp/PageDown scroll transcript/history.

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

### Bracketed paste

Enable and parse bracketed paste through Symfony TUI/terminal events if exposed. If Symfony TUI does not expose paste events clearly, add raw input detection in `EditorInputRouter`:

```text
ESC [ 200 ~   paste start
ESC [ 201 ~   paste end
```

### Small paste

Small paste inserts directly at cursor, preserving newlines.

### Large paste

Large paste should not flood the editor. Thresholds:

- more than 10 lines, or
- more than 1000 characters

Insert marker:

```text
[paste #1 +123 lines]
[paste #2 1543 chars]
```

Store payload in memory first, later in session attachments:

```text
.hatfield/sessions/<session-id>/attachments/paste-0001.txt
```

Submitted prompt should expand paste markers into full payload for runtime context, while UI continues showing compact marker.

### Image paste

Later phase.

Target behavior:

- Ctrl+V attempts image paste first.
- If image exists, store under:

```text
.hatfield/sessions/<session-id>/attachments/image-0001.png
```

- Insert marker:

```text
[Image 1 (.hatfield/sessions/<id>/attachments/image-0001.png)]
```

If no image, treat as normal paste.

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

### Phase 1 — Pure editor model

Deliver:

- `EditorState`
- `PromptEditor`
- text insertion/deletion
- cursor left/right/home/end
- newline insertion via method
- submit text extraction
- clear/isEmpty
- unit tests

No Symfony widget integration yet except maybe a simple render method.

Validation:

```bash
castor test
castor check
```

### Phase 2 — Symfony TUI widget adapter

Deliver:

- `PromptEditorWidget` integrating `PromptEditor` with Symfony TUI.
- Replace direct `EditorWidget` usage in `InteractiveMode`.
- Preserve current keybindings: Enter submit, Ctrl+J newline, Ctrl+C clear, Ctrl+D exit.
- Render placeholder and cursor reasonably.

Validation:

```bash
castor test
castor test:tui
castor check
```

### Phase 3 — Viewport/growth/scrolling

Deliver:

- max visible lines based on terminal rows.
- editor grows from 1 line up to max.
- internal scroll offset keeps cursor visible.
- PageUp/PageDown scroll editor.
- scroll indicators.

Add unit tests for wrapping/scrolling and tmux e2e for long multiline prompt.

### Phase 4 — Prompt history

Deliver:

- session-backed prompt history loading.
- empty editor Up/Down history navigation.
- prompt history persisted alongside session transcript.
- e2e: submit prompt, exit, resume, Up restores prompt or transcript is visible.

Depends on session persistence landing first.

### Phase 5 — Completion foundation

Deliver:

- `CompletionProvider` interface.
- completion state/menu rendering.
- slash command provider.
- file mention provider for `@` using `fd` fallback.
- Tab accept/trigger.

### Phase 6 — Paste markers

Deliver:

- bracketed paste detection.
- small paste insert.
- large paste marker + payload store.
- marker-aware cursor movement/delete.
- submitted text expands markers.

### Phase 7 — App command handling

Deliver:

- `/help`, `/clear`, `/exit`.
- `!` / `!!` bash execution routing.
- render bash output as transcript/tool blocks.
- file mention resolution into runtime context.

### Phase 8 — Configurable keybindings

Deliver:

- `EditorKeymap` loaded from Hatfield settings.
- conflict detection.
- keybinding documentation.
- footer key hints generated from active keymap instead of hardcoded text.

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
| EDITOR-01 | Pure prompt editor state and operations | none | EDITOR-03 | yes | Text model, cursor movement, insert/delete/newline, submit/clear. |
| EDITOR-02 | PromptEditorWidget adapter and InteractiveMode integration | EDITOR-01 | EDITOR-04 | yes | Replaces direct Symfony `EditorWidget`; preserves current key behavior. |
| EDITOR-03 | App command parser and command result contracts | none | EDITOR-01, EDITOR-02 | yes | Pure after-submit parser for `/`, `!`, `!!`; only slash execution in MVP. |
| EDITOR-04 | MVP slash command registry and built-in commands | EDITOR-03 | EDITOR-02 | yes | `/help`, `/clear`, `/exit`; extension seam for AI/model commands. |
| EDITOR-05 | Submission routing for prompts vs commands | EDITOR-02, EDITOR-04 | none after deps | yes | Normal prompts go to runtime; slash commands execute locally. |
| EDITOR-06 | Editor viewport, growth, and internal scrolling | EDITOR-01, EDITOR-02 | EDITOR-03, EDITOR-04 | no | Max visible lines, scroll offset, PageUp/PageDown, indicators. |
| EDITOR-07 | Prompt history navigation and session persistence | EDITOR-05 | EDITOR-06, EDITOR-08 | no | Empty editor Up/Down, session-aware history. |
| EDITOR-08 | Completion foundation and slash command completion | EDITOR-03, EDITOR-04 | EDITOR-06, EDITOR-07 | no | Provider API, completion state/menu, Tab accept/trigger for slash commands. |
| EDITOR-09 | File mention completion and resolution | EDITOR-08 | EDITOR-10 | no | `@` provider, `fd` fallback, quoted path insertion. |
| EDITOR-10 | Paste store and paste marker handling | EDITOR-01, EDITOR-02 | EDITOR-09 | no | Bracketed paste, small/large paste, marker expansion on submit. |
| EDITOR-11 | Shell command prefixes `!` and `!!` | EDITOR-03, EDITOR-05, TOOLS-09, RTVS-04, RTVS-07 | EDITOR-08, EDITOR-09, EDITOR-10 | no | Route submitted shell prefixes through bash/tool transcript flow. |
| EDITOR-12 | Configurable keybindings, docs, and full editor smoke | EDITOR-05, EDITOR-06, EDITOR-07, EDITOR-08, EDITOR-10 | none after deps | no | Hatfield settings, conflict detection, docs, tmux e2e coverage. |

### Dependency waves

1. **MVP foundation**
   - EDITOR-01 and EDITOR-03 can start immediately in parallel.
   - EDITOR-02 follows EDITOR-01.
   - EDITOR-04 follows EDITOR-03 and can run in parallel with EDITOR-02.

2. **MVP integration**
   - EDITOR-05 waits for EDITOR-02 and EDITOR-04.
   - After EDITOR-05, the app has owned editor state plus local slash commands.

3. **Editor ergonomics**
   - EDITOR-06 can start after EDITOR-01/02 and does not need command routing.
   - EDITOR-07 waits for EDITOR-05 so it can record submitted prompts consistently.

4. **Completion and mentions**
   - EDITOR-08 waits for command parser/registry tasks (EDITOR-03/04).
   - EDITOR-09 waits for EDITOR-08.

5. **Paste and shell prefixes**
   - EDITOR-10 waits for editor state/widget integration.
   - EDITOR-11 waits for command routing and the tool/runtime transcript backbone: TOOLS-09, RTVS-04, and RTVS-07.

6. **Configuration and final docs**
   - EDITOR-12 waits until the behavior it documents/configures exists.

### Parallelization guidance

Safe parallel tracks:

- EDITOR-01 and EDITOR-03.
- EDITOR-02 and EDITOR-04 after their foundations are done.
- EDITOR-06 and EDITOR-08 after MVP foundations.
- EDITOR-09 and EDITOR-10 once completion/editor integration exists.

Avoid parallel edits to:

- `InteractiveMode` submission routing: serialize EDITOR-02 and EDITOR-05 changes.
- editor key dispatch/input routing: serialize EDITOR-02, EDITOR-05, EDITOR-06, and EDITOR-10 when they touch the same methods.
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
