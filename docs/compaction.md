---
builtin: true
description: Compact parent-session context manually or automatically, configure thresholds, and handle failures.
---

# Context compaction

Compaction summarizes older model context while retaining recent raw messages.
Leading system/project instructions stay raw, and tool-call/result groups are not
split. It changes what the model receives, not files in the workspace or canonical
history. Repeated compaction can summarize a previous summary again.

## Manual compaction

```text
/compact [optional custom instructions]
```

Alias: `/cmp`. Manual compaction is available for parent sessions even when
automatic compaction is off. After cancellation settles, it can compact without
restarting cancelled work; requests during cancellation are rejected.

The UI first shows `Compaction requested.`, then `Compacting conversation` when
execution starts. Rejection displays its reason. Messages entered while compaction
is pending wait locally in submission order. If the run fails, they return to the
editor instead of starting another turn automatically.

## Automatic compaction

Parent runs become eligible when the latest provider-reported input usage reaches
`compaction.compact_after_tokens`. The runtime waits for a safe boundary after
tools/human waits, compacts, then continues. It can also compact as maintenance
after a turn. Without provider usage, the threshold does not trigger; each usage
measurement authorizes only one attempt.

| Setting | Purpose |
|---|---|
| `compaction.auto_enabled` | Enable automatic compaction |
| `compaction.compact_after_tokens` | Input-usage threshold |
| `compaction.keep_recent_tokens` | Raw tail to retain |
| `compaction.model` / `thinking_level` | Summary model/reasoning |
| `compaction.provider_overrides` / `model_overrides` | Sparse per-provider/model overrides |

See [settings](settings.md) for defaults. Context-budget reminders are separate
from compaction thresholds.

## Limits and failure

- Child subagents/forks do not compact. They finish, hand off or hit the provider
  context limit. Parent fork-snapshot preparation is a separate parent concern.
- A provider context-too-large error fails normally; it does not trigger automatic
  error-recovery compaction.
- A failed/empty summary preserves original messages. Pending active work continues
  on that context unless cancelled; maintenance compaction simply settles.
- Cancellation wins over a pending continuation.
- The transcript retains the latest completed compaction segment plus the current
  segment; successful compactions advance that window. Canonical history stays on
  disk. This is not a fixed byte/row memory limit.

Extensions can contribute compaction notes through the public hooks documented in
`extension-api-runtime`. Package-specific memory settings belong to that
extension's README, not core settings.

Related: [sessions](session-storage.md), [model settings](settings-models.md).
