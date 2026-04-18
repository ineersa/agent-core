# Stage 11 - Reference Schemas and Event Examples

## Goal
Provide concrete reference payloads for implementation consistency across orchestrator, workers, and clients.

All examples use bundle canonical DTOs at API/worker boundaries. Before provider invocation, `convertToLlm` maps these payloads to Symfony AI message objects (`MessageBag`, `UserMessage`, `AssistantMessage`, `ToolCallMessage`, etc.).

## Command Payload Examples

### Start run
```json
{
  "command": "StartRun",
  "run_id": "d2f2f4ab-8d80-4c6f-84bc-96db31207c72",
  "user_id": "9fe6dfab-5e88-4c8f-89fa-72fe8dd57c08",
  "idempotency_key": "start:d2f2f4ab",
  "initial_message": {
    "role": "user",
    "content": [{ "type": "text", "text": "Analyze repo" }],
    "timestamp": 1770000000
  }
}
```

### Steer
```json
{
  "command": "ApplySteerCommand",
  "run_id": "d2f2f4ab-8d80-4c6f-84bc-96db31207c72",
  "idempotency_key": "steer:42",
  "message": {
    "role": "user",
    "content": [{ "type": "text", "text": "Stop and summarize first" }],
    "timestamp": 1770001111
  }
}
```

### Extension command
```json
{
  "command": "ApplyExtensionCommand",
  "run_id": "d2f2f4ab-8d80-4c6f-84bc-96db31207c72",
  "kind": "ext:compaction:compact",
  "idempotency_key": "ext-compaction-3",
  "options": { "cancel_safe": false },
  "payload": {
    "custom_instructions": "Summarize implementation decisions and open risks"
  }
}
```

### Cancel-safe extension command
```json
{
  "command": "ApplyExtensionCommand",
  "run_id": "d2f2f4ab-8d80-4c6f-84bc-96db31207c72",
  "kind": "ext:cleanup:flush",
  "idempotency_key": "ext-cleanup-1",
  "options": { "cancel_safe": true },
  "payload": { "reason": "run_cancelled" }
}
```

## Execution Payload Examples

### Execute LLM step
```json
{
  "type": "ExecuteLlmStep",
  "run_id": "d2f2f4ab-8d80-4c6f-84bc-96db31207c72",
  "turn_no": 3,
  "step_id": "turn-3-llm-1",
  "attempt": 1,
  "context_ref": "hot:run:d2f2f4ab",
  "tools_ref": "toolset:tenant:acme:turn:3"
}
```

### LLM step result
```json
{
  "type": "LlmStepResult",
  "run_id": "d2f2f4ab-8d80-4c6f-84bc-96db31207c72",
  "turn_no": 3,
  "step_id": "turn-3-llm-1",
  "assistant_message": {
    "role": "assistant",
    "content": null,
    "tool_calls": [
      {
        "id": "call_tc_1",
        "name": "web_search",
        "arguments": { "query": "symfony workflow" }
      }
    ],
    "stop_reason": "tool_call",
    "timestamp": 1770001234
  },
  "usage": { "input_tokens": 1234, "output_tokens": 345, "total_tokens": 1579 },
  "error": null
}
```

### Tool result message (next turn input)
```json
{
  "role": "tool",
  "tool_call": {
    "id": "call_tc_1",
    "name": "web_search",
    "arguments": { "query": "symfony workflow" }
  },
  "content": "{\"is_error\":false,\"content\":[{\"type\":\"text\",\"text\":\"Top result: ...\"}],\"details_ref\":\"artifact://run/d2f2/call_tc_1_result.json.zst\"}",
  "timestamp": 1770001238
}
```

## JSONL Event Examples

### Turn start
```json
{"seq":21,"run_id":"d2f2f4ab-8d80-4c6f-84bc-96db31207c72","turn_no":3,"type":"turn_start","payload":{},"ts":"2026-04-12T12:12:12Z"}
```

### Tool execution end
```json
{"seq":27,"run_id":"d2f2f4ab-8d80-4c6f-84bc-96db31207c72","turn_no":3,"type":"tool_execution_end","payload":{"tool_call_id":"call_tc_1","tool_name":"web_search","is_error":false,"result_ref":"artifact://run/d2f2/call_tc_1_result.json.zst"},"ts":"2026-04-12T12:12:17Z"}
```

### Extension event
```json
{"seq":28,"run_id":"d2f2f4ab-8d80-4c6f-84bc-96db31207c72","turn_no":3,"type":"ext_compaction_start","payload":{"reason":"threshold","requested_by":"policy"},"ts":"2026-04-12T12:12:18Z"}
```

### Run end
```json
{"seq":33,"run_id":"d2f2f4ab-8d80-4c6f-84bc-96db31207c72","turn_no":4,"type":"agent_end","payload":{"reason":"completed"},"ts":"2026-04-12T12:12:30Z"}
```

## Minimal SQL Indexing Guidance
- `agent_runs(status, updated_at)` for scheduler scans.
- `agent_commands(run_id, status, created_at)` for mailbox reads.
- unique `agent_commands(run_id, idempotency_key)` for command dedupe.
- `agent_turn_index(run_id, turn_no)` for transcript pagination.

## Event Naming Conventions
- Use lowercase snake_case for persisted event type names.
- Keep API stream event names aligned with JS names where possible.
- Reserve core lifecycle names for bundle events.
- Extension events must use `ext_` prefix.
- Maintain a mapping table in code for internal <-> public event names.

## Contract Versioning
- Add `schema_version` to command and event payloads.
- New fields must be additive in minor versions.
- Breaking shape changes require migration and explicit compatibility layer.

## Deliverables
- Shared schema package in the bundle (`src/Schema`).
- Serializer normalizers for command/event payloads.
- Golden file tests for representative JSON payloads.

## Acceptance Criteria
- Worker and API payload schemas validate against the same contract set.
- Golden file tests catch accidental payload shape drift.
