# TUI composition

[Architecture](README.md) · [Projection](context-and-projection.md) · [Terminal usage](../docs/terminal-usage.md)

`InteractiveMode` creates each session through `TuiSessionCompositionFactory`.
`ChatScreen` mounts native Symfony TUI widgets and owns editor/overlay focus;
session services own commands, questions, history, polling and projection.

## Input and rendering

The screen lays out header, transcript, pending questions/status, extension widgets,
editor and footer in one column. Listeners route input by priority and respect
focus. Local slash commands use `SlashCommandCatalog` / session-bound handlers;
`CommandParser` distinguishes prompts, shell input and slash commands. Existing
absolute file/directory paths remain prompts.

Bracketed paste uses Symfony's native buffering. A sole absolute supported image
path enters the clipboard-attachment pipeline through a private staged copy; the
source is never owned/deleted. Other pastes retain native text semantics. Consumed
paste requests rendering and closes stale completions; other focused widgets keep
native routing.

Pickers use native `SelectListWidget` / `TextWidget` in `ContainerWidget`.
`PickerOverlay` mounts/closes them; it does not implement selection rendering.
Theme settings select YAML themes under `config/themes/`.
Hatfield uses Symfony's stock renderer and `ScreenWriter`, including synchronized
cursor hide/restore and differential output. Switching the visible run requests
a full frame.

Tool elapsed labels measure canonical start-to-terminal duration, including
admission/queue wait. Active labels update in whole seconds; terminal labels freeze
from recorded timestamps. Deferred children retain their own lifecycle timing.

## Session boundary

The TUI sends commands and consumes runtime DTOs through `AgentSessionClient`.
It must not repair or rebuild AgentCore state through local stores. During attach,
execution input stays blocked until verified bootstrap mount and suffix catch-up.
Local edits made during attach are not overwritten by historical editor prefill.
Automatic recovery of an already-ready session preserves its current draft.

Parent and selected-child polling have distinct ownership. Questions and Escape
in a child live view target the child. Leaving that view releases its transcript.
Session switching stops the old UI loop and constructs fresh session-scoped services.

## Extension UI

Public `TuiExtensionContextInterface` provides status/transient status, warning
rows, tick hooks, native overlays, focus and retained prompt-row lookup. The host
bridge adapts these contracts; packages do not import internal `PickerOverlay` or
replace the host renderer. Transient notices clear on the next nonempty submission;
persistent status rows retain their lifetime.

Foreground attach reconciliation shares the owner's bounded resume projection.
The producer seeds pending request identities/categories from `RunState`; the
spool owner validates and retains only content-free foreground metadata before
acknowledgement removes the spool. The existing canonical suffix traversal folds
that projection, including history-position and tail-discard fences that have no
runtime mapping. Controller drain-race passes retain the same projection until
the current ready cut. Older output drains before the final cut check; readiness
is enqueued once without an intervening yield, and the bootstrap filter is
removed at that cut. Ready and following live records use the existing bounded
nonblocking transfer queue until it drains. An empty user-space queue does not
prove space in the OS pipe: cancellation and timeout ownership remain active
through ready delivery, and ordinary blocking output resumes only after drain.
No second archive scan or execution-state registry exists.

One Symfony foreground validator owns admission at producer, spool, ready and
TUI input boundaries: request IDs are limited to 1,024 bytes, ordered lists to
2,000 occurrences, and encoded foreground data to 32 KiB. Historical correlation
is separate from wire admission: oversized historical IDs retain only a distinct
nonexportable digest, so later FIFO responses can settle them without storing
unbounded strings. An unresolved marker refuses publication; sealing replaces
historical pending identities with typed current requests from `RunState`.

The bootstrap assembler validates the ready baseline before advancing readiness.
`RuntimeEventPoller` dispatches the optional public foreground baseline observer
only on that accepted transition, before following live observations. Host mapping
keeps runtime types out of ExtensionApi. Historical terminal outcomes do not become
completion observations. Hatfield 360 uses canonical response-opening sequences
for both live and resumed responses, reconciles foreground attention by request
identity and FIFO occurrence, and resolves one occurrence per answer. It retains
the entire admitted baseline, reuses surviving episode IDs on reconciliation,
and keeps live overflow attention until an authoritative baseline or terminal
settles it. Verified child attention and operational counts stay on their
existing sources.

Dependency edges follow [depfile.yaml](../depfile.yaml): TUI can use owning
CodingAgent services directly where allowed. Runtime contracts describe the session
protocol, not wrappers invented to hide permitted CodingAgent dependencies.

Sources: [ChatScreen](../src/Tui/Screen/ChatScreen.php),
[InteractiveMode](../src/Tui/Application/InteractiveMode.php),
[image-paste listener](../src/Tui/Listener/ImagePasteInputListener.php),
[public TUI API](../.hatfield/extensions/extension-api/docs/extension-api-tui.md).
