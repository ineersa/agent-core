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
Fork run: 04c309926
PR URL: https://github.com/ineersa/agent-core/pull/158
PR Status: open
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

## Task workflow update - 2026-06-17T17:34:12.661Z
- Recorded fork run: r91hmmwynaz8
- Validation: Fork-reported (Castor-only): castor test --filter=LlmHttpRetryPolicyTest 24/76 OK; --filter=LlmRetryingHttpClientTest 12/27 OK; --filter=LlmProviderErrorClassifierTest 33/89 OK; castor test 2643/7761 OK; castor deptrac 0 violations; castor phpstan 0 errors; castor cs-check 0 fixed; castor test:tui --filter=TuiProviderErrorE2eTest 1/6 OK; castor test:tui 7/79 OK; castor test:llm-real 5/51 OK; castor check full gate all lanes OK (54.3s).; Orchestrator state check: worktree `task/issue-129-vllm-provider-resilience` clean at 57bdf35af (2 commits over origin/main: d711ce375 + 57bdf35af); integration `main` clean at origin/main 64a7eec98.
- Summary: Follow-up fork r91hmmwynaz8 completed at commit `57bdf35af`; orchestrator verified the implementation. All blockers from the first fork (7dtgw8sj2p05) are resolved:

1. TUI E2E proof ADDED — `tests/Tui/E2E/TuiProviderErrorE2eTest.php` (real TmuxHarness, `#[Group('tui-e2e-replay')]`, isolated dir). Replay seam `ControllerReplayHttpClientFactory` extended with `isHttpErrorFixture()`/`buildErrorResponse()` to serve non-SSE HTTP error MockResponses; new fixture `tui-provider-rate-limit-error.json` (429, Retry-After 30, sentinel body). Test asserts red `✕` error block appears, `◇` assistant block absent, sanitized "rate limit"/"retryable" text visible, sentinel `DO_NOT_LEAK_PROVIDER_BODY` absent. Verified sentinel cannot leak: `RuntimeEventTranslator::onLlmStepFailed()` only passes through safe keys (retryable, error_category, http_status_code, retry_after_ms, response_error_code, response_error_type) — `response_error_message` is NOT in the passthrough list.

2. PHPStan baseline — first fork's `missingType.iterableValue` entries removed; new code now has `@param array<string, mixed>` docblocks. This introduced `method.childParameterType` contravariance entries instead. Verified against `vendor/symfony/http-client-contracts/HttpClientInterface.php`: parent declares bare `array $options` with no value-type docblock, so ONE baseline entry per method is genuinely unavoidable at this phpstan level (bare array → missingType; precise array → contravariance). This is the standard Symfony escape hatch, not a defect.

3. Privacy-safe diagnostics — `LlmPlatformAdapter::extractResponseDiagnostics()` no longer sets `response_body_preview`; uses `response_body_bytes` + `response_body_is_json` instead (non-JSON bodies record only metadata). Retry-After parsed into `retry_after_ms` via new `parseRetryAfterHeader()`. Confirmed no `response_body_preview` leak path remains in src (only the classifier's defensive unset + its test).

4. Classifier — uses composite error text across message + structured provider fields; terminal billing/quota detection works via any structured field; 429 user_message includes retry-after hint + provider code; 6 new classifier tests added.

5. Provider ID wired — both `buildGenericCompletionsProvider()` and `buildCodexProvider()` now pass `$provider->id` to `getHttpClient()`.

Worktree clean at `57bdf35af`; integration checkout `main` clean at `origin/main` (64a7eec98). Implementation phase complete. Non-blocking notes: (a) `response_error_message` (structured provider error text) is still persisted in the RunEvent error array → events.jsonl, but never displayed in TUI; (b) unused private `savePlainSnapshot` helper in the TUI test.

Ready for task-to-pr phase (reviewer + focused validation + CODE-REVIEW) on user go-ahead.

## Task workflow update - 2026-06-17T18:29:34.692Z
- Validation: Reviewer subagent: APPROVED. Read testing skill + tests/AGENTS.md + task-workflow skill. Ran castor test --filter=LlmHttpRetryPolicyTest (24/76 OK), --filter=LlmRetryingHttpClientTest (12/27 OK), --filter=LlmProviderErrorClassifierTest (33/89 OK), test:tui --filter=TuiProviderErrorE2eTest (1/6 OK), deptrac (0 viol), phpstan (0 err), cs-check (0 fixed). Confirmed TUI E2E proof valid and privacy path closed.; Orchestrator stale-worker cleanup: killed 17 genuinely-stale workers from llm-raw-stream-capture worktree (supervising console agent controller was respawning messenger consumers). Root-owned system daemon PID 3415 (PPID 3319, root user) confirmed as harmless false-positive in stale-worker pattern — unkillable without root, present during all prior green validation, does not touch isolated test transports.; Orchestrator focused local validation (worktree task/issue-129-vllm-provider-resilience @ 57bdf35af): castor deptrac 0 violations; castor phpstan 0 errors; castor cs-check 0 files fixed; castor test 2643 tests/7761 assertions OK (11.1s); castor test:tui 7 tests/79 assertions OK (24.3s); castor test:llm-real 5 tests/51 assertions OK (21.9s). All lanes green.
- Summary: task-to-pr phase complete. Reviewer subagent returned APPROVED (no CRITICAL/BUG/SEC blockers). Verified all hard gates: real TmuxHarness TUI E2E proof present and passing (TuiProviderErrorE2eTest — red ✕ block, sanitized text visible, sentinel body NOT leaked); privacy path closed (RuntimeEventTranslator passthrough list excludes response_error_message/raw body; extractResponseDiagnostics stores only safe metadata); retry logic correct (no mid-stream retries, Retry-After parsed + capped, terminal billing/quota not retried); provider-id wired on both generic + Codex paths.

Reviewer confirmed the 2 new phpstan-baseline entries (method.childParameterType for LlmRetryingHttpClient::request()/withOptions()) are genuinely unavoidable — Symfony's HttpClientInterface declares bare `array $options` with no @param value type, so the narrowing docblock triggers contravariance; baseline is the correct standard approach.

Reviewer findings were all non-blocking (reviewer verdict was APPROVE, not REQUEST CHANGES):
- [EDGE CASE] total retry loop time can exceed maxDuration (per-request, not per-loop) — mitigated by maxDelay cap + env config; reviewer suggested a documenting comment.
- [EDGE CASE] LlmPlatformAdapter::parseRetryAfterHeader() uses lowercase header access (relies on Symfony getHeaders(false) lowercasing) vs LlmHttpRetryPolicy's case-insensitive lookup — only affects diagnostics, not the actual retry path; boundary-driven (AgentCore can't depend on CodingAgent's policy).
- [CONVENTION] 4 empty catch blocks in extractResponseDiagnostics() — VERIFIED PRE-EXISTING (present in origin/main), not introduced by this PR. The one NEW empty catch (parseRetryAfterHeader date parse, `// Not a valid date`) has a documenting comment.
- [SIMPLIFY/NTH] near-duplicate Retry-After parsers across layer boundary; retry log could include url; consider controller-replay coverage. All optional.

Decision: APPROVED verdict = no implementation fork needed. Proceeding to focused local validation + CODE-REVIEW.

Commits on branch task/issue-129-vllm-provider-resilience (HEAD 57bdf35af):
- d711ce375 fix: add LLM HTTP retry/backoff and provider error classification for issue #129
- 57bdf35af fix: address blocker review items for issue #129 — TUI E2E proof, privacy-safe diagnostics, provider id wiring

## Task workflow update - 2026-06-17T18:30:11.273Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Running deterministic castor check in worktree (timeout 480s)...
- castor check passed (27.4s).
- Pushed task/issue-129-vllm-provider-resilience to origin.
- branch 'task/issue-129-vllm-provider-resilience' set up to track 'origin/task/issue-129-vllm-provider-resilience'.
- Created PR: https://github.com/ineersa/agent-core/pull/158

## Task workflow update - 2026-06-17T18:30:23.055Z
- Updated PR URL: https://github.com/ineersa/agent-core/pull/158
- Updated PR Status: open
- Summary: Moved IN-PROGRESS → CODE-REVIEW. Deterministic castor check passed in worktree (27.4s). Branch pushed, PR #158 created: https://github.com/ineersa/agent-core/pull/158 — title "Fix issue #129 vLLM provider resilience, reasoning round-trip, and HTTP retry handling". Awaiting review/merge.

## Task workflow update - 2026-06-17T19:07:22.627Z
- Moved CODE-REVIEW → IN-PROGRESS.

## Task workflow update - 2026-06-17T19:16:36.566Z
- Recorded fork run: 04c309926
- Validation: Fork validation (Castor-only): castor test --filter=AiHttpConfigTest 13/38 OK; --filter=LlmHttpRetryPolicyTest 24/76 OK; --filter=LlmProviderErrorClassifierTest 33/89 OK; --filter=LlmRetryingHttpClientTest 12/27 OK; --filter=SymfonyAiProviderFactoryTest 5/9 OK; castor deptrac 0 violations; castor phpstan 0 errors; castor cs-check 0 fixed; castor test full suite 2657 tests/7800 assertions OK (was 2643/7761, +14 tests +39 assertions).; Orchestrator independent re-confirm at 04c309926: castor deptrac 0 violations (allowed=1137); castor phpstan 0 errors; castor test --filter=AiHttpConfigTest 13 tests/38 assertions OK. Grep confirms no getenv/HATFIELD_LLM_HTTP in LlmHttpRetryPolicy; AiConfig exposes ->http; factory seeds policy from $this->appConfig->ai?->http.
- Summary: task-review-iterate: addressed PR #158 inline comment (LlmHttpRetryPolicy.php:57) — "Will be way nicer to get those from settings, settings also support env: syntax if need envs for testing."

Fork moved the LLM HTTP retry config source from HATFIELD_LLM_HTTP_* env vars to a new ai.http Hatfield settings block:
- NEW src/CodingAgent/Config/Ai/AiHttpConfig.php — typed readonly DTO (?int timeout/maxDuration/maxRetries/baseDelayMs/maxDelayMs) with fromArray() supporting null / int / numeric-string / env:VARNAME resolution (env: reused for test overrides, matching the existing api_key env: pattern).
- AiConfig now exposes ->http (parsed from ai.http block, default empty).
- LlmHttpRetryPolicy: removed ALL getenv()/HATFIELD_LLM_HTTP_* reading; constructor now takes explicit ?ints falling back to DEFAULT_* constants via pure validatePositive()/validateNonNegative() validators (no backward-compat shim). Verified zero getenv/HATFIELD_LLM_HTTP references remain.
- SymfonyAiProviderFactory::getHttpClient() now seeds LlmHttpRetryPolicy from $this->appConfig->ai?->http (null-safe; absent ai.http → all defaults, identical runtime behavior).
- docs/settings.md: new ### ai.http subsection (5 keys, defaults 30/120/2/1000/60000, units, env: note). .hatfield/settings.yaml: commented example block added (kept in sync per AGENTS.md).
- Tests: NEW AiHttpConfigTest (13 tests/38 assertions — empty/explicit/env:/unset/numeric-string/invalid + AiConfig integration); SymfonyAiProviderFactoryTest +1 test (custom ai.http accepted). LlmHttpRetryPolicyTest unchanged — all 24 existing tests pass (no env-specific assertions existed).

This is a pure config-sourcing refactor — runtime behavior unchanged (defaults identical when ai.http absent). PR #158's TUI E2E proof + llm-real coverage from the prior cycle still apply; retry client behavior untouched.

Commit 04c309926 on task/issue-129-vllm-provider-resilience (now 3 commits over origin/main: d711ce375, 57bdf35af, 04c309926). 19 files, +2413/-27. Worktree clean, NOT pushed (orchestrator handles via CODE-REVIEW move).
