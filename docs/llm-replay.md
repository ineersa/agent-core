# LLM Replay Fixture Format

Deterministic replay fixtures for LLM interactions. Replaces live llama.cpp/OpenAI-compatible
provider calls in QA/test environments with pre-recorded stream deltas.

## Purpose

Normal QA (`castor test`, `castor check`) should not depend on a live LLM endpoint.
Instead, tests replay recorded fixtures that capture the exact stream of deltas a provider
returned during a reference run.

Live LLM is opt-in: re-record fixtures explicitly with `castor llm:fixtures:record` when
provider behavior, prompts, or tool schemas change.

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

## Castor commands

| Command | Purpose |
|---------|---------|
| `castor llm:fixtures:record` | Re-record fixtures from live LLM endpoint |
| `castor test` | Replay-based unit/integration tests (no live LLM) |
| `castor test:llm-real` | Live LLM smoke (opt-in) |
