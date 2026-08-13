---
builtin: true
description: Tool approval suspensions, SafeGuard modes, and extension approval hooks.
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

`Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension` is the built-in approval policy extension. It classifies tool calls (for example bash/file mutations) and may require approval based on mode and rules.

### Modes (summary)

SafeGuard supports multiple runtime modes (interactive approval, auto behaviors, stricter blocking) configured under `extensions.settings.safe_guard` and related defaults. Exact rule tables live in defaults/settings — keep project overrides sparse.

`HATFIELD_APPROVAL_CHANNEL` can override approval channel routing for specialized environments.

### Child agents

`agents.extensions.always_on` includes SafeGuard by default so children inherit approval policy unless configuration removes it.

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
- Noninteractive automation must set modes/channels deliberately or calls stay blocked waiting for humans.
