---
builtin: true
description: Context compaction settings, /compact command behavior, events, and failure rules.
---

# Context Compaction

Context compaction replaces older conversation history with a concise handoff summary while keeping the most recent messages raw. This keeps long sessions inside the model context window without discarding recent local detail.

## Resulting message shape

```text
[summary message — compacted older history]
[recent retained message 1]
[recent retained message 2]
…
```

Repeated compaction is supported: a later `/compact` treats an existing summary as part of the current list and re-summarizes older material.

The algorithm never splits tool-call/tool-result groups. Leading system / user-context instruction messages stay raw and are not summarized away.

## Manual command

```text
/compact [optional custom instructions]
```

Alias: `/cmp`

Manual compaction is always available for **parent** sessions regardless of `compaction.auto_enabled`.

## Automatic compaction

When enabled, parent runs may compact after token thresholds using:

| Key | Role |
|---|---|
| `compaction.auto_enabled` | Master switch |
| `compaction.compact_after_tokens` | Trigger threshold |
| `compaction.keep_recent_tokens` | Raw tail to retain |
| `compaction.model` / `thinking_level` | Compaction model selection |
| `compaction.provider_overrides` / `model_overrides` | Sparse overrides |

See [settings.md](settings.md).

## Child runs

**Fork and subagent child runs do not compact.** Children complete, hand off, or fail at the provider context limit. Parent automatic and manual compaction is unchanged. Parent-side fork snapshot compaction before launch remains a parent concern, not a child-run feature.

## Provider context-limit errors

If a provider rejects a request as context-too-large, the run fails through the ordinary LLM error path. Hatfield does **not** auto-recover by compacting on that error. Compaction still occurs only via manual `/compact` (parents) or automatic threshold scheduling (parents).

## Events and resume

Compaction records canonical session events so resume rebuilds the compacted LLM-visible history correctly. Transcript projection follows the event log — see [session-storage.md](session-storage.md).

The TUI retains the most recently completed compaction segment and the current segment. The first successful compaction keeps the initial conversation. The second drops content before the first completion marker. Each later success advances that window once. Failed compactions do not advance it. Resume and history selection rebuild the same window at the selected position.

Retention removes old projected blocks and mounted widgets, including local notices in the evicted segment. Canonical events remain on disk for history and replay. This is a segment limit, not a byte or rendered-row limit: one large segment can still be expensive. Full event reads are temporary and are not cached by the session event store. Child views rebuild from durable state on every entry and release their projection on exit.

## Extension hooks

Extensions may register `BeforeCompactionHookInterface` via the public Extension API to contribute notes within the public contract. Package-specific observational-memory settings, when that extension is installed, live under `extensions.settings.observational_memory` and are documented in that package’s own README — not as core `hatfield_docs` entries.

## Related

- Sessions: [session-storage.md](session-storage.md)
- Settings: [settings.md](settings.md)
- Extension API runtime hooks: `extension-api-runtime` via `hatfield_docs`
