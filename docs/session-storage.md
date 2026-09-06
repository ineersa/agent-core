---
builtin: true
description: Session identity, storage layout, events, resume, locking, and history operations.
---

# Hatfield Sessions

**One directory = one session = one agent run.** `session_id === run_id`.

## Invariants

- The TUI session and AgentCore run share one identity (DB-issued numeric string).
- Each session row also stores immutable `provider_cache_key` (UUIDv7). Codex maps it to `prompt_cache_key`; generic providers omit Hatfield correlation fields, while Grok maps the session id to its `prompt_cache_key`.
- Everything needed to resume lives under the session directory plus the `hatfield_session` DB row — there is no global `.hatfield/runs/` registry.
- Canonical conversation source is append-only `events.jsonl`. Transcript projection rebuilds from events on resume.
- There is **no** `metadata.yaml` in the session directory.

## Directory layout

Base path: `sessions.path` setting (default under project `.hatfield/sessions/`).

```text
.hatfield/sessions/<session_id>/
  events.jsonl          # canonical event log
  sequence.cursor       # event sequence allocation
  artifacts/            # child agent artifacts (when used)
  ...                   # other runtime sidecars as created
```

### Database metadata

Table `hatfield_session` stores id, display name, timestamps, provider cache key, and related session metadata. Directory name is canonical; embedded IDs are validated on read.

### Naming

Sessions may be renamed via `/rename`. Display names are metadata only — they do not change `session_id`.

## Events and operational state

- `events.jsonl` is the canonical conversation history used for resume.
- The runtime rebuilds its working state and disposable database projections from
  canonical events. Those projections do not contain a second copy of prompt history.
- New and resumed runs do not read or write `state.json`. Older event schemas are
  not supported through a compatibility reader.
- `sequence.cursor` allocates event sequence numbers. It is not itself conversation history.
- Transient streamed text is separate from durable events. Resume rebuilds from
  committed history, not from an unfinished stream.

## Child artifacts

Foreground subagent runs store parent-scoped artifacts under the parent session (handoff text, metadata, bounded event/history summaries). Retrieve with `agent_retrieve` (see [agents.md](agents.md)).

Deferred subagent supervision (single and parallel) uses durable batch records and timeouts configured by `agents.subagent_tool_timeout_seconds` (default 24h, minimum 60s). Recovery reads child event logs backward from the durable tail until its stored event sequence cursor, then restores chronological order; it does not treat `sequence.cursor` as event-tail truth because allocation may leave valid sequence holes.

## Resume and new session

| Flow | Behavior |
|---|---|
| `/resume` | Pick an existing session; rebuild transcript from events; continue with same `session_id` |
| `/new` | Start a new session identity |
| Lazy draft | New interactive session without an initial prompt may delay DB row creation until first message |
| Process restart | Controller/runtime recover from session dir + DB; event projection rebuilds |
| Catalog recovery | On startup after schema migrations, orphan numeric `sessions/<id>/events.jsonl` dirs without a `hatfield_session` row are reinserted into the catalog (same id) |

### Catalog recovery after state DB loss

If `.hatfield/state.sqlite` is deleted or loses `hatfield_session` rows while session directories remain, startup reconciles **canonical** positive-digit directories that contain `events.jsonl`:

- **Canonical IDs only:** directory name must be a positive decimal whose integer round-trip equals the original string (rejects `0`, `007`, non-digits, and integer-overflow aliases).
- **Preserved:** directory name as `session_id` / `run_id`; existing canonical `events.jsonl` bytes are never rewritten or truncated.
- **Recovered into the row when present in events:** initial user prompt (and default display name from it), current model (including later `model_changed`), reasoning from `run_started` metadata, child `parent_run_id` when present.
- **Not event-backed:** a fresh UUIDv7 `provider_cache_key` is generated; renames and other DB-only fields are not restored when absent from events.
- **Not recoverable from session events (SQLite-only):** deferred subagent batches/children, background processes, pending tool questions, messenger queues, and other app-state tables.

New session creation uses atomic exclusive `mkdir` of the leaf session path and fails closed before writing `events.jsonl` when any directory, file, or symlink already occupies that path (including malformed orphans). Concurrent recovery inserts are idempotent via `ON CONFLICT(id) DO NOTHING` on the primary key. Corrupt event logs are skipped with privacy-safe diagnostics; DB/storage infrastructure failures hard-fail startup.

## History (`/history`)

`/history` is **conversation-only** (user-prompt rows). It supports navigating retained history / undo-redo semantics for the active session’s linear history model. File restore is **not** mixed into `/history` (extension-owned `/rewind` is separate and package-local).

Selected history position and retained-history replay are derived from the canonical event stream; details of projection live in the TUI/runtime implementation.

## Concurrency and locking

Session access uses cooperative locking so two interactive controllers do not corrupt the same session directory. Contenders fail closed or wait according to runtime lock helpers — do not hand-edit `events.jsonl` while a session is live.

## Storage notes

- SQLite (and other DBs) back metadata/queues as configured by the app; session **conversation** remains file-based events for portability and replay.
- Attachments and large tool outputs may live under tool temp paths (for example output-cap storage) with references from events — not as free-form copies inside every event payload. Lifecycle detail: [Ephemeral output-cap artifacts](#ephemeral-output-cap-artifacts).
- The model-visible `fork` tool is **shipped** (isolated child with inherited parent context; see [agents.md](agents.md)). Linear history remains the supported user model: multi-branch session **trees**, `/tree` UI, and session-graph browsing are **not** shipped end-user workflows.

## Transition validity

The runtime ignores completed or stale control messages, but tool execution is not
an exactly-once guarantee. A retried operation can repeat external effects.

### Repair safety

`/repair` is explicit recovery, never automatic. Inspect its diagnosis before using
`/repair --apply` to redispatch stranded work. Restarting a session does not by itself
make abandoned claimed queue messages available again.

Repair reuses the current operation identity. It does not mark unfinished work as
completed, roll back side effects, or clear abandoned claimed messages. Check whether
the original command or external tool already performed its action before redispatching.

Calls waiting for human input are not redispatched. Compaction repair requires a
saved prepared request and refuses safely when that request is unavailable.
Do not edit queue rows or event logs to force recovery while a controller is live.

Existing `idempotency.jsonl` files are inert legacy data. Current runs do not create
them, and they are not a repair mechanism.

## Compaction interaction

Compaction rewrites the LLM-visible history while retaining a recent raw tail and recording compaction events. Resume after compaction replays the compacted view correctly — see [compaction.md](compaction.md).

## Ephemeral output-cap artifacts

Oversized tool output is saved under `tools.output_cap.path/run-<sha256(run_id)>/` only while a controller owns that session. Controller start/resume clears prior parent and child scopes; controller shutdown and explicit deletion dispatch the same cleanup before canonical session metadata is removed. Completion, cancellation, and failure remain resumable boundaries and do not clear these artifacts. Saved output-cap paths in canonical notices are metadata only: replay, repair, and projection never read the artifact, so historical notices can refer to deleted files. A 24-hour first-use stale cleanup remains the crash/orphan fallback.

Legacy date-prefixed root files are intentionally not lifecycle-deleted, but exact date-prefixed files are covered by the existing 24-hour automatic fallback and the separately authorized dry-run procedure. Operators may clean them only with quiesced controllers after verifying the configured root, dry-running an exact `^\d{8}-[a-f0-9]{16}\.txt$` name/date selection, reviewing names, and deleting each approved direct file non-recursively. Historical custom `session_prefix` root files match neither lifecycle nor automatic fallback patterns: they are inert, operator-owned artifacts requiring separate exact-name review and individual non-recursive authorization.

## Related

- Terminal commands: [terminal-usage.md](terminal-usage.md)
- Settings: [settings.md](settings.md)
- Agents: [agents.md](agents.md)
- Human input: [human-input.md](human-input.md)
