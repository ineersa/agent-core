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
- `insertOverlayAfterEditor` / `removeOverlay` — overlay widgets below the editor
- `setFocus` — focus an overlay widget
- `onTick` — idle-safe tick callbacks (host discards return values; self-throttle)
- `requestRender` / `getSessionId`
- `formatMuted` / `formatRolePrefix` — themed text helpers for pickers
- `turnRowsInDisplayOrder($sessionId)` — **read-only** retained user-prompt rows
  (`turnNo`, `title`, `displayRole`) for history/rewind pickers

There is no footer-segment API and no API to retain/write transcript rows from this context.
Extensions must not mutate core transcript widgets directly.

## UX guidelines

- Keep overlays dismissible (Esc) where practical.
- Do not block the editor loop with unbounded work — use hooks/jobs for heavy IO.
- Session-scoped feature data should live under project `.hatfield/` paths from `getCwd()`.

## Related

- Overview: [extension-api.md](extension-api.md)
- Runtime hooks: [extension-api-runtime.md](extension-api-runtime.md)
