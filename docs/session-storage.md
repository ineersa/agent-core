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
| Catalog recovery | On startup after schema migrations, orphan numeric `sessions/<id>/events.jsonl` dirs without a `hatfield_session` row are reinserted into the catalog (same id) |

### Catalog recovery after state DB loss

If `.hatfield/state.sqlite` is deleted or loses `hatfield_session` rows while session directories remain, startup reconciles **canonical** positive-digit directories that contain `events.jsonl`:

- **Canonical IDs only:** directory name must be a positive decimal whose integer round-trip equals the original string (rejects `0`, `007`, non-digits, and integer-overflow aliases).
- **Preserved:** directory name as `session_id` / `run_id`; existing `events.jsonl` / `state.json` bytes are never rewritten or truncated.
- **Recovered into the row when present in events:** initial user prompt (and default display name from it), current model (including later `model_changed`), reasoning from `run_started` metadata, child `parent_run_id` when present.
- **Not event-backed:** a fresh UUIDv7 `provider_cache_key` is generated; renames and other DB-only fields are not restored when absent from events.
- **Not recoverable from session events (SQLite-only):** deferred subagent batches/children, background processes, pending tool questions, messenger queues, and other app-state tables.

New session creation uses atomic exclusive `mkdir` of the leaf session path and fails closed before writing `state.json` / `events.jsonl` when any directory, file, or symlink already occupies that path (including malformed orphans). Concurrent recovery inserts are idempotent via `ON CONFLICT(id) DO NOTHING` on the primary key. Corrupt event logs are skipped with privacy-safe diagnostics; DB/storage infrastructure failures hard-fail startup.

## History (`/history`)

`/history` is **conversation-only** (user-prompt rows). It supports navigating retained history / undo-redo semantics for the active session’s linear history model. File restore is **not** mixed into `/history` (extension-owned `/rewind` is separate and package-local).

Selected history position and retained-history replay are derived from the canonical event stream; details of projection live in the TUI/runtime implementation.

## Concurrency and locking

Session access uses cooperative locking so two interactive controllers do not corrupt the same session directory. Contenders fail closed or wait according to runtime lock helpers — do not hand-edit `events.jsonl` while a session is live.

## Storage notes

- SQLite (and other DBs) back metadata/queues as configured by the app; session **conversation** remains file-based events for portability and replay.
- Attachments and large tool outputs may live under tool temp paths (for example output-cap storage) with references from events — not as free-form copies inside every event payload.
- The model-visible `fork` tool is **shipped** (isolated child with inherited parent context; see [agents.md](agents.md)). Linear history remains the supported user model: multi-branch session **trees**, `/tree` UI, and session-graph browsing are **not** shipped end-user workflows.

## Compaction interaction

Compaction rewrites the LLM-visible history while retaining a recent raw tail and recording compaction events. Resume after compaction replays the compacted view correctly — see [compaction.md](compaction.md).

## Related

- Settings: [settings.md](settings.md)
- Agents: [agents.md](agents.md)
- Human input: [human-input.md](human-input.md)

## State-transition duplicate delivery

Run-control does not maintain a receipt ledger. The run lock and CAS serialize
transitions; committed `StartRun`, queued command mailbox entries, finalized tool
batches, and the active LLM checkpoint are the current bounded duplicate guards.
A stale LLM or tool result is a no-op. Explicit `/repair` first preserves
cancellation and canonical-event corruption repair, then may redispatch a current
LLM, durable tool-batch, direct-shell, or advance message with its existing
identity. It appends no completion events; workers and result handlers remain
authoritative. Current compaction recovery redispatches its bounded exact
prepared worker payload without rerunning hooks; historical compaction starts
without that payload are refused non-destructively.

| Scope | Current authoritative evidence | Committed/stale delivery |
|---|---|---|
| `command.start` | non-queued `RunState` | no-op |
| `command.apply` | command mailbox idempotency key | no-op |
| `command.apply_shell` (standalone) | bounded shell checkpoint | no-op |
| `command.advance` | expected predecessor turn plus the last committed advance key (replayed from the canonical transition event) | no-op before mailbox drain |
| `result.llm` | active `(turn, step, attempt)` checkpoint | no-op |
| `result.tool` | finalized batch/pending call/HITL identity | no-op |
| `command.compact` / `result.compaction` | canonical start payload, bounded active `(turn, step, attempt, key, worker input)` checkpoint, and last completed key | stale/completed delivery is a no-op before hooks or effects; `/repair` redrives only reconstructible current input |

Legacy `idempotency.jsonl` artifacts are inert user data: no migration, pruner, or
deletion is performed. New parent and child operations never create them.
