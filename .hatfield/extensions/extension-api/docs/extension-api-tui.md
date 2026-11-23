---
builtin: true
description: TUI extension interfaces for overlays, status-panel rows, focus, ticks, and retained prompt lookup.
---

# TUI Extension API

TUI-capable extensions implement `TuiExtensionInterface` in addition to
`HatfieldExtensionInterface` (or compose registration accordingly). Context is
`TuiExtensionContextInterface`.

## Approved dependency

Generic TUI contracts intentionally depend on **Symfony TUI** public widgets/events/input.
That is part of the public API. Do **not** depend on in-repo `Ineersa\Tui\*` classes.

`TuiProjectExtensionInterface` is a deprecated compatibility alias — prefer `TuiExtensionInterface`.

## Capabilities (public context)

`TuiExtensionContextInterface` exposes exactly:

- `setStatus($key, $text|null)` — keyed **status-panel** rows (not the footer bar)
- `setTransientStatus($key, $text)` — one-shot status-panel notice; cleared on the next nonempty submit. Persistent `setStatus` lifetime is unchanged.
- `setExtensionWarning($name, $message|null)` — keyed plain-text warnings under `[Extensions]` in startup loaded resources, including resumed sessions. Null clears a resolved warning. This does not write to the status panel or footer.
- `insertOverlayAfterEditor` / `removeOverlay` — overlay widgets below the editor
- `setFocus` — focus an overlay widget
- `onTick` — idle-safe tick callbacks (host discards return values; self-throttle)
- `requestRender` / `getSessionId`
- `formatMuted` / `formatRolePrefix` — themed text helpers for pickers
- `turnRowsInDisplayOrder($sessionId)` — **read-only** retained user-prompt rows
  (`turnNo`, `title`, `displayRole`) for session-history pickers

There is no footer-segment API and no API to retain/write transcript rows from this context.
Extensions must not mutate core transcript widgets directly.

## Session selection

Implement `TuiSessionObserverInterface` alongside `HatfieldExtensionInterface`
to receive `onTuiSessionSelected($sessionId)` from the interactive process.
The callback runs for a fresh established session, a selected resume target,
or when a draft obtains a real session ID. An empty draft does not notify.
This is a selection signal, not confirmation that restore or a turn succeeded.
Repeat selections can have the same ID. The callback receives no prompt or
transcript and must not block the UI. Headless workers do not call it.

Implement `TuiRunObserverInterface` to receive typed, content-free foreground
observations after live TUI application of canonical events. It exposes the
session ID, canonical sequence, observation type and an optional request ID.
Match `InputResolved` to either `QuestionRequested` or `ApprovalRequested`
by session and request ID; both kinds can be answered through the same route.
Bootstrap history and transient deltas do not dispatch this hook. Turn start
can reflect an internal model step; a foreground terminal event alone does not
prove background work has settled. The callback must not block the UI.

## UX guidelines

- Keep overlays dismissible (Esc) where practical.
- Do not block the editor loop with unbounded work — use hooks/jobs for heavy IO.
- Session-scoped feature data should live under project `.hatfield/` paths from `getCwd()`.

## Related

- Overview: [extension-api.md](extension-api.md)
- Runtime hooks: [extension-api-runtime.md](extension-api-runtime.md)
