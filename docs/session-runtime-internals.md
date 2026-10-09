# Session runtime internals

Repository-only reference for run-state projection, replay measurements, and transition identity checks. User-facing storage and recovery guidance lives in [sessions](session-storage.md). See [runtime architecture](async-runtime-architecture.md) for process ownership.

## Events and operational state

- **`events.jsonl`**: append-only Run/TUI events used for resume and history; it is the canonical run authority.
- Run-control explicitly reconstructs a cold run through the indexed replay coordinator and retains one current `RunState` in its owner-local registry.
- The payload-free `run_operational_state`, `run_operational_tool_call`, and `run_operational_human_input` database projection supports bounded operational coordination. It never stores prompt history or other full payloads and is rebuilt from canonical events when needed.
- The single session-owned run-control consumer appends canonical events before updating operational projections and replacing its current state. Publication failure releases the owner-local state so recovery must reconstruct it.
- The controller acquires the session-owner lock, synchronously clears that owner’s disposable parent and child projection rows, and only then launches run-control and execution consumers. Execution workers make only narrow indexed status reads for cancellation.
- New and resumed active runs perform zero `state.json` reads or writes. Older unsupported event schemas have no fallback reader or migration.
- Sequence allocation uses `sequence.cursor` so multi-writer paths do not collide.

`history-index.sqlite` is disposable scalar metadata, not a second source of truth.
It stores actual committed record locations and immutable turn anchors. A missing
or invalid index triggers a streaming metadata pass, followed by selected-record
replay. Historical mappings stay on disk. Compaction locations do not replace
the operational evidence needed to recover pending work.

Parent attach produces execution state and display products in one owner-led
replay. Committed attach-policy events extend the temporary display before its
cut is sealed. The private `runtime/bootstrap` spool contains bounded transcript
blocks and scalar resume metadata, never `RunState` or raw conversation events.
The TUI validates and mounts the transfer before acknowledging its exact cut.
Acknowledgement releases the spool; bounded canonical suffix delivery ends with
`session.ready`. Slow screens do not hold the owner lock or accumulate event tails.

Runtime projects events into the TUI transcript. Keep transient stream deltas separate from canonical replay. During active polling, observers pass their last successfully applied canonical sequence into the runtime client; in-process delivery reverse-reads only the unseen durable suffix, while transient deltas remain unfiltered and are delivered first. The observer advances its cursor only after successful forwarding/application, so a failed poll retries the same canonical suffix rather than losing it.

## Transition validity

Run-control delivery is at-least-once. A completed or stale control message is acknowledged as a pure no-op. Messenger may still reject and re-enqueue a fresh unclaimed execution envelope on a legitimate handler failure. Session Doctrine DSNs use `redeliver_timeout=315360000` (~ten 365-day years) without `--keepalive`, so claimed rows stay unavailable within that horizon and can reclaim only after it. Restarting the same session reuses the same queue names and does not reset `delivered_at` age. Explicit `/repair` redrives the current same-token effects as fresh unclaimed envelopes and does not clear the abandoned claimed row. `/repair` is never automatic recovery. There is no receipt ledger: the run lock serializes transitions while canonical events, the payload-free operational projection, mailbox entries, tool-batch snapshots, and active operation identities are the bounded guards. Redrive repair appends no completion events because workers and result handlers remain authoritative. For a terminal history with unmatched assistant tool calls, repair can append a synthetic error `tool_execution_end` and `tool_batch_committed` to restore valid history. It does not repeat tool execution or append another `agent_end`. Existing `idempotency.jsonl` artifacts are inert user data: no migration, pruner, or deletion is performed, and new parent and child operations never create them.

| Scope | Expected current token | Committed evidence | Completed/stale duplicate behavior | Same-active/unfinished retry behavior | Stranded repair action |
|---|---|---|---|---|---|
| `command.start` | Queued initialization, or the shell-only `Completed`/model-null initialization case | Canonical `run_started` with a non-null model | No-op; a non-null-model `RunStarted` cannot be applied again | Normal control delivery may apply the still-valid initialization once | None; this transition has no detached execution effect |
| `command.apply` | Pending mailbox command identity and expected run generation | Mailbox command is consumed and canonical command/application events are committed | No-op; it cannot consume a later queued command | Normal control delivery applies the same still-pending command | None; a pending mailbox command remains available to normal delivery |
| `command.apply_shell` | Current shell command token (`turn`/`step`/attempt/key) and pending shell identity | Canonical `agent_command_applied`; pending shell state while execution is active, then canonical reverse-scan evidence after completion | No-op without another shell effect | The current `ExecuteShellToolCall` may retry only via reject+re-enqueue or `/repair --apply` | `/repair --apply` reconstructs and redrives the same direct-shell operation |
| `command.advance` | Expected predecessor turn and advance idempotency key | Replayed `lastAppliedAdvanceKey` plus successor/terminal or compaction-request event evidence | No-op before mailbox drain or successor dispatch | A still-valid unclaimed advance control delivery may perform the one transition | `/repair --apply` dispatches deterministic idle `AdvanceRun` at the current boundary |
| `result.llm` | Current LLM operation: turn, step, attempt, and idempotency key | Replayed bounded current-operation checkpoint and LLM completion/terminal events | No-op; no assistant message, batch, or effect is repeated | The current `ExecuteLlmStep` may retry only via reject+re-enqueue or `/repair --apply` | `/repair --apply` redispatches the exact current LLM operation |
| `result.tool` | Active batch, pending tool-call identity, terminal/suspension state, and human-input request identity | Durable tool-batch snapshot plus canonical tool result/execution/message events | No-op, including untracked ordinary results; no stale diagnostic event is appended | Pending tool execution may retry only via reject+re-enqueue or `/repair --apply`; parallel out-of-order collection remains valid | `/repair --apply` redrives durable pending/in-flight calls; waiting-for-human-input is not dispatched |
| `command.compact` | Current compaction request key and turn | Compaction request/start/failure evidence, current compaction operation, and last-applied compaction key | No-op before preparation hooks or worker dispatch | The current `ExecuteCompactionStep` may retry only via reject+re-enqueue or `/repair --apply` | `/repair --apply` redrives only a current compaction with its durable prepared worker request; historical starts without that payload are refused |
| `result.compaction` | Current compaction turn, step, attempt, and request key | Replayed current compaction operation and terminal compaction evidence | No-op; no false stale-failure lifecycle event | The matching current unclaimed execution result may be delivered normally | `/repair --apply` uses the same durable prepared request when available; otherwise it refuses safely |
