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
Status: IN-PROGRESS
Branch: task/issue-129-vllm-provider-resilience
Worktree: /home/ineersa/projects/agent-core-worktrees/issue-129-vllm-provider-resilience
Fork run: r91hmmwynaz8
PR URL:
PR Status:
Started: 2026-06-17T16:53:39.596Z
Completed:

## Work log
- Created: 2026-06-17T16:45:21.632Z

## Task workflow update - 2026-06-17T16:53:35.607Z
- Summary: Scope clarification from user before implementation: the main goal is proper LLM HTTP request handling — sane timeouts, max duration/limits, bounded retries/backoff, Retry-After/header/status/error-body handling, terminal billing/quota detection, sanitized error classification, and visible red TUI/runtime diagnostics so users know what is happening. `DurableResultConverter` missing `finish_reason` behavior is explicitly OUT OF SCOPE for this task; the user will handle finish_reason upstream in Symfony AI later. Do not spend implementation budget changing finish_reason semantics except if tests need to assert current successful reasoning round-trip remains intact.
- Implementation scope override: focus on HTTP/provider request resilience and user-visible sanitized error feedback. Treat the initial task acceptance item about changing missing/late finish_reason behavior as deferred/out-of-scope for this task; keep any reasoning coverage limited to verifying current successful reasoning-content round-trip if practical.

## Task workflow update - 2026-06-17T16:53:39.596Z
- Moved TODO → IN-PROGRESS.
- Created branch task/issue-129-vllm-provider-resilience.
- Created worktree /home/ineersa/projects/agent-core-worktrees/issue-129-vllm-provider-resilience.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/issue-129-vllm-provider-resilience.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/issue-129-vllm-provider-resilience.

## Task workflow update - 2026-06-17T16:58:15.492Z
- Recorded fork run: 7dtgw8sj2p05
- Summary: Implementation fork launched in worktree `/home/ineersa/projects/agent-core-worktrees/issue-129-vllm-provider-resilience` to implement the scoped issue #129 request-handling work. Fork instructions focus on LLM HTTP timeout/max_duration, bounded retry/backoff, Retry-After/Retry-After-Ms parsing with cap, terminal quota/billing detection, sanitized provider error classification, and red TUI/runtime diagnostics. Fork explicitly instructed that `DurableResultConverter` missing/late finish_reason semantics are out of scope and should not be changed. Fork also instructed to read testing skill + tests/AGENTS.md before tests, use Castor only, add focused tests plus TmuxHarness E2E proof if TUI/projection behavior is changed, commit changes, leave worktree clean, and report validation results.

## Task workflow update - 2026-06-17T17:12:49.410Z
- Recorded fork run: 7dtgw8sj2p05
- Validation: Fork-reported validation: castor test OK (2638 tests, 7745 assertions), castor deptrac OK (0 violations), castor phpstan OK (0 errors after baseline regeneration), castor cs-check OK (0 files fixed).; Orchestrator status check after repair: worktree `task/issue-129-vllm-provider-resilience` clean at d711ce375; integration checkout `main` clean at origin/main 64a7eec98.
- Summary: Implementation fork 7dtgw8sj2p05 reported completion at commit `d711ce375` with LLM HTTP retry/backoff, provider error classification, sanitized runtime/TUI error text, and unit tests. Orchestrator repaired workflow state after discovering the implementation commit had landed on the integration checkout `main` instead of the task worktree branch: task worktree branch `task/issue-129-vllm-provider-resilience` was reset to `d711ce375`, and integration checkout `main` was reset back to `origin/main` (`64a7eec98`). Worktree and integration checkout are clean after repair.

Not accepted for CODE-REVIEW yet. Blocking issues found in handoff/inspection:
- Fork admitted no real TmuxHarness TUI E2E proof was added, despite changing `RuntimeEventTranslator::onLlmStepFailed()` visible TUI error text. AGENTS.md/task-workflow hard gate requires a real replay-backed `TmuxHarness` E2E proof and `castor test:tui` for TUI feature behavior.
- Fork ran raw `phpunit --filter=...` commands before Castor; this violates project QA policy. Future validation must use Castor only.
- Fork added new PHPStan baseline entries for `LlmRetryingHttpClient::request()` / `withOptions()` missing array value types instead of fixing the docblocks/types. These baseline additions should be removed and the code fixed.
- `extractResponseDiagnostics()` still has a raw `response_body_preview` path for non-JSON bodies in log context; task instructions required no raw body previews in logs/persistence/display. Prefer safe fields such as content type, byte count, and truncated flag.
- Runtime translator passes through `retry_after_ms` if present, but response diagnostics do not currently parse Retry-After headers into the error array.
- `SymfonyAiProviderFactory::getHttpClient()` accepts `providerId` but callers do not pass `$provider->id`, leaving retry logs without provider identity.

Next step: launch a follow-up implementation fork to address these blockers before reviewer/code-review phase.

## Task workflow update - 2026-06-17T17:14:02.652Z
- Recorded fork run: r91hmmwynaz8
- Summary: Follow-up implementation fork `r91hmmwynaz8` launched on worktree `/home/ineersa/projects/agent-core-worktrees/issue-129-vllm-provider-resilience` to address blockers from fork `7dtgw8sj2p05`: add mandatory replay-backed TmuxHarness E2E proof for sanitized red provider error block; extend test replay seam for HTTP error fixtures; remove new PHPStan baseline additions and fix new-code docblocks; remove raw response body preview diagnostics and parse Retry-After headers into safe diagnostics; improve classifier use of structured provider fields/retry-after/provider code; wire provider ID to retry logs; rerun Castor-only focused/full validation. Fork explicitly instructed not to push/open PR/move task and to leave worktree clean with a commit.
