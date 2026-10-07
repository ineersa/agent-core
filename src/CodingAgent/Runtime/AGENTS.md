# Runtime

- Use `AgentSessionClient` as the TUI/runtime boundary.
- Keep worker lifecycle in the controller and durable transitions in `run_control`.
- Translate committed `RunEvent`, not `RunState`, into protocol DTOs.
- Keep transient deltas at sequence zero and out of durable replay.
- Send attach, history selection, and repair to `SessionMaintenanceHandler` through `run_control`.
- Do not reconstruct execution state or write canonical history in the controller.
- Attach must finish question cancellation and context refresh without starting a model turn.
