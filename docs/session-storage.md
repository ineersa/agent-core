---
builtin: true
description: Session identity, storage layout, events, resume, locking, and history operations.
---

# Hatfield Sessions

**One directory = one session = one agent run.** `session_id === run_id`.

## Invariants

- The TUI session and AgentCore run share one identity (DB-issued numeric string).
- Each session row also stores immutable `provider_cache_key` (UUIDv7) for provider correlation where supported (for example Codex `prompt_cache_key`). Other providers may ignore it on the wire.
- Everything needed to resume lives under the session directory plus the `hatfield_session` DB row — there is no global `.hatfield/runs/` registry.
- Canonical conversation source is append-only `events.jsonl`. Transcript projection rebuilds from events on resume.
- There is **no** `metadata.yaml` in the session directory.

## Directory layout

Base path: `sessions.path` setting (default under project `.hatfield/sessions/`).

```text
.hatfield/sessions/<session_id>/
  events.jsonl          # canonical event log
  state.json            # durable run/projection state
  sequence.cursor       # event sequence allocation
  artifacts/            # child agent artifacts (when used)
  ...                   # other runtime sidecars as created
```

### Database metadata

Table `hatfield_session` stores id, display name, timestamps, provider cache key, and related session metadata. Directory name is canonical; embedded IDs are validated on read.

### Naming

Sessions may be renamed via `/rename`. Display names are metadata only — they do not change `session_id`.

## Events and state

- **`events.jsonl`**: append-only Run/TUI events used for resume and history.
- **`state.json`**: durable state snapshot for process/runtime recovery (not a second conversation source of truth).
- Sequence allocation uses `sequence.cursor` so multi-writer paths do not collide.

Runtime projects events into the TUI transcript. Keep transient stream deltas separate from canonical replay.

## Child artifacts

Foreground subagent runs store parent-scoped artifacts under the parent session (handoff text, metadata, bounded event/history summaries). Retrieve with `agent_retrieve` (see [agents.md](agents.md)).

Deferred subagent supervision (single and parallel) uses durable batch records and timeouts configured by `agents.subagent_tool_timeout_seconds` (default 24h, minimum 60s).

## Resume and new session

| Flow | Behavior |
|---|---|
| `/resume` | Pick an existing session; rebuild transcript from events; continue with same `session_id` |
| `/new` | Start a new session identity |
| Lazy draft | New interactive session without an initial prompt may delay DB row creation until first message |
| Process restart | Controller/runtime recover from session dir + DB; event projection rebuilds |

## History (`/history`)

`/history` is **conversation-only** (user-prompt rows). It supports navigating retained history / undo-redo semantics for the active session’s linear history model. File restore is **not** mixed into `/history` (extension-owned `/rewind` is separate and package-local).

Selected history position and retained-history replay are derived from the canonical event stream; details of projection live in the TUI/runtime implementation.

## Concurrency and locking

Session access uses cooperative locking so two interactive controllers do not corrupt the same session directory. Contenders fail closed or wait according to runtime lock helpers — do not hand-edit `events.jsonl` while a session is live.

## Storage notes

- SQLite (and other DBs) back metadata/queues as configured by the app; session **conversation** remains file-based events for portability and replay.
- Attachments and large tool outputs may live under tool temp paths (for example output-cap storage) with references from events — not as free-form copies inside every event payload.
- Fork **trees** / multi-branch session graphs are not a shipped user workflow in this documentation set; linear history is the supported model. If fork APIs appear experimentally, treat them as unfinished unless a release notes them.

## Compaction interaction

Compaction rewrites the LLM-visible history while retaining a recent raw tail and recording compaction events. Resume after compaction replays the compacted view correctly — see [compaction.md](compaction.md).

## Related

- Settings: [settings.md](settings.md)
- Agents: [agents.md](agents.md)
- Human input: [human-input.md](human-input.md)
