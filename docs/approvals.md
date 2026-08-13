---
builtin: true
description: Tool approval suspensions, SafeGuard policy, and extension approval hooks.
---

# Tool Approvals

Approvals (Path A) pause a **specific tool call** when an extension hook returns
`RequireApproval`. The human decides allow / block / replace-result; on allow the
original tool handler runs for that exact call — **no extra LLM turn**.

Model-driven questions use [human-input.md](human-input.md) instead.

## Flow

1. `ToolCallHookInterface` returns `ToolCallDecisionDTO::requireApproval(...)`.
2. Runtime admits the call into awaiting-human state and projects a human-input request with tool-call continuation metadata.
3. TUI renders the approval question (schema-driven).
4. Human answers via the runtime answer command.
5. The originating `ApprovalAnswerHookInterface` maps the answer to allow / block / replace-result.
6. On allow, the exact stored tool call is re-dispatched.

Hooks run in registration order; the first non-allow decision wins for tool-call hooks.

## SafeGuard (built-in)

`Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension` is the built-in
approval policy extension. It classifies tool calls (bash, write/edit, protected reads,
settings mutations, and related patterns) and returns **allow**, **require approval**,
or **block** according to fixed policy rules — there is **no** user-facing SafeGuard
“mode” enum.

### Interactive vs noninteractive

- **Interactive** (TUI / controller present): policy-relaxable dangerous operations
  typically return **RequireApproval** so a human can allow, block, or replace-result.
- **Noninteractive** (no TUI/controller — for example headless messenger workers): when
  `auto_deny_in_noninteractive` is `true` (default), those same categories return
  **Block** instead of waiting forever for a human.

Hard blocks (for example privilege escalation via `sudo`) are never negotiable.

### Settings (`extensions.settings.safe_guard`)

| Key | Meaning | Default |
|---|---|---|
| `tool_names.bash` / `write` / `edit` / `read` / `settings` | Tool name aliases used for matching | built-in tool names |
| `allow_command_patterns` | Command substrings that bypass destructive/dangerous checks | `[]` |
| `allow_write_outside_cwd` | Absolute paths where outside-CWD writes are always allowed | `[]` |
| `protected_read_patterns` | **Additive** on top of built-in secret-path defaults | `[]` |
| `dangerous_command_patterns` | Extra dangerous command substrings | `[]` |
| `auto_deny_in_noninteractive` | Block instead of RequireApproval without TUI/controller | `true` |

Built-in protected-read patterns (`.env.local`, SSH keys, cloud credentials, etc.) cannot
be removed through config; YAML only adds more.

`HATFIELD_APPROVAL_CHANNEL` can override approval channel routing for specialized environments.

### Child agents

`agents.extensions.always_on` includes SafeGuard by default so children inherit approval
policy unless configuration removes it.

## Extension authors

Use public Extension API types only:

- `ToolCallHookInterface` / `ToolResultHookInterface`
- `ToolCallDecisionDTO::requireApproval()`
- `ApprovalAnswerHookInterface` + answer DTOs

See Extension API docs (`extension-api`, `extension-api-tools`).

## Related settings

- [settings.md](settings.md) — core keys / env vars
- [settings-agents.md](settings-agents.md) — `always_on`, enablement
- [agents.md](agents.md) — child tool denylist and live view

## Limitations

- Approval UX depends on an interactive TUI or configured approval channel.
- Noninteractive automation should keep `auto_deny_in_noninteractive: true` (default)
  or provide a real approval channel; otherwise policy-relaxable calls cannot complete.
