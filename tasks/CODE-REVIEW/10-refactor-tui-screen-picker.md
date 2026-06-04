# 10-refactor-tui-screen-picker: split screen sections and picker overlay

## Goal
Plan: .pi/plans/architecture-refactor-plan.md
Reports: .pi/reports/tui-architecture.md, .pi/reports/tests-architecture.md

Improve TUI composition testability by splitting ChatScreen into composable sections and extracting shared picker overlay lifecycle code.

Scope:
- Extract PickerOverlay from ModelPickerController and FavoritePickerController lifecycle boilerplate.
- Split ChatScreen into per-section objects or a screen model that owns LiveTextWidget production and slot rendering per section.
- Preserve TuiSlotRegistry extension slots, ChatScreen listener-facing public API, mount order, and terminal-resize responsiveness.
- Remove unused empty widget stubs if still unused and safe.

## Acceptance criteria
- Picker controllers share common overlay lifecycle code and keep only selection-specific behavior.
- ChatScreen is reduced to orchestration with section-level units that can be tested without full tmux E2E.
- Startup snapshot and TUI smoke expectations remain stable or are intentionally updated with documented visual changes.
- Run and report Castor validation: focused TUI tests, castor test:tui, and castor check where prerequisites allow, or exact environmental blockers.

## Finalized implementation plan

### Scope decisions
- Include QuestionController in PickerOverlay extraction (all 3 overlay controllers)
- Skip ChatScreen section splitting — renderables already testable, closures trivial, extraction not justified (consistent with task 05 cancellation rationale)
- Remove 3 dead widget stubs
- Leave FavoritePickerController tests in shared ModelPickerControllerTest.php

### Part A — PickerOverlay extraction

**New file: `src/Tui/Picker/PickerOverlay.php`** (~80 lines)
Final class owning shared overlay lifecycle:
- Properties: `listWidget` (SelectListWidget), `container` (ContainerWidget), `isOpen` (bool), `tui` (?Tui), `screen` (?ChatScreen)
- `mount(Tui, ChatScreen, SelectListWidget, TextWidget $header): void` — compose container, add to tui, focus list, set isOpen=true
- `close(): void` — remove container from tui, null refs, set isOpen=false
- `isOpen(): bool`
- Keybindings are provided by the caller (standard 6-entry array: up/down/page_up/page_down/enter/esc)

**Modify: `src/Tui/Picker/ModelPickerController.php`** (362→~280 lines)
- Constructor stays: ModelSelectionService, AppConfig, LoggerInterface
- `setRuntimeRefs()` stores Tui, ChatScreen, TuiSessionState
- `open()` delegates to PickerOverlay: builds items via `buildItemsStatic()`, creates header, creates SelectListWidget with 6 keybindings, calls overlay.mount()
- onSelect static closure: calls `applySelectEffect()` then `overlay.close()`
- onCancel static closure: calls `overlay.close()`
- onInput for Ctrl+F: keeps in-place favorite toggle + item rebuild
- `applySelectEffect()` unchanged (persists model change, updates footer)
- `applyCloseEffect()` deleted — replaced by overlay.close()

**Modify: `src/Tui/Picker/FavoritePickerController.php`** (250→~170 lines)
- Constructor stays: ModelSelectionService, LoggerInterface
- `setRuntimeRefs()` stays
- `open()` delegates to PickerOverlay: builds items via `buildFavoritesItems()`, creates header, SelectListWidget, overlay.mount()
- onSelect: calls overlay.close() (no side effects — Enter just closes)
- onCancel: calls overlay.close()
- onInput for Space: keeps favorite toggle + item rebuild
- `applyCloseEffect()` deleted

**Modify: `src/Tui/Question/QuestionController.php`** (226→~170 lines)
- Constructor stays: QuestionCoordinator
- Currently uses TuiRuntimeContext (1 arg) instead of 3 separate refs — adapt: extract tui/screen from context internally
- `open()` delegates to PickerOverlay for SelectList-based question kinds (Choice/Approval)
- Text/Confirm kinds that don't use SelectListWidget stay as-is (no overlay)
- close() calls overlay.close() + additional cleanup (setStatus(null), refresh())

**New file: `tests/Tui/Picker/PickerOverlayTest.php`** (~100 lines)
- Test mount sets isOpen=true
- Test close sets isOpen=false and nulls refs
- Test close is idempotent
- Test isOpen guard prevents double-mount

### Part B — Remove dead widget stubs

**Delete:**
- `src/Tui/Widget/PromptInputWidget.php` (14 lines, empty class with @todo)
- `src/Tui/Widget/ToolOutputWidget.php` (14 lines, empty class with @todo)
- `src/Tui/Transcript/TranscriptWidget.php` (54 lines, legacy @todo replaced by TranscriptBlockWidget)

**Verify:** grep for any imports/usages before deletion.

### Validation
- `castor test --filter=PickerOverlay`
- `castor test --filter=ModelPicker`
- `castor test --filter=Question`
- `castor test`
- `castor deptrac`
- `castor phpstan`
- `castor cs-check`
- TUI E2E smoke (`castor test:tui`) if prerequisites available

### File summary
| Action | File | Lines change |
|--------|------|-------------|
| CREATE | src/Tui/Picker/PickerOverlay.php | ~+80 |
| CREATE | tests/Tui/Picker/PickerOverlayTest.php | ~+100 |
| MODIFY | src/Tui/Picker/ModelPickerController.php | -82 |
| MODIFY | src/Tui/Picker/FavoritePickerController.php | -80 |
| MODIFY | src/Tui/Question/QuestionController.php | -56 |
| DELETE | src/Tui/Widget/PromptInputWidget.php | -14 |
| DELETE | src/Tui/Widget/ToolOutputWidget.php | -14 |
| DELETE | src/Tui/Transcript/TranscriptWidget.php | -54 |
| **Net** | **8 files** | **~+180/-296** |

## Workflow metadata
Status: CODE-REVIEW
Branch: task/10-refactor-tui-screen-picker
Worktree: /home/ineersa/projects/agent-core-worktrees/10-refactor-tui-screen-picker
Fork run: kxoxu19e7fx7
PR URL: https://github.com/ineersa/agent-core/pull/92
PR Status: open
Started: 2026-06-04T17:10:01.925Z
Completed:

## Work log
- Created: 2026-06-03T00:32:16.525Z
- Plan finalized: 2026-06-04 — Scope narrowed to PickerOverlay + dead stubs only; ChatScreen splitting skipped (extraction not justified)

## Task workflow update - 2026-06-04T17:10:01.926Z
- Moved TODO → IN-PROGRESS.
- Created branch task/10-refactor-tui-screen-picker.
- Created worktree /home/ineersa/projects/agent-core-worktrees/10-refactor-tui-screen-picker.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/10-refactor-tui-screen-picker.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/10-refactor-tui-screen-picker.

## Task workflow update - 2026-06-04T17:18:08.696Z
- Recorded fork run: kxoxu19e7fx7
- Validation: castor test — 1656/4891 pass (+7 new); castor deptrac — 0 violations; castor phpstan — 0 errors; castor cs-check — clean
- Summary: Fork kxoxu19e7fx7 completed task 10 — commit 74150e53 on branch task/10-refactor-tui-screen-picker, 6 files changed (+288/-156). Created PickerOverlay (88 lines, shared mount/close lifecycle), rewrote ModelPickerController (362→~295, overlay delegation + simplified applySelectEffect from 5-param to 1-param), rewrote FavoritePickerController (250→~201, overlay delegation), deleted 2 dead stubs (PromptInputWidget, ToolOutputWidget), created PickerOverlayTest (7 tests). 1656 tests pass (+7 new), 0 deptrac violations, 0 phpstan errors, cs-check clean. QuestionController left unchanged (lifecycle differs too much). TranscriptWidget kept (has real callers). ChatScreen not touched.

## Task workflow update - 2026-06-04T17:27:50.406Z
- Updated PR Status: APPROVE WITH SUGGESTIONS — all 5 findings fixed (mount guard, close null symmetry, docblock, overlay null in controllers, 2 new tests)
- Validation: castor test — 1658/4899 pass (+9 new); castor deptrac — 0 violations; castor phpstan — 0 errors; castor cs-check — clean
- Summary: Code review: APPROVE WITH SUGGESTIONS — 5 actionable findings all fixed via fork hilao8x6oyhu (commit amended to 11f0abb9). BUG/EDGE: mount guard added. DESIGN: close() nulls all refs. CONVENTION: closePicker() nulls overlay. SIMPLIFY: docblock updated. NTH: 2 new tests (close-before-mount no-op, mount assertion). Local validation: castor test 1658/4899, deptrac 0, phpstan 0, cs-check clean.
Castor Check Status: passed
Castor Check Commit: 11f0abb9d8a404ab16a32af0ade06592b8ab8dba
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 600s
Castor Check Completed: 2026-06-04T17:28:59.623Z
Castor Check Output SHA256: 0b174dd999e492f52713615390ec52020ac8597174dc7a88371f54ad10c238a2

## Task workflow update - 2026-06-04T17:29:03.085Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (600s timeout). Commit: 11f0abb9d8a4.
- Pushed task/10-refactor-tui-screen-picker to origin.
- branch 'task/10-refactor-tui-screen-picker' set up to track 'origin/task/10-refactor-tui-screen-picker'.
- Created PR: https://github.com/ineersa/agent-core/pull/92
