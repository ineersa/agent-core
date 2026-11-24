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
Child execution also produces live `ChildExecutionStarted` and
`ChildExecutionEnded` observations. `sessionId` is the root session,
and `childRunId` and `sequence` identify
the child and its canonical event, not the parent cursor. Bootstrap history
and streaming deltas do not dispatch this hook. Turn start
can reflect an internal model step; a foreground terminal event alone does not
prove background work has settled. The callback must not block the UI.

Implement the optional `TuiForegroundBaselineObserverInterface` to receive
`onTuiForegroundBaseline(TuiForegroundBaselineDTO $baseline)` once a matching
bootstrap has mounted, been acknowledged, and reached its validated ready cut.
Dispatch precedes following live observations; selection, incomplete or failed
bootstrap, stale packets, and repeated ready packets do not dispatch it.

The baseline contains the selected `sessionId`, ready `canonicalSequence`, nullable
`currentResponseOpeningSequence`, and `pendingRequests`. Each
`TuiForegroundRequestDTO` contains only `requestId` and `type`
(`QuestionRequested` or `ApprovalRequested`); it carries no prompt, answer, tool
arguments, or child attention. Request IDs are bounded to 1,024 bytes, lists to
2,000 entries, and the encoded foreground metadata to 32 KiB; oversized data
refuses attachment rather than truncating it.

The response identity is the canonical sequence of the first turn start in the
current logical response, not a turn number or the latest internal step. A null
identity does not describe a historical completion. Reconcile current foreground
attention by request ID, retaining existing episodes still present and clearing
absent ones; `InputResolved` later resolves the same IDs. Same-session rebootstrap
delivers the current cut again and should not duplicate notifications. Child
attention and background-work counts remain independent observations. Callback
exceptions are logged and isolated from later observers and live events. Keep
the callback nonblocking; it is a delivered baseline, not a query API.

Implement `TuiSessionCloseObserverInterface` to receive
`onTuiSessionClosing($sessionId)` before teardown on final graceful interactive
quit. It does not fire for drafts, session switches, settings reloads, or abrupt
process exits. Keep any best-effort shutdown work bounded.

Implement `TuiBackgroundJobObserverInterface` for live, best-effort background
job start and end observations, including while the selected session is idle.
The DTO contains only the selected root session ID, the native owner run ID
(which can be a child run), an opaque job ID, and the transition type. Native
Bash and code-mode jobs are routed by their recorded ownership. Observations
are not replayed on attach or selection, and other sessions are not attributed
to the selection.

## UX guidelines

- Keep overlays dismissible (Esc) where practical.
- Do not block the editor loop with unbounded work — use hooks/jobs for heavy IO.
- Session-scoped feature data should live under project `.hatfield/` paths from `getCwd()`.

## Related

- Overview: [extension-api.md](extension-api.md)
- Runtime hooks: [extension-api-runtime.md](extension-api-runtime.md)

## Selected session metadata and current background work

Check whether the host context implements `TuiSessionMetadataContextInterface`
before calling `getSessionDisplayTitle()`. It returns the canonical picker title
for the current persisted selection, or `null` when unavailable.

Implement `TuiBackgroundSnapshotObserverInterface` to receive
`TuiBackgroundSnapshotDTO` from the existing host operational poll once the
session is ready. Each snapshot contains the selected session ID and separate
current counts of nonterminal owned subagents and background scripts. A zero
means none; `null` means unavailable. The DTO carries no job identities, prompts,
commands, or history. It is a delivered observation, not a synchronous query API.
