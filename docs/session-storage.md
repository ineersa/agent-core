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
- Canonical event types are exactly `RunEventTypeEnum`; unsupported or removed wire types fail loudly during normalization/replay. Active-development logs written before the vocabulary cleanup are intentionally unsupported.
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

Runtime projects events into the TUI transcript. Keep transient stream deltas separate from canonical replay. Terminal `tool_execution_end.payload.tool_result` is the sole complete durable tool-result authority; replay creates ordered model-visible tool messages at `tool_batch_committed`, while direct-shell results remain non-model-visible. Nonterminal `tool_execution_update` is transient only; terminal snapshots remain canonical for child-artifact recovery. `llm_step_completed.assistant_message` owns assistant text and tool calls for runtime/export; it has no top-level `text` or `tool_calls_count` duplicate. During active polling, observers pass their last successfully applied canonical sequence into the runtime client; in-process delivery reverse-reads only the unseen durable suffix, while transient deltas remain unfiltered and are delivered first. The observer advances its cursor only after successful forwarding/application, so a failed poll retries the same canonical suffix rather than losing it.

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
- **Preserved:** directory name as `session_id` / `run_id`; existing `events.jsonl` / `state.json` bytes are never rewritten or truncated.
- **Recovered into the row when present in events:** initial user prompt (and default display name from it), current model and reasoning from `run_started` metadata, child `parent_run_id` when present.
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
- Output-cap files live in `tools.output_cap.path/run-<sha256(run_id)>/` and are ephemeral controller-session artifacts. Controller start/resume removes stale scopes for the parent and registered child runs before consumers start; controlled shutdown and explicit session deletion remove the same scopes. Completion, cancellation, and failure remain resumable states, so they are not artifact-cleanup boundaries. Historical `saved_path` notices may therefore point to deleted files: replay, repair, and transcript projection use canonical event payloads and never dereference output-cap files.
- The existing `tools.output_cap.retention` (24 hours by default) is only a first-use orphan/crash fallback. Legacy date-prefixed files at the output-cap root are never lifecycle-deleted. One-time operator cleanup requires separate authorization: quiesce project controllers, verify the canonical configured root, dry-run only direct regular `YYYYMMDD-<16 lowercase hex>.txt` entries for an explicitly approved date range, review count/bytes/names, then individually delete only the reviewed names without recursion. Historical custom `session_prefix` root files do not match lifecycle or automatic fallback patterns; they are inert, operator-owned artifacts that require separate exact-name review and individual non-recursive authorization. Do not use wildcard or root wipes.
- The model-visible `fork` tool is **shipped** (isolated child with inherited parent context; see [agents.md](agents.md)). Linear history remains the supported user model: multi-branch session **trees**, `/tree` UI, and session-graph browsing are **not** shipped end-user workflows.

## Transition validity

Run-control delivery is at-least-once. A completed or stale control message is acknowledged as a pure no-op; this is separate from normal Messenger retry/redelivery of an **execution** message for an operation that remains current and unfinished. `/repair --apply` is explicit user-authorized same-token redrive, never automatic recovery. The run lock and CAS serialize transitions while committed state, mailbox entries, tool-batch snapshots, and active operation identities are the bounded guards. Repair appends no completion events; workers and result handlers remain authoritative.

| Scope | Expected current token | Committed evidence | Completed/stale duplicate behavior | Same-active/unfinished retry behavior | Stranded repair action |
|---|---|---|---|---|---|
| `command.start` | Queued initialization, or the shell-only `Completed`/model-null initialization case | Canonical `run_started` with a non-null model | No-op; a non-null-model `RunStarted` cannot be applied again | Normal control delivery may apply the still-valid initialization once | None; this transition has no detached execution effect |
| `command.apply` | Pending mailbox command identity and expected run generation | Mailbox command is consumed and canonical command/application events are committed | No-op; it cannot consume a later queued command | Normal control delivery applies the same still-pending command | None; a pending mailbox command remains available to normal delivery |
| `command.apply_shell` | Current shell command token (`turn`/`step`/attempt/key) and pending shell identity | Canonical `agent_command_applied`; pending shell state while execution is active, then canonical reverse-scan evidence after completion | No-op without another shell effect | The current `ExecuteShellToolCall` may retry through Messenger | `/repair --apply` reconstructs and redrives the same direct-shell operation |
| `command.advance` | Expected predecessor turn and advance idempotency key | Replayed `lastAppliedAdvanceKey` plus successor/terminal or compaction-request event evidence | No-op before mailbox drain or successor dispatch | A still-valid advance control delivery may perform the one transition | `/repair --apply` dispatches deterministic idle `AdvanceRun` at the current boundary |
| `result.llm` | Current LLM operation: turn, step, attempt, and idempotency key | Replayed bounded current-operation checkpoint and LLM completion/terminal events | No-op; no assistant message, batch, or effect is repeated | The current `ExecuteLlmStep` may retry through Messenger | `/repair --apply` redispatches the exact current LLM operation |
| `result.tool` | Active batch, pending tool-call identity, terminal/suspension state, and human-input request identity | Durable tool-batch snapshot plus canonical typed `tool_execution_end` result and commit boundary | No-op, including untracked ordinary results; no stale diagnostic event is appended | Pending tool execution messages may retry; parallel out-of-order collection remains valid | `/repair --apply` redrives durable pending/in-flight calls; waiting-for-human-input is not dispatched |
| `command.compact` | Current compaction request key and turn | Compaction request/start/failure evidence, current compaction operation, and last-applied compaction key | No-op before preparation hooks or worker dispatch | The current `ExecuteCompactionStep` may retry through Messenger | `/repair --apply` redrives only a current compaction with its durable prepared worker request; historical starts without that payload are refused |
| `result.compaction` | Current compaction turn, step, attempt, and request key | Replayed current compaction operation and terminal compaction evidence | No-op; no false stale-failure lifecycle event | The matching current execution result may be delivered normally | `/repair --apply` uses the same durable prepared request when available; otherwise it refuses safely |

## Compaction interaction

Compaction rewrites the LLM-visible history while retaining a recent raw tail and recording compaction events. Resume after compaction replays the compacted view correctly — see [compaction.md](compaction.md).

## Ephemeral output-cap artifacts

Oversized tool output is saved under `tools.output_cap.path/run-<sha256(run_id)>/` only while a controller owns that session. Controller start/resume clears prior parent and child scopes; controller shutdown and explicit deletion dispatch the same cleanup before canonical session metadata is removed. Completion, cancellation, and failure remain resumable boundaries and do not clear these artifacts. Saved output-cap paths in canonical notices are metadata only: replay, repair, and projection never read the artifact, so historical notices can refer to deleted files. A 24-hour first-use stale cleanup remains the crash/orphan fallback.

Legacy date-prefixed root files are intentionally not lifecycle-deleted, but exact date-prefixed files are covered by the existing 24-hour automatic fallback and the separately authorized dry-run procedure. Operators may clean them only with quiesced controllers after verifying the configured root, dry-running an exact `^\d{8}-[a-f0-9]{16}\.txt$` name/date selection, reviewing names, and deleting each approved direct file non-recursively. Historical custom `session_prefix` root files match neither lifecycle nor automatic fallback patterns: they are inert, operator-owned artifacts requiring separate exact-name review and individual non-recursive authorization.

## Related

- Settings: [settings.md](settings.md)
- Agents: [agents.md](agents.md)
- Human input: [human-input.md](human-input.md)
