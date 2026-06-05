# TUI Architecture Analysis Report

**Date**: 2026-06-02  
**Scope**: `src/Tui/` namespace (plus mirrored test evidence)  
**Method**: Read-only exploration per `improve-codebase-architecture` skill

---

## 1. Exploration Findings

### Overview

The `src/Tui/` namespace spans **79 PHP files** (~7,039 lines) across **16 modules**:
`Application`, `Command`, `Editor`, `Extension`, `Footer`, `Header`, `Layout`,
`Listener`, `Picker`, `Question`, `Runtime`, `Screen`, `Status`, `Theme`,
`Transcript`, `Widget`.

Test coverage: **24 unit/integration test files** (~4,993 lines) plus **4 E2E
tmux snapshot tests** (~1,393 lines). Five modules have zero unit tests:
`Application` (378 LOC), `Header` (42 LOC), `Runtime` (585 LOC), `Screen`
(440 LOC), `Status` (133 LOC).

The TUI is well-documented and has clear architectural boundaries enforced by
Deptrac. However, several structural friction points lower testability and
create shallow abstractions.

---

### Friction Point 1: Dual Widget Contract — `TuiWidget` vs Symfony `AbstractWidget`

Two parallel rendering paths exist:

| Path | Contract | Used by | Render mode |
|---|---|---|---|
| `ChatScreen` | Symfony `AbstractWidget` via `LiveTextWidget` | Production | Live terminal re-render |
| `ChatLayout` | `TuiWidget` interface | Tests + hypothetical static render | Static line list |

`ChatScreen` wraps **every** TUI widget in a `LiveTextWidget` producer closure
that calls the TuiWidget's `render()` method — 14 separate closures in its
constructor (`src/Tui/Screen/ChatScreen.php:94-264`). `ChatLayout`
(`src/Tui/Layout/ChatLayout.php:56-125`) does the same thing via a different
render path using `TuiSlotRegistry` directly, producing almost-identical output
but with no terminal-resize responsiveness.

**Consequence**: The two paths can diverge in rendering behavior. Testing
`ChatScreen` requires a full Symfony TUI instance (`Tui` + event loop). The
"testable" alternative (`ChatLayout`) doesn't actually get exercised by the
production code path.

**File evidence**:
- `src/Tui/Widget/TuiWidget.php:29` — The lightweight interface
- `src/Tui/Widget/LiveTextWidget.php:24-66` — Bridges TuiWidget→Symfony
- `src/Tui/Screen/ChatScreen.php:94-264` — 14 LiveTextWidget closures

---

### Friction Point 2: ChatScreen God Object

`ChatScreen` (`src/Tui/Screen/ChatScreen.php`, 440 lines) owns:

- 14 privately-held `LiveTextWidget` references (lines 47-73)
- 8 TUI renderables (HeaderWidget, TranscriptBlockWidget, etc.) (lines 75-81)
- `TuiSlotRegistry` + `SlotBasedTuiExtensionContext` (lines 83-84)
- The mount lifecycle and a large public API of 15+ methods (lines 266-430)

The constructor is 170 lines of closure definitions. Each producer closure
captures `$this` and mixes responsibilities: reading slot registry state,
calling `TuiWidget::render()`, building `TuiRenderContext` adapters, and
managing visibility flags.

**Consequence**: `ChatScreen` is untestable without a full `Tui` instance.
Replacing any widget or layout behaviour requires modifying this single file.

**File evidence**:
- `src/Tui/Screen/ChatScreen.php:40-264` — Constructor with 14 closures
- `src/Tui/Screen/ChatScreen.php:266-430` — Large public API surface

---

### Friction Point 3: Empty Stub Widgets

Two widget files are completely empty placeholders:

```php
// src/Tui/Widget/PromptInputWidget.php:12-14
final class PromptInputWidget
{
}

// src/Tui/Widget/ToolOutputWidget.php:12-14
final class ToolOutputWidget
{
}
```

Both carry `@todo` comments about wiring Symfony TUI components. They add
file count but zero value.

---

### Friction Point 4: Oversized ThemeColorEnum (47 Cases)

`ThemeColorEnum` (`src/Tui/Theme/ThemeColorEnum.php`) defines **47 semantic
tokens**, including highly granular ones:
- 6 thinking-level tokens (`ThinkingOff`, `ThinkingMinimal`, `ThinkingLow`,
  `ThinkingMedium`, `ThinkingHigh`, `ThinkingXhigh`)
- 10 syntax-highlighting tokens (`SyntaxComment`, `SyntaxKeyword`, etc.)
- 9 markdown tokens (`MarkdownHeading`, `MarkdownLink`, etc.)

Most of these tokens have no consumers yet (markdown rendering, syntax
highlighting are deferred to future phases). The `DefaultTheme` builds a
`Style` cache keyed by all 47 enum values (`src/Tui/Theme/DefaultTheme.php:81-86`).

**Consequence**: Adding a new semantic role requires modifying the enum (a
breaking change if extensions reference it), plus adding palette entries to
every theme YAML file. There's no mechanism for extensions to register custom
color tokens.

**File evidence**:
- `src/Tui/Theme/ThemeColorEnum.php:15-93` — 47 enum cases
- `src/Tui/Theme/DefaultTheme.php:81-86` — Per-color Style cache

---

### Friction Point 5: Listener Coupling Explosion

`TuiListener` (`src/Tui/Listener/`) has **16 imports from CodingAgent** — the
highest coupling score of any TUI module. Deptrac allows listeners to depend
on 13 other modules/interfaces:

```
TuiListener deptrac rules (depfile.yaml):
  - AppRuntimeContract, AppRuntimeProjection, AppSession, AppConfig
  - TuiRuntime, TuiScreen, TuiTranscript, TuiCommand
  - TuiPicker, TuiFooter, TuiQuestion, TuiTheme
  - SymfonyTui
```

Each `TuiListenerRegistrar::register()` method creates closures that
capture-by-reference 5-10 variables from the runtime context. For example,
`SubmitListener::register()` at `src/Tui/Listener/SubmitListener.php:41-86`
captures: `$sessionStore`, `$router`, `$blockFactory`, `$client`, `$state`,
`$screen`, `$tui`, `$questionCoordinator`, `$questionController` — 9
variables in one closure.

**Consequence**: Listeners are hard to unit test in isolation. The listener
registrars are stateless (good), but the closures they create are
tightly-coupled to the mutable `TuiSessionState` bag. Testing a listener
requires wiring all 9+ dependencies.

**File evidence**:
- `src/Tui/Listener/SubmitListener.php:41-86` — 9 captured variables
- `src/Tui/Listener/FooterStateListener.php:17-31` — Ticks + footer provider
- `depfile.yaml:242-255` — TuiListener has 13 allowed dependencies

---

### Friction Point 6: Picker Controller Duplication

`ModelPickerController` (`src/Tui/Picker/ModelPickerController.php`, 301 lines)
and `FavoritePickerController` (`src/Tui/Picker/FavoritePickerController.php`,
216 lines) share:

- Identical `setRuntimeRefs()` pattern (lines 87-92 vs 56-60)
- Identical `open()` → build header + list → mount → `$tui->add()` → `$tui->setFocus()` flow
- Nearly identical `applyCloseEffect()` methods
- Nearly identical `isOpen()` guard
- Identical `$container/$listWidget/$isOpen` mutable state

The only real difference: what happens on selection. Everything else is
boilerplate that could be extracted into a shared `PickerOverlay` abstraction.

**File evidence**:
- `src/Tui/Picker/ModelPickerController.php:26-30` — State fields
- `src/Tui/Picker/FavoritePickerController.php:26-30` — Identical state fields
- Compare `applyCloseEffect()`: ModelPickerController.php:248-260 vs FavoritePickerController.php:173-181

---

### Friction Point 7: RuntimeEventPoller is a Multi-Concern Monolith

`RuntimeEventPoller` (`src/Tui/Runtime/RuntimeEventPoller.php`, 241 lines) does:

1. Poll throttling (lines 53-57)
2. Runtime event fetching + iterator normalization (lines 195-207)
3. Deduplication by sequence number (lines 82-89)
4. Activity state transitions via `updateActivity()` (lines 163-192) — a 30-line match on 25+ RuntimeEventTypeEnum cases
5. Footer usage extraction via `extractFooterUsage()` (lines 198-238) — token counting, cost accumulation, per-turn reset
6. Processing placeholder removal (lines 145-155)
7. Synchronization of projected blocks (lines 213-240)
8. Error handling with fatal/non-fatal classification + boundary delegation (lines 104-144)

**Consequence**: Testing any single concern (e.g., activity transitions)
requires mocking the entire poller. The `updateActivity` and
`extractFooterUsage` methods are `private static`, making them untestable
independently.

**File evidence**:
- `src/Tui/Runtime/RuntimeEventPoller.php:46-239` — Entire poll method
- `src/Tui/Runtime/RuntimeEventPoller.php:163-192` — 30-line activity transition match

---

### Friction Point 8: TuiSessionState is a Mutable Dumping Ground

`TuiSessionState` (`src/Tui/Runtime/TuiSessionState.php`) holds **21 public
mutable fields** — session ID, run handle, activity state, transcript blocks,
sequencing state, footer projection fields, per-turn metrics, session timing,
cwd, branch. It replaces the earlier pattern of "6+ reference-captured
variables" with a single bag, but the bag itself has no structure, no
invariants, and no behavior.

**Consequence**: Any mutation is a valid mutation — nothing enforces that
`turnOutputTokens` resets when `turnStartTime` is set. The two are reset
together in `RuntimeEventPoller::extractFooterUsage()` at lines 200-206, but
nothing prevents a subtle drift in other callers.

---

### Friction Point 9: Untested High-Complexity Modules

| Module | LOC | Test LOC | Test files | Risk |
|---|---|---|---|---|
| `Application` | 378 | 0 | 0 | **High** — entry point, session init, theme factory |
| `Runtime` | 585 | 0 | 0 | **High** — poller, state machine, tick dispatch |
| `Screen` | 440 | 0 | 0 | **High** — god object, widget composition |
| `Status` | 133 | 0 | 0 | Low |
| `Header` | 42 | 0 | 0 | Trivial |

The full production code path (`InteractiveMode::run()` → `ChatScreen::mount()`
→ listener registrars → `RuntimeEventPoller::poll()`) has **zero unit test
coverage**. All testing of this path happens only in expensive E2E tmux
snapshot tests.

---

### Friction Point 10: Command Layer Has Shallow DTOs

The `Command/` module contains 18 files but is the shallowest module. Most
files are single-purpose DTOs/interfaces:

- `CommandResult` — empty marker interface (`src/Tui/Command/CommandResult.php`)
- `CommandParseResult` — empty marker interface (same file)
- `NoOp`, `ClearTranscript`, `ExitApplication`, `StatusUpdate`, `DispatchRuntime` —
  each a ~10-15 line class carrying a `CommandResult` marker
- `CommandMetadata` — 5-field readonly DTO (35 lines)
- `NormalPromptCommand`, `ShellCommand`, `SlashCommand` — discriminated parse results

The interface-to-implementation ratio is high. `CommandResult` has 7
implementors, all shallow. The typing is valuable (discriminated union), but
the cost per concept (1 file per variant) is high.

---

## 2. Candidate List

### Candidate 1: Unify TuiWidget rendering path — eliminate ChatLayout/ChatScreen dualism
- **Cluster**: `Widget/`, `Layout/`, `Screen/`
- **Coupling**: ChatScreen's 14 LiveTextWidget adapters vs ChatLayout's single-pass render
- **Dependency category**: Internal refactor — both are TUI-internal; no CodingAgent deps change
- **Test impact**: Replace ChatLayout unit tests + untested ChatScreen with boundary tests on a single unified screen model

### Candidate 2: Extract a PickerOverlay abstraction to deduplicate ModelPickerController and FavoritePickerController
- **Cluster**: `Picker/`
- **Coupling**: 200+ lines of near-identical widget lifecycle code between two controllers
- **Dependency category**: Extension point — may expose to future extension pickers
- **Test impact**: Replace 2 picker test files with a single PickerOverlay test + focused selection-handler tests

### Candidate 3: Split RuntimeEventPoller into single-responsibility components
- **Cluster**: `Runtime/`
- **Coupling**: Poller does polling, dedup, activity transitions, usage extraction, error handling
- **Dependency category**: Cross-module — extracted components would be used by Listeners
- **Test impact**: Enable unit testing of activity transitions, usage extraction, dedup — currently entirely untested

### Candidate 4: Split ChatScreen into composable layout sections
- **Cluster**: `Screen/`, `Layout/`, `Extension/`
- **Coupling**: 440-line god object with 14 private widget refs, 170-line constructor
- **Dependency category**: Internal refactor — no CodingAgent deps change
- **Test impact**: Enable section-level testing without full Tui instance; currently zero unit coverage for 440 LOC

### Candidate 5: Reduce ThemeColorEnum surface and support extension-registered tokens
- **Cluster**: `Theme/`
- **Coupling**: 47 tokens, many unused; no extension token registration mechanism
- **Dependency category**: Public interface (extensions reference ThemeColorEnum)
- **Test impact**: Theme palette tests remain; extension token registration becomes testable

### Candidate 6: Decompose TuiSessionState into structured sub-objects with invariants
- **Cluster**: `Runtime/`, `Listener/`
- **Coupling**: 21 public mutable fields accessed across 8+ listeners
- **Dependency category**: Internal refactor
- **Test impact**: Enable testing of state transitions independently (currently 0 Runtime tests)

### Candidate 7: Remove empty stub classes (PromptInputWidget, ToolOutputWidget)
- **Cluster**: `Widget/`
- **Coupling**: None — classes are unused
- **Dependency category**: Trivial cleanup
- **Test impact**: None

---

## 3. Problem Frames (for Top 3 Candidates)

### Candidate 1: Unify TuiWidget Rendering

**Constraints**:
- Must preserve terminal-resize responsiveness (ChatScreen's `LiveTextWidget` path)
- Must retain `TuiWidget` as the stable rendering contract for extensions
- Must not require extensions to adopt Symfony `AbstractWidget`
- Must keep `TuiRenderContext` (theme + terminal dimensions) as the render parameter
- Must support widget replacement from `TuiSlotRegistry` (extension overrides)

**Dependencies**:
- `TuiSlotRegistry` (source of widget overrides and status entries)
- `TuiRenderContext` (rendering metadata)
- Symfony `Tui` (mount target; may become optional for testing)
- `TuiWidget` implementations (HeaderWidget, TranscriptBlockWidget, etc.)

**Code sketch (grounding, not a proposal)**:
```php
// A "screen model" that existing ChatScreen delegates to,
// and that ChatLayout's tests can exercise directly.
// It renders the full layout to lines WITHOUT needing a Tui instance,
// but produces the EXACT same output as ChatScreen's mounted widgets.
final class ScreenModel {
    public function __construct(
        private TuiSlotRegistry $registry,
        private HeaderWidget $headerDefault,
        private TranscriptBlockWidget $transcript,
        // ... etc
    ) {}

    /** @return list<string> */
    public function render(TuiRenderContext $ctx): array {
        // Same logic as ChatScreen's producer closures, but unified
    }
}
```

---

### Candidate 4: Split ChatScreen into Composable Layout Sections

**Constraints**:
- Must preserve all 14 currently-mounted `LiveTextWidget` positions
- Must preserve the `Tui->add()` order (top-margin → header → ... → footer)
- Must keep `TuiSlotRegistry` as the extension slot model
- `mount(Tui)` must remain a single call with predictable widget order
- Must not change the public API that Listeners depend on (`editorText()`, `setTranscriptBlocks()`, `setWorkingMessage()`, etc.)

**Dependencies**:
- `Tui`, `LiveTextWidget`, `TuiWidget`, `TuiRenderContext`
- `TuiSlotRegistry`, `SlotBasedTuiExtensionContext`
- `PromptEditor` (Symfony EditorWidget wrapper)
- Concrete widgets: `HeaderWidget`, `TranscriptBlockWidget`, `WorkingStatusWidget`, etc.

**Code sketch**:
```php
// Each section owns its production LiveTextWidget + TuiWidget renderable
final class HeaderSection {
    public function __construct(TuiSlotRegistry $registry, HeaderWidget $default) {}
    public function mount(Tui $tui): void;  // adds widget
    public function invalidate(): void;     // forces re-render
}

final class TranscriptSection {
    public function __construct(TranscriptBlockWidget $renderable) {}
    public function mount(Tui $tui): void;
    public function setBlocks(array $blocks): void;
    public function invalidate(): void;
}
// ... etc for each layout row

// ChatScreen becomes a thin orchestrator
final class ChatScreen {
    private HeaderSection $header;
    private TranscriptSection $transcript;
    // ...
    public function mount(Tui $tui): void {
        foreach ($this->sections as $section) $section->mount($tui);
    }
}
```

---

### Candidate 3: Split RuntimeEventPoller into Single-Responsibility Components

**Constraints**:
- Must not change the tick callback interface (poll returns `list<TranscriptBlock>|null`)
- Must preserve the polling throttle (50ms) and the dedup-by-seq mechanism
- Must preserve the fatal/non-fatal error classification + `RuntimeExceptionBoundary` delegation
- Footer usage extraction must remain tied to runtime events (not a separate poll)
- Activity state transitions must remain authoritative (matching the documented event lifecycle)

**Dependencies**:
- `AgentSessionClient` (event source)
- `TranscriptProjectorInterface` (block projection)
- `RuntimeExceptionBoundary` (error capture/rethrow policy)
- `TuiSessionState` (mutable state target — or its replacement)
- `LoggerInterface`

**Code sketch**:
```php
// Pure function: event → activity state transition
final readonly class ActivityStateMachine {
    public function transition(RunActivityStateEnum $current, RuntimeEvent $event): RunActivityStateEnum;
}

// Pure function: event → footer usage updates
final readonly class UsageExtractor {
    public function extract(TuiSessionState $state, RuntimeEvent $event): void;
}

// Streamlined poller that composes the above
final class RuntimeEventPoller {
    public function poll(TuiSessionState $state, AgentSessionClient $client): ?array {
        // throttle → fetch events → dedup → stateMachine.transition → usageExtractor.extract → project → sync
    }
}
```

---

## 4. Top Recommendations

### Recommendation 1 (Highest Impact): Split ChatScreen into Sections

**Why**: ChatScreen at 440 lines with 14 widget references and zero unit test
coverage is the single biggest testability gap in the TUI codebase. Splitting
it into per-section classes (HeaderSection, TranscriptSection, FooterSection,
etc.) would:
- Make each section independently testable (render output assertions)
- Keep the extension slot model intact (TuiSlotRegistry)
- Not change the public API that Listeners depend on
- Reduce ChatScreen to ~50 lines of section orchestration

This is a real problem, not just a styling preference. The current structure
forces all widget interaction tests through expensive E2E tmux snapshots.

**Risk**: Medium. Requires careful section interface design to avoid
introducing a new abstraction that's just as shallow. Must preserve
`LiveTextWidget` resize responsiveness.

### Recommendation 2 (High Impact): Decompose TuiSessionState

**Why**: 21 public mutable fields with zero invariants is a time bomb.
Per-turn metrics (`turnOutputTokens`, `turnStartTime`) must reset together;
footer projection fields (`footerModel`, `footerReasoning`) have implicit
coherence. A structured decomposition would:
- Group related fields into sub-objects (`FooterProjection`, `TurnMetrics`,
  `RunState`)
- Enforce invariants at construction/transition boundaries
- Enable direct unit testing of state transitions without the poller

This is a real problem — the comment at `RuntimeEventPoller.php:206` documents
the per-turn reset invariant, but nothing enforces it programmatically.

**Risk**: Medium-High. Touches every listener. Requires careful migration to
avoid breaking 8+ listener registrars that mutate these fields directly.

### Recommendation 3 (Medium Impact): Extract a PickerOverlay Abstraction

**Why**: `ModelPickerController` and `FavoritePickerController` share ~200
lines of boilerplate. A shared `PickerOverlay` would:
- Remove duplicate widget lifecycle code (mount, unmount, focus, close)
- Make adding new picker overlays (future: file picker, theme picker) trivial
- Keep the selection/cancellation logic specific to each controller

This is unambiguously good — the duplication is clear and has no
architectural justification.

**Risk**: Low. The pattern is already well-understood; both controllers use
the same Symfony TUI widget primitives (ContainerWidget, SelectListWidget).

---

## 5. Module Health Summary

| Module | LOC | Tests | Health | Notes |
|---|---|---|---|---|
| Command | 754 | 925 | ✅ Good | Well-separated, stateless, testable |
| Theme | 522 | 390 | ✅ Good | Reasonable depth; enum size is future concern |
| Layout | 298 | 483 | ✅ Good | ChatLayout is tested; ChatScreen path untested |
| Footer | 343 | 155 | ⚠️ Fair | ReadonlyFooterDataProvider is shallow |
| Transcript | 425 | 442 | ✅ Good | Renderer + widget separation is clean |
| Editor | 272 | 454 | ✅ Good | PromptEditor is a clean facade |
| Listener | 1,303 | 933 | ⚠️ Fair | Overcoupled; closures capture too many vars |
| Question | 551 | 745 | ✅ Good | Coordinator pattern is solid |
| Picker | 612 | 278 | ⚠️ Fair | Duplication between controllers |
| Extension | 164 | 111 | ✅ Good | Simple delegation; stable contract |
| **Screen** | **440** | **0** | **🔴 Poor** | God object; 14 widget refs; untestable |
| **Runtime** | **585** | **0** | **🔴 Poor** | Multi-concern poller; untestable |
| **Application** | **378** | **0** | **🔴 Poor** | Entry point with no unit coverage |
| Status | 133 | 0 | ⚠️ Fair | Simple widgets; low risk |
| Header | 42 | 0 | ✅ Good | Too trivial to fail |
| Widget | 217 | 77 | ⚠️ Fair | Empty stubs (PromptInputWidget, ToolOutputWidget) |

---

## 6. Architecture Boundaries Compliance

Per `depfile.yaml`, all TUI→CodingAgent imports are through the allowed
contract layers (`AppRuntimeContract`, `AppRuntimeProjection`, `AppSession`,
`AppConfig`). No architecture violations detected.

`TuiListener` is the widest-allowed layer (13 dependencies). Consider narrowing
the listener→`AppConfig` and listener→`TuiPicker` dependencies — listeners
should not need to know about pickers or config directly.

---

*Generated by `improve-codebase-architecture` skill analysis. Read-only.*
