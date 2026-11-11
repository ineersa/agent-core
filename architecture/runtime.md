# Runtime supervision

[Architecture](README.md) · [Processes and queues](processes-and-queues.md)

The TUI uses `AgentSessionClient`. Production process mode communicates with a
session controller through long-lived JSONL stdin/stdout pipes; in-process mode
is available for tests and simple runs. The controller owns the session lock,
broker and consumer processes, not run transitions.

## Admission and delivery

The controller ACKs commands before dispatch. An accepted command can still fail
at dispatch or execution. `run_control` owns canonical state changes, and
`RuntimeEventMapper` / `RuntimeEventTranslator` map committed `RunEvent` values
into protocol DTOs. They do not consume `RunState`. LLM stream events are transient
sequence-0 deltas. Partial stdout records remain buffered until their newline.

## Failure boundaries

| Failure | Behavior |
|---|---|
| Consumer exits | Supervisor may restart within its budget; not a guarantee the current effect is replayed |
| Lease renewal fails | Messenger logs a warning; it does not stop the handler. Expired leases allow redelivery |
| Broker dies | Controller emits fatal `protocol.error`, then shuts down. Client keeps that diagnostic and refuses commands rather than transparently reconnecting |
| Unexpected nonfatal controller loss | Process client can replace the controller and reattach; durable history and operational evidence govern recovery |
| Canonical commit succeeds, later publication fails | Events remain authoritative; discard owner-local state and reconstruct before continuing |

Fatal diagnostics survive parent, child and session-wide polling until an explicit
start, attach or shutdown resets the client boundary. The TUI marks the session
failed rather than hiding the original broker failure behind a generic reconnect.
`/repair` is explicit; see [recovery](state-and-recovery.md).

## Limits

- Broker lease: **60 seconds**; keepalive interval: **5 seconds**.
- Consumer restart budget: **3 per key in 60 seconds**, initial delay **1 second**.
- Shared-consumer shutdown grace: **5 seconds**; stderr diagnostic tail: **16,384 bytes**.
- Worker memory recycle threshold: **256 MiB**; no message-count recycle boundary.
- Queue `max_payload`: **1,000,000,000 bytes**, configured in
  `config/packages/sqlite_queue.yaml`. This is independent of the bounded
  disposable transcript-bootstrap spool.
- `SessionSqliteQueueBrokerTopology` derives required connections from the resolved
  consumer topology. Controller readiness rejects insufficient advertised budgets.

`HATFIELD_BINARY_PATH` chooses the subprocess executable for source, PHAR and
static builds. MCP disconnect and process cancellation are best-effort, not a
sandbox or rollback guarantee. Extension construction/registration can fail startup.

Sources: [process client](../src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php),
[controller](../src/CodingAgent/Runtime/Controller/HeadlessController.php),
[ConsumerSupervisor](../src/CodingAgent/Runtime/Controller/ConsumerSupervisor.php),
[queue configuration](../config/packages/sqlite_queue.yaml).
