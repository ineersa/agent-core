# Hatfield 360 for Hatfield

This PHP extension sends Hatfield activity to the desktop app’s v1 Unix
socket. The [Claude Code adapter](../../../.claude-plugin/hatfield-360/README.md)
is a separate Node.js plugin using the same wire contract.

## Enable the extension

Enable the built-in Hatfield 360 extension in `hatfield setup`. A project-level `extensions.enabled` list replaces the global list; if the project defines one, keep `Ineersa\HatfieldExt\Hatfield360\Hatfield360Extension` in that list alongside the other enabled extensions. Preserve existing settings and list entries. Reload Hatfield with `/reload` or restart it after changing enablement.

## Snapshot delivery

Each snapshot replaces the entire selected conversation status. A later snapshot
can establish an epoch even when the first was lost. Hatfield coalesces unsent
snapshots. The extension samples inventory at most twice a second on the idle
TUI tick; there, the latest unconfirmed snapshot is retried after 1, 2, 4, 8, 16,
then at most once every 30 seconds until confirmed or the selection changes. The
extension does not replay historical observations or guarantee delivery when the
TUI no longer ticks. `session.ended` requires an already
live epoch and does not imply process exit.

Hatfield reads the selected conversation’s picker title on selection and at
each foreground turn. A `/rename` of the selected conversation sends its new
title right away without changing the turn.

## Send an additional notification

In Hatfield, enter `/h360-notify Review available | The report is ready.` in the
selected conversation. The first `|` separates the title and body. The command
reports whether the desktop confirmed acceptance, not whether it showed a toast.

## Hatfield coverage

The extension listens to the Extension API session events. Foreground events
report work, final response, failure or cancellation, correlated foreground and
child question/approval waits and tool names (not tool inputs or results).
Tool completion or failure clears the matching progress marker, not the response.
A completed main turn carries the final assistant message as `turn.response`:
plain text of at most 1,000 Unicode characters; longer text keeps its last 999
characters behind a leading `…`.
Later snapshots for the same turn resend it, and a new turn starts without one.
It is left out when the final message has no text, and never sent for working,
failed or cancelled turns.
Attention and tool markers still come from live events; the visible TUI
prompt is not an equivalent inventory of attention occurrences.
The extension reads owned nonterminal children and Bash/code-mode work through
the selected-session context. Ordinary events reuse the latest sample; selection,
main completion, child or job lifecycle changes and polls read it again. An
unavailable read reports `null`. After a successful main terminal event,
completion waits for at least five seconds with no new main turn, zero unsettled
children, and no observed attention request. During that delay the snapshot
withholds the zero-child count; on the first poll at or after the deadline,
Hatfield refreshes child inventory before reporting completion. Observed new
work, attention, or unknown child inventory invalidates the delay. Scripts remain
informational, and selecting a session does not replay past completions.

The extension API does not expose queued handoffs or steering intent. The delay
covers handoffs that become observable during the window, but a handoff taking
longer than five seconds to become observable can still follow a completion report.
Final interactive quit attempts `session.ended`; drafts, switches, reloads and
abrupt exits do not send it. Quit
never initiates manual Linux bootstrap.
