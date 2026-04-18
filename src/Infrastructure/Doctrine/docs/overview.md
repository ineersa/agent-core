# Infrastructure\Doctrine

## Version20260418000100

Initial migration creating the agent-loop persistence schema:

| Table | Purpose |
|-------|---------|
| `agent_run_event` | Event sourcing store — runId, seq, turnNo, type, payload, createdAt |
| `agent_command` | Pending command queue — runId, kind, idempotencyKey, payload, status |
| `agent_run` | Run aggregate state — runId, status, version, turnNo, state snapshot |
| `agent_outbox` | Outbox pattern — sink, event data, attempts, availableAt |
| `agent_prompt_state` | Hot prompt cache — runId, state blob, timestamps |
| `agent_artifact` | Named artifacts — runId, name, content, metadata |
