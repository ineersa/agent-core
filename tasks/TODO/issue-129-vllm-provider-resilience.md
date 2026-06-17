# Fix issue #129 vLLM provider resilience, reasoning round-trip, and HTTP retry handling

## Goal
GitHub issue: https://github.com/ineersa/agent-core/issues/129

Problem context from issue/user:
- vLLM provider previously appeared to break during tool-call/streaming flows, with malformed tool-call-ish output and the model dying mid-run.
- User reports vLLM currently appears to work after durable tool-call fixes, but wants this double-checked.
- Need verify reasoning content is received, persisted, and sent back correctly through Symfony AI.
- Need ensure the HTTP client used for LLM/provider calls has sane timeouts, retries/backoff, and respects received headers/status codes/error responses.
- Need useful TUI/runtime feedback when provider timeout/error occurs.

Scouts launched and completed:
1. Agent-core vLLM/Symfony AI reasoning scout.
2. Agent-core HTTP client/retry/error/TUI feedback scout.
3. pi-mono retry/timeout pattern scout in `/home/ineersa/claw/pi-mono` for examples of sane retry limits.

Key scout findings:
- Current generic/vLLM HTTP client is built in `src/CodingAgent/Infrastructure/SymfonyAi/SymfonyAiProviderFactory.php::getHttpClient()` with `HttpClient::create(['timeout' => HATFIELD_LLM_HTTP_TIMEOUT ?? 30])` only. No `max_duration`, no `RetryableHttpClient`, no retry/backoff, no Retry-After handling, and no per-provider timeout/retry settings.
- `src/Platform/Bridge/OpenAICodex/Factory.php` creates a default `HttpClient::create()` with no timeout option; assess whether Codex should share the same sane client policy or whether scope should stay generic/vLLM only.
- Generic Symfony AI `ResultConverter` covers 401/400/429 but constructs `RateLimitExceededException(null, ...)`, so `Retry-After` is ignored; 5xx/non-JSON errors become generic runtime errors.
- `src/Platform/Bridge/Generic/DurableResultConverter.php` has custom streaming durability and throws `IncompleteStreamException` when a stream had chunks but no `finish_reason`. This can make vLLM fragile if an OpenAI-compatible server closes SSE without a final finish_reason. Also, accumulated reasoning is emitted at stream end only after this check, so early/incomplete streams can drop partial reasoning before the exception.
- Reasoning round-trip appears structurally correct for successful streams: `reasoning_content`/`reasoning` deltas become `ThinkingDelta`/`ThinkingComplete`, `LlmPlatformAdapter::buildAssistantMessage()` creates Symfony AI `Thinking`, `AgentMessageNormalizer` stores `details['thinking']`/`details['thinking_signature']`, and `AgentMessageConverter::buildAssistantMessage()` sends `Thinking` back in later requests.
- Compatibility shaping exists through `ReasoningContentFeatureShaper` and model resolver compatibility features for providers requiring reasoning content on assistant messages.
- `ExecuteLlmStepWorker` has no retry loop; `LlmStepResultHandler` reads a `retryable` flag but default is false and nothing currently auto-retries provider calls.
- `LlmStreamDispatchObserver::EVENT_ERROR` exists but scouts found no subscriber that surfaces streaming errors immediately; eventual failure becomes transcript/TUI error via normal failed run projection.
- pi-mono examples to consider: `packages/ai/src/providers/openai-codex-responses.ts` manually retries 429/500/502/503/504 and network failures, parses `retry-after-ms`, `retry-after` seconds, and HTTP-date headers, caps server-requested retry delay (default 60s), distinguishes terminal quota/billing rate limits from transient rate limits, uses abortable sleep, and disables SDK-level retries by default. `packages/coding-agent/src/core/settings-manager.ts` defines agent/provider retry settings; `agent-session.ts` uses app-level retry classification/backoff.

Suggested implementation direction:
- Add a small application-owned HTTP retry policy for LLM provider calls rather than relying blindly on SDK/vendor behavior.
- Use sane default timeout/max_duration and bounded retry count/backoff; respect Retry-After/Retry-After-Ms headers with a cap.
- Classify retryable vs terminal provider errors (429 transient vs quota/billing, 500/502/503/504, timeouts/network resets) without leaking raw prompts/tool output or sensitive response bodies.
- Revisit `DurableResultConverter` finish_reason enforcement for OpenAI-compatible/vLLM streams: ensure accumulated reasoning is flushed before incomplete-stream failure, and decide whether missing finish_reason should be retryable/soft for useful completed content.
- Add deterministic regression tests around reasoning round-trip and HTTP retry/error handling. If implementation touches provider/LLM-visible paths, run focused `castor test:llm-real` when appropriate in addition to deterministic Castor validation.

## Acceptance criteria
- Reasoning content from vLLM/OpenAI-compatible streams is verified with regression coverage: received `reasoning_content`/`reasoning` is persisted on the assistant message and sent back on the next provider request when present.
- `DurableResultConverter` handles missing/late finish_reason and accumulated reasoning safely: no reasoning is silently dropped before an incomplete-stream error; missing finish_reason behavior is explicitly tested and classified as retryable or intentionally tolerated according to the chosen design.
- LLM HTTP client configuration has sane defaults for timeout and total request duration, with bounded retry/backoff for transient provider/network failures.
- Retry behavior respects provider headers/statuses where available, including `retry-after-ms`, `retry-after` seconds, and HTTP-date forms, with a maximum delay cap to avoid indefinite hangs.
- Error handling distinguishes transient retryable errors from terminal auth/bad request/quota/billing errors and surfaces clear, sanitized diagnostics through runtime/TUI failure projection.
- Existing provider behavior remains compatible for OpenAI/Codex/generic providers unless intentionally updated; no raw prompts, tool outputs, API keys, or full response bodies are logged/displayed.
- Add focused unit/integration tests for HTTP retry policy, Retry-After parsing, error classification, and reasoning round-trip. Add controller/TUI replay coverage only if production changes affect runtime/TUI user-visible error flow.
- Before implementation/validation, forks must read `.agents/skills/testing/SKILL.md` and `tests/AGENTS.md`; validate via Castor (`castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check`, and `castor check` before PR; `castor test:llm-real` if live provider compatibility is affected).

## Workflow metadata
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-06-17T16:45:21.632Z

## Task workflow update - 2026-06-17T16:53:35.607Z
- Summary: Scope clarification from user before implementation: the main goal is proper LLM HTTP request handling — sane timeouts, max duration/limits, bounded retries/backoff, Retry-After/header/status/error-body handling, terminal billing/quota detection, sanitized error classification, and visible red TUI/runtime diagnostics so users know what is happening. `DurableResultConverter` missing `finish_reason` behavior is explicitly OUT OF SCOPE for this task; the user will handle finish_reason upstream in Symfony AI later. Do not spend implementation budget changing finish_reason semantics except if tests need to assert current successful reasoning round-trip remains intact.
- Implementation scope override: focus on HTTP/provider request resilience and user-visible sanitized error feedback. Treat the initial task acceptance item about changing missing/late finish_reason behavior as deferred/out-of-scope for this task; keep any reasoning coverage limited to verifying current successful reasoning-content round-trip if practical.
