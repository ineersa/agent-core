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
Status: IN-PROGRESS
Branch: task/fix-stream-include-usage
Worktree: /home/ineersa/projects/agent-core-worktrees/fix-stream-include-usage
Fork run:
PR URL:
PR Status:
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
