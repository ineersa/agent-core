# Issue #131 — Show real tool result output in TUI (not just "bash completed"/"read done")

## Goal
## Issue
https://github.com/ineersa/agent-core/issues/131 — "TUI is missing tool responses". Users can't see if edits landed, what bash/read returned, etc. Tool results are a black box; the TUI only shows terse status like "bash completed" / "read done".

## User scope decision (2026-06-17)
Issue #131 has TWO pieces. Per user:
- **SafeGuard (and other assistant messages) — OUT OF SCOPE for now.** Leave for a separate task.
- **Tool call result output — IN SCOPE.** Show the actual tool output (bash stdout, read content, etc.), not just "bash completed"/"read done".

## Root cause (confirmed by 3 parallel read-only scouts + orchestrator verification)
This is a **data-flow gap at the pipeline→event boundary**, NOT a rendering bug. The actual tool output exists but is never put into the event the TUI consumes.

Verified chain (all file:line confirmed):
1. `src/AgentCore/Application/Pipeline/ToolCallResultHandler.php` (~lines 145-151): the `ToolExecutionEnd` domain event payload is built with ONLY `tool_call_id`, `order_index`, `is_error`. The `result` field is **omitted**, even though `$message->result` (`ToolCallResult->result`, typed `mixed`) holds the output. (The later `MessageEnd` event DOES carry full content in `message`, but that event type is NOT in the translator dispatch table, so it's dropped.)
2. `src/CodingAgent/Runtime/Protocol/RuntimeEventTranslator.php` `onToolExecutionEnded()` (~line 285): **already** passes through `result` when present and string — `if (isset($p['result']) && is_string($p['result'])) $payload['result'] = $p['result'];`. No change needed here.
3. `src/CodingAgent/Runtime/ProjectionPipeline/ToolProjectionSubscriber.php` `onToolExecutionCompleted()` (~line 193): **already** reads `$p['result']` and uses it as the block text; only falls back to `"{tool_name} completed"` when empty. No change needed here.
4. `src/Tui/Transcript/TranscriptBlockRenderer.php`: renders `$block->text`. No change needed for data to show.

**Proven pattern to mirror:** the InProcess shell `!` path (`src/CodingAgent/Runtime/InProcess/InProcessAgentSessionClient.php:352`) already injects `'result' => $resultText` into the event — that's why shell `!` output shows but normal tool execution does not.

## Relationship to RENDER-* task series (IMPORTANT — no conflict/duplication)
#131 is a **PREREQUISITE** to RENDER-04/RENDER-05, not a duplicate:
- **#131 (this task)** = fix the DATA: get real tool output text into the transcript block.
- **RENDER-04** (TODO, `.pi/plans/tui-rich-transcript-blocks-plan.md`) = PRESENTATION: tool cards, fenced YAML args, configurable `tool_result_lines` preview, Ctrl+O toggle.
- **RENDER-05** = diff classification for edit/write results.

Doing RENDER-04 before #131 would render pretty cards that say "bash completed". #131 must land first. Keep #131's presentation changes minimal (see scope) so RENDER-04 can replace/enrich without backward-compat shims.

## Implementation plan (for the implementation fork)
**Core fix (Option A — all scouts strongly recommend):**
- `src/AgentCore/Application/Pipeline/ToolCallResultHandler.php`: add `'result' => <displayable string>` to the `ToolExecutionEnd` event spec payload.
  - Extract a string from `$message->result` (mixed). Reuse existing normalization — the handler already calls `$this->messageNormalizer->toolMessage($orderedResult)` to build the AgentMessage whose `content[0]['text']` is the displayable text. Investigate `AgentMessageNormalizer::toolMessage()` and `ToolExecutor` (`toDomainResult`/`normalizeResultText`) for the cleanest extraction path. Must produce a STRING (the translator only forwards strings); serialize arrays to readable text.
  - Mirror the InProcess shell pattern (`'result' => $resultText`).
- Downstream translator + projector already handle it — verify, don't re-implement.

**Presentation (minimal — don't build RENDER-04):**
- Once data flows, the existing renderer shows `$block->text`. Add a pragmatic line cap (e.g. first N lines + "… (M more lines)") so long bash output doesn't flood the transcript. Place at the display boundary (renderer/projector). Keep errors generous/full. Document that RENDER-04 will make the cap configurable via `tui.transcript.previews.tool_result_lines`.

**Mandatory TUI E2E proof (AGENTS.md hard gate):**
- NEW `tests/Tui/E2E/TuiToolOutputE2eTest.php` (#[Group('tui-e2e-replay')]), mirroring `TuiJourneyE2eTest.php`.
- NEW fixture `tests/Tui/E2E/fixtures/tui-tool-call-output.json` with a `read` tool call (delta types tool_call_start/tool_input_delta/tool_call_complete — already supported by ControllerReplayHttpClientFactory).
- Isolated test dir with a known file (e.g. test.txt containing a sentinel like `TOOL_OUTPUT_SENTINEL_12345`). Assert: sentinel visible in TUI capture, "read completed" fallback absent. Use saveAnsiSnapshot().

## Out of scope (explicit)
- SafeGuard and other assistant-message rendering (user deferred — separate task).
- Full RENDER-* presentation: Markdown rendering, fenced YAML args, edit/write diff classification, Ctrl+O preview toggle, `tui.transcript.*` config DTOs. (RENDER-04/05.)
- Streaming tool output via `tool_execution.output_delta` — note: `ToolExecutionUpdate` domain event → `tool_execution.output_delta` runtime type has NO translator mapping and `ToolProjectionSubscriber::onToolExecutionOutputDelta` is currently dead code. Possible follow-up, not needed for #131 (we surface the final result).

## Scout runs
- 3 parallel read-only subagents (fresh context, concurrency 3) on 2026-06-17. Findings consistent across all three. Full scout output: /home/ineersa/.pi/agent/tmp/2026-06--e4c4414f.txt

## Risk
LOW. Root cause is a missing payload field; all downstream consumers already handle it. Only one production caller path affected (normal Messenger tool pipeline). InProcess shell path already proves the pattern works.

## Acceptance criteria
- Tool result OUTPUT text is included in the ToolExecutionEnd domain event payload in ToolCallResultHandler, extracted as a displayable string from the ToolCallResult (handles both string results like bash/read AND structured/array results via normalization, e.g. reusing AgentMessageNormalizer::toolMessage() content text or ToolExecutor normalization).
- Output propagates end-to-end: RuntimeEventTranslator::onToolExecutionEnded passes the string result through (already implemented), ToolProjectionSubscriber::onToolExecutionCompleted uses it as the TranscriptBlock text (already implemented) — the '{tool} completed' fallback is no longer hit when real output exists.
- TUI shows actual tool output (bash stdout/stderr, read file content, etc.) instead of only 'bash completed'/'read done'.
- Long output is bounded by a sensible line cap with a truncation indicator so the transcript is not flooded; error results remain generous/full by default. Implemented minimally at the display layer (renderer/projector); documented that RENDER-04 will later make this configurable via tui.transcript.previews.tool_result_lines.
- Fallback '{tool} completed' still shows when a tool genuinely produces no output (empty result), so no regression for no-output tools.
- No regression to the InProcess shell '!' path (which already injects result) or existing TranscriptProjector/ToolProjectionSubscriber tests — update any tests that asserted the 'completed' fallback where real output now flows.
- Mandatory TUI E2E proof: new TuiToolOutputE2eTest (#[Group('tui-e2e-replay')]) + fixture exercising a read tool call, asserting real file content (sentinel) is visible in the TUI and the 'read completed' fallback is absent. Uses TmuxHarness + replay fixture, mirrors TuiJourneyE2eTest.
- Castor validation green: castor test, castor test:tui (mandatory — runtime/TUI-visible change), castor deptrac, castor phpstan, castor cs-check. castor test:llm-real NOT required (no provider/LLM-visible change).

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
- Created: 2026-06-17T20:38:22.538Z
