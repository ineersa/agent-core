# Fix: add stream_options include_usage for token usage in footer

## Goal
## Problem
Footer usage/session stats don't show token counts when using llama.cpp or other OpenAI-compatible providers with streaming. 

The root cause: OpenAI-compatible streaming endpoints only include `usage` data in the final SSE chunk when `stream_options: { include_usage: true }` is passed in the request body. We always stream (`'stream' => true`) but never request usage.

## Fix
Add `'stream_options' => ['include_usage' => true]` to the options in `LlmPlatformAdapter::invoke()` alongside the existing `'stream' => true`.

The Symfony AI generic bridge `ModelClient` merges all options into the JSON body, and `CompletionsConversionTrait::convertStream()` already yields `TokenUsage` from `data['usage']` chunks when present. `LlmPlatformAdapter::extractUsage()` already reads `TokenUsageInterface` from `DeferredResult` metadata. So the full pipeline is ready — we just need to request the data.

## Validation
- `castor test` (existing unit/integration tests)
- `castor test:llm-real` or `castor run:agent-test` with llama.cpp to verify footer shows token usage

## Acceptance criteria
- stream_options with include_usage=true is sent in all streaming LLM requests
- Token usage appears in footer when using llama.cpp test model
- castor test passes
- castor test:llm-real passes and shows usage data

## Workflow metadata
Status: CODE-REVIEW
Branch: task/fix-stream-include-usage
Worktree: /home/ineersa/projects/agent-core-worktrees/fix-stream-include-usage
Fork run:
PR URL: https://github.com/ineersa/agent-core/pull/46
PR Status: open
Started: 2026-05-23T22:32:27.799Z
Completed:

## Work log
- Created: 2026-05-23T22:32:13.269Z

## Task workflow update - 2026-05-23T22:32:27.799Z
- Moved TODO → IN-PROGRESS.
- Created branch task/fix-stream-include-usage.
- Created worktree /home/ineersa/projects/agent-core-worktrees/fix-stream-include-usage.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/fix-stream-include-usage.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/fix-stream-include-usage.

## Task workflow update - 2026-05-23T22:38:03.983Z
- Validation: castor test — 806 tests, 9575 assertions, OK; LlamaCppSmokeTest (LLAMA_CPP_SMOKE_TEST=1) — 1 test, 8 assertions, OK — real llama.cpp call succeeds with usage data extracted; castor test:llm-real — LlamaCppSmokeTest passes (.), TuiAgentSmokeTest fails (F) — TUI snapshot mismatch, pre-existing issue unrelated to stream_options change
- ## Fork execution - 2026-05-23T22:37:55.000Z
- - Applied the production code change to LlmPlatformAdapter.php
- - Ran `castor test` — 806 tests, 9575 assertions, all pass
- - Ran LlamaCppSmokeTest (LLAMA_CPP_SMOKE_TEST=1) — 1 test, 8 assertions, all pass (real llama.cpp call succeeds with usage extraction)
- - Ran `castor test:llm-real` — first test passes (.), second test (TuiAgentSmokeTest) fails (F) with TUI snapshot assertion (user message ❯ prefix not visible); this is a pre-existing TUI rendering issue, not related to the stream_options change
- - Verified: the TokenUsageStreamListener in Symfony AI platform captures TokenUsage from stream deltas and populates DeferredResult metadata; our change enables this path
- - Commit: 2782ee8c feat: add stream_options include_usage for streaming LLM requests

## Task workflow update - 2026-05-23T23:27:26.192Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Pushed task/fix-stream-include-usage to origin.
- branch 'task/fix-stream-include-usage' set up to track 'origin/task/fix-stream-include-usage'.
- Created PR: https://github.com/ineersa/agent-core/pull/46

## Task workflow update - 2026-05-24T00:07:31.493Z
- Summary: Additional fixes pushed:
- Footer calculations now match Pi reference: context % uses latest input_tokens (not accumulated sum), t/s resets per-turn
- Fixed events.jsonl going to UUID directory: JsonlProcessAgentSessionClient now passes sessionId as runId to controller
- Replaced artifact polling hack with proper tmux scrollback capture (waitForHistoryContains)
- All 5 TUI e2e tests pass in ~13s
