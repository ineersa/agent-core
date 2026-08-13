---
builtin: true
description: TUI extension interfaces for overlays, status rows, focus, ticks, and retained rows.
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

Typical context operations (see interface methods for exact signatures):

- Mount/unmount overlay widgets
- Contribute status rows / footer-adjacent UI through approved slots
- Participate in focus and tick/render cycles
- Retain turn-scoped rows when the host retains them

Extensions must not mutate core transcript widgets directly; use slot/context APIs.

## UX guidelines

- Keep overlays dismissible (Esc) where practical.
- Do not block the editor loop with unbounded work — use hooks/jobs for heavy IO.
- Session-scoped feature data should live under project `.hatfield/` paths from `getCwd()`.

## Related

- Overview: [extension-api.md](extension-api.md)
- Runtime hooks: [extension-api-runtime.md](extension-api-runtime.md)
