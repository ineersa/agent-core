# Runtime model

- `AgentSessionClient` is the TUI/runtime boundary.
- `Runtime/Contract` and `Runtime/Protocol` define command/event DTOs for session/runtime protocol surfaces.
- Controller owns workers and process lifecycle. `run_control` owns `RunState` and durable transitions. `RuntimeEventTranslator` consumes `RunEvent` (not `RunState`) to build protocol DTOs.
- Live committed events travel on controller stdout. Transient stream deltas use sequence `0` and stay out of durable replay.
- Application LLM retries emit transient `llm.request_retrying` (seq=`0`) for TUI working-status progress. Retry observer wiring is independent of `streamObserverEnabled`; compaction keeps stream deltas suppressed.
- TUI may depend on CodingAgent directly for ordinary service ownership; do not add Runtime/Contract catalog interfaces solely to isolate TUI from CodingAgent modules. Follow `depfile.yaml`.
- CodingAgent must not depend on TUI.
- `Runtime/InProcess` calls AgentCore directly; `Runtime/Process` uses headless JSONL subprocess.
- `src/CodingAgent/CLI/AgentCommand.php` wires TUI via `Ineersa\Tui\Application\InteractiveMode`.
- Canonical source: `.hatfield/sessions/<id>/events.jsonl` via `EventStoreInterface`. Overview: `docs/async-runtime-architecture.md`.

## Application maintenance commands

`AttachRun`, `SelectHistoryPrompt`, and `RepairSession` live in `CodingAgent\Application\Message`. Controllers and the in-process client submit them on `agent.command.bus` to `run_control`. `SessionMaintenanceHandler` owns their application policy and runtime replies. Sharing a transport with framework commands does not make them AgentCore concepts.

Attach completes pending-question cancellation and context refresh through `RunMessageProcessor` before returning, without starting a model turn. History selection and repair use `RunCommit` under the owner lock. The controller neither reconstructs execution state nor writes canonical history.
