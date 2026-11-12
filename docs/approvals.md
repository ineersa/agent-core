---
builtin: true
description: Configure SafeGuard, understand allow/block/approval decisions, and avoid unattended approval waits.
---

# Tool approvals

Approval pauses a specific tool call before execution. Allow runs that stored
call; block or replacement produces a result without running it. Approval does
not require another model decision turn. Model questions use [ask_human](human-input.md).

## SafeGuard

Built-in `Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension`
checks Bash, writes/edits and protected reads. Its fixed policy returns allow,
require approval or block; there is no SafeGuard "mode" setting. Hard blocks such
as `sudo` are not negotiable through the approval dialog.

Configure under `extensions.settings.safe_guard`:

| Key | Meaning | Default |
|---|---|---|
| `tool_names.bash` / `write` / `edit` / `read` | Aliases matched by policy | Built-in names |
| `allow_command_patterns` | Command substrings exempted from dangerous/destructive checks | `[]` |
| `allow_write_outside_cwd` | Allowed absolute outside-workspace paths | `[]` |
| `protected_read_patterns` | Additional protected-read patterns | `[]` |
| `dangerous_command_patterns` | Additional dangerous-command substrings | `[]` |
| `auto_deny_in_noninteractive` | Block approval-required calls without an answer channel | `true` |

Protected secret-path defaults cannot be removed by YAML; patterns are additive.
Keep exemptions narrow. Tool availability and approval policy are separate, and
neither creates a sandbox. Raw PHP in `code_mode` bypasses tool hooks.

## Interactive versus unattended

Interactive sessions can show approval questions. Without a real answer channel,
keep `auto_deny_in_noninteractive: true` so automation fails closed rather than
waiting forever. Scheduled work denies approval-required calls.

`HATFIELD_APPROVAL_CHANNEL` is a capability signal: any nonempty value tells policy
that an answer channel exists. Merely setting it does not create one. The TUI
configures its controller channel, inherited by consumers.

Children inherit `agents.extensions.always_on`, which includes SafeGuard by
default. Parent optional extensions are not automatically inherited; see
[agent settings](settings-agents.md).

Extension authors: use the public tool/approval hooks documented in
`extension-api-tools` through `hatfield_docs`, not host-internal policy classes.

Related: [settings](settings.md), [human input](human-input.md), [tools](tools.md).
