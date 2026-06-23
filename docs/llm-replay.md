# LLM Replay Fixture Format

Deterministic replay fixtures for LLM interactions. Replaces live llama.cpp/OpenAI-compatible
provider calls in QA/test environments with pre-recorded stream deltas.

## Purpose

Normal QA (`castor test`, `castor check`) should not depend on a live LLM endpoint.
Instead, tests replay recorded fixtures that capture the exact stream of deltas a provider
returned during a reference run.

Live LLM is opt-in: re-record fixtures explicitly with `castor llm:fixtures:record` when
provider behavior, prompts, or tool schemas change.


## llama-proxy (live smoke HTTP cache)

Live tests (`castor test:llm-real`, `castor test:controller`) can run through
[llama-proxy](file:///home/ineersa/projects/llama-proxy) on port `9052`. The proxy
SHA-256-caches the full JSON request body (method, path, query, sorted body).

Hatfield enables **deterministic prompt prefix** when `LLAMA_CPP_SMOKE_TEST=1`
or `HATFIELD_LLM_PROXY_DETERMINISTIC=1`:

- System prompt: `{date}` is empty; `{cwd}` stays the **real** runtime cwd (tools must not see a fake path).
- `~/.hatfield/APPEND_SYSTEM.md`, `{cwd}/.hatfield/APPEND_SYSTEM.md`, and extension prompt contributors are omitted.
- AGENTS.md context: repo-root / `.hatfield` / `.agents` AGENTS.md from `%kernel.project_dir%` with stable display paths (`fixedCwd()` in XML only).
- Skills: discovered from project `.agents/skills` / `.hatfield/skills` only; skill `<location>` uses stable display paths.

DI fixture replay (`HATFIELD_LLM_REPLAY_FIXTURE_PATH`) is unchanged and does not use the proxy.

Proxy admin: `curl http://127.0.0.1:9052/__llama_proxy/cache/stats` and `POST .../cache/clear`.

## Fixture format (JSON)

```json
{
    "$schema": "Fixture version and description (optional, human-oriented)",
    "model": "provider/model-id",
    "provider_id": "provider-id",
    "reasoning": "off|minimal|low|medium|high|xhigh",
    "recorded_at": "ISO-8601 timestamp",
    "recording_source": "llama_cpp_test/test | openai/gpt-5 | ...",
    "input": {
        "messages": [
            {"role": "user|assistant|system|tool", "content": "message text"}
        ]
    },
    "deltas": [
        {"type": "text", "content": "..."},
        {"type": "thinking", "content": "..."},
        {"type": "thinking_signature", "content": "..."},
        {"type": "tool_call_start", "id": "call_1", "name": "tool_name"},
        {"type": "tool_input_delta", "id": "call_1", "name": "tool_name", "partial_json": "..."},
        {"type": "tool_call_complete", "tool_calls": [{"id": "call_1", "name": "read", "arguments": {"path": "./file.txt"}}]}
    ],
    "usage": {
        "input_tokens": 42,
        "output_tokens": 87,
        "total_tokens": 129
    },
    "stop_reason": "stop | tool_call | length | content_filter | null",
    "expected_text": "Optional: the expected assistant message text for assertion convenience"
}
```

### Delta types

| type | When | Fields |
|------|------|--------|
| `text` | Text delta from assistant | `content` |
| `thinking` | Thinking/reasoning content | `content` |
| `thinking_signature` | Thinking signature | `content` |
| `tool_call_start` | Tool call starts streaming | `id`, `name` |
| `tool_input_delta` | Tool argument chunk | `id`, `name`, `partial_json` |
| `tool_call_complete` | All tool calls finalized | `tool_calls[]` (id, name, arguments) |

### Usage

Token usage metadata. All fields are nullable integers:
- `input_tokens`: prompt tokens
- `output_tokens`: completion tokens
- `total_tokens`: sum of above

### stop_reason

| Value | Meaning |
|-------|---------|
| `stop` | Normal completion (end-of-text token) |
| `tool_call` | Model stopped because it called tools |
| `length` | Max tokens reached |
| `content_filter` | Content filter blocked output |
| `null` | Unknown / not captured |

## Recording

Fixtures are recorded from a live LLM endpoint using `castor llm:fixtures:record`.
The recording path uses `LlmStreamObserverInterface` to capture every delta as it
flows through `LlmPlatformAdapter::consumeStream()`.

Recording writes fixtures to `tests/AgentCore/Fixtures/traces/` by default.

### Safety

- Recording never runs during default `castor test` or `castor check`.
- Recording requires an explicit env/command opt-in.
- If no live LLM endpoint is reachable, the command fails with a diagnostic.
- Overwrite protection: existing fixtures are backed up or require `--force`.

## Replay

Tests use `FixtureReplayModelClient` and `FixtureReplayResultConverter` to substitute
the live HTTP transport with fixture-driven deltas. The replay exercises the full
`LlmPlatformAdapter` path (stream conversion, message building, usage extraction).

### Seams

| Seam | What it replaces | What stays real |
|------|-----------------|-----------------|
| `ModelClientInterface` | HTTP call to provider API | `LlmPlatformAdapter`, `AgentMessageConverter`, stream assembly |
| `ResultConverterInterface` | Stream result production | Same as above |
| `LlmStreamObserverInterface` | N/A (recording, not replay) | N/A |

### Adding a replay test

```php
$fixture = json_decode(file_get_contents('tests/AgentCore/Fixtures/traces/my-fixture.json'), true);
$modelClient = new FixtureReplayModelClient($fixture);
// ... build Platform with FixtureReplayResultConverter, wire through LlmPlatformAdapter ...
```

See `tests/AgentCore/Infrastructure/SymfonyAi/Replay/ReplayTest.php` for full examples.

## Raw provider stream capture

For debugging `DurableResultConverter` behavior around multi-tool calls,
phantom chunks, or upstream compatibility issues, an opt-in capture path
writes every raw SSE `data:` chunk plus correlated converted deltas to a
JSONL file.

**Env vars:**

| Variable | Default | Purpose |
|----------|---------|--------|
| `HATFIELD_LLM_RAW_STREAM_CAPTURE` | (unset) | Set to `1` to enable capture |
| `HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH` | auto-generated | Output JSONL path override |

Default path: `<cwd>/var/tmp/llm-raw-stream-capture-<timestamp>-<id>.jsonl`

The capture seam lives in `DurableResultConverter::convertStream()` via an
optional `$onStreamEvent` closure.  It is injected only for generic
OpenAI-compatible providers in `SymfonyAiProviderFactory` and only when the
env var is set.

### Usage

```bash
castor run:agent-capture
```

Or manually:

```bash
HATFIELD_LLM_RAW_STREAM_CAPTURE=1 \
  HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH=/tmp/my-capture.jsonl \
  bin/console agent
```

### JSONL shape

```jsonl
{"event":"capture_start","provider_id":"zai","timestamp":"..."}
{"event":"raw_chunk","ordinal":0,"timestamp":"...","data":{"choices":[{"delta":{"tool_calls":[...]}}]}}
{"event":"converted_delta","ordinal":0,"timestamp":"...","type":"ToolCallStart","id":"call_1","name":"read"}
{"event":"converted_delta","ordinal":1,"timestamp":"...","type":"ToolInputDelta","id":"call_1","partial_json":"{\"path\":\".\\/file.txt\"}"}
{"event":"converted_delta","ordinal":2,"timestamp":"...","type":"ToolCallComplete","tool_calls":[{"id":"call_1","name":"read","arguments":{"path":"./file.txt"}}]}
{"event":"capture_end","ordinal":-1,"timestamp":"...","stop_reason":"tool_call"}
```

Each `raw_chunk` record contains the full decoded SSE data array.  Each
`converted_delta` record is correlated by `ordinal` and includes the delta
type and its relevant fields.

### Privacy warning

Artifacts contain **raw model output** (generated text, tool-call arguments,
reasoning content).  Treat them as potentially sensitive.  Delete or redact
before attaching to issues or sharing publicly.

### Using artifacts for tests

A captured JSONL file is a complete record of a single provider turn.  To
create a regression test, extract the `raw_chunk` data payloads in order
and feed them as chunk fixtures to an existing test, or use the fixture
as inspiration for the exact chunk sequence a provider emitted.

## Castor commands

| Command | Purpose |
|---------|---------|
| `castor llm:fixtures:record` | Re-record fixtures from live LLM endpoint |
| `castor run:agent-capture` | Launch agent TUI with raw stream capture enabled |
| `castor test` | Replay-based unit/integration tests (no live LLM) |
| `castor test:llm-real` | Live LLM smoke (opt-in) |

## Synthetic fixtures

Synthetic (hand-authored) fixtures are allowed only for test-layer
smoke/rendering assertions that do **not** require provider fidelity.
They must:

- Include `"fixture_source": "synthetic"`.
- Include `"synthetic_reason"` explaining why synthetic was used.
- NOT contain faked `recorded_at` or `recording_source` fields.

Normal E2E provider-behaviour fixtures (tool calls, stop reasons,
token usage) should be recorded via `castor llm:fixtures:record`.

Example synthetic fixture annotation:

```json
{
    "$schema": "synthetic TUI rendering fixture",
    "fixture_source": "synthetic",
    "synthetic_reason": "TUI rendering assertion — model response text is not assertion-critical"
}
```
