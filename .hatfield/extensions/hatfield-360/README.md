# Hatfield 360 for Hatfield

This PHP extension sends Hatfield activity to the desktop app’s v1 Unix
socket. The [Claude Code adapter](../../../.claude-plugin/hatfield-360/README.md)
is a separate Node.js plugin using the same wire contract.

## Enable the extension

Enable the built-in Hatfield 360 extension in `hatfield setup`. A project-level `extensions.enabled` list replaces the global list; if the project defines one, keep `Ineersa\HatfieldExt\Hatfield360\Hatfield360Extension` in that list alongside the other enabled extensions. Preserve existing settings and list entries. Reload Hatfield with `/reload` or restart it after changing enablement.

## Snapshot delivery

Each snapshot replaces the entire selected conversation status. A later snapshot
can establish an epoch even when the first was lost. Hatfield coalesces unsent
snapshots. The extension does not replay historical observations, retry in the
background, or guarantee eventual delivery. `session.ended` requires an already
live epoch and does not imply process exit.

Hatfield reads the selected conversation’s picker title on selection and at
the next foreground turn after a rename; it does not watch rename-only updates.

## Send an additional notification

In Hatfield, enter `/h360-notify Review available | The report is ready.` in the
selected conversation. The first `|` separates the title and body. The command
reports whether the desktop confirmed acceptance, not whether it showed a toast.

## Hatfield coverage

Foreground observations report work, final response, failure or cancellation,
correlated foreground and child question/approval waits and tool names (not tool inputs or results).
Tool completion or failure clears the matching progress marker, not the response.
The existing TUI operational poll reports separate counts of owned nonterminal
children and Bash/code-mode work. Counts start unknown and reconcile after
session readiness, including on resume. An unavailable read reports `null`.
A zero sampled before the foreground response finishes cannot settle it; a
subsequent poll must confirm zero children. Scripts do not gate completion.
Final interactive quit attempts `session.ended`; drafts, switches, reloads and
abrupt exits do not send it. Quit
never initiates manual Linux bootstrap.
