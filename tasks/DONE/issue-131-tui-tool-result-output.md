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
- Once data flows, the existing renderer shows `$block->text` verbatim — show the FULL unbounded output. Do NOT add any line cap, preview, or truncation (user decision 2026-06-17: all truncation/preview handling is deferred to RENDER-04). Just make the real text reach the block.

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
- TUI shows the FULL actual tool output (bash stdout/stderr, read file content, etc.) verbatim instead of only 'bash completed'/'read done'. NO line cap, preview, or truncation — user defers all truncation/preview handling to RENDER-04.
- Fallback '{tool} completed' still shows when a tool genuinely produces no output (empty result), so no regression for no-output tools.
- No regression to the InProcess shell '!' path (which already injects result) or existing TranscriptProjector/ToolProjectionSubscriber tests — update any tests that asserted the 'completed' fallback where real output now flows.
- Mandatory TUI E2E proof: new TuiToolOutputE2eTest (#[Group('tui-e2e-replay')]) + fixture exercising a read tool call, asserting real file content (sentinel) is visible in the TUI and the 'read completed' fallback is absent. Uses TmuxHarness + replay fixture, mirrors TuiJourneyE2eTest.
- Castor validation green: castor test, castor test:tui (mandatory — runtime/TUI-visible change), castor deptrac, castor phpstan, castor cs-check. castor test:llm-real NOT required (no provider/LLM-visible change).

## Workflow metadata
Status: DONE
Branch: task/issue-131-tui-tool-result-output
Worktree: /home/ineersa/projects/agent-core-worktrees/issue-131-tui-tool-result-output
Fork run: 423243cad
PR URL: https://github.com/ineersa/agent-core/pull/160
PR Status: merged
Started: 2026-06-17T20:38:34.453Z
Completed: 2026-06-17T21:51:33.354Z

## Work log
- Created: 2026-06-17T20:38:22.538Z

## Task workflow update - 2026-06-17T20:38:34.453Z
- Moved TODO → IN-PROGRESS.
- Created branch task/issue-131-tui-tool-result-output.
- Created worktree /home/ineersa/projects/agent-core-worktrees/issue-131-tui-tool-result-output.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/issue-131-tui-tool-result-output.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/issue-131-tui-tool-result-output.

## Task workflow update - 2026-06-17T21:33:08.872Z
- Recorded fork run: 423243cad
- Validation: Reviewer subagent APPROVED (testing skill + tests/AGENTS.md + AGENTS.md read). All Castor QA run by reviewer: ToolCallResultHandlerTest 6/29 (pre-followup), RuntimeEventMapperTest 42/139, TranscriptProjectorTest 87/333, TuiToolOutputE2eTest 1/4, deptrac 0, phpstan 0, cs-check 0, full castor test 2671/7876.; Follow-up fork 423243cad validation (tests-only): castor test --filter=ToolCallResultHandlerTest 9/38 OK, phpstan 0, cs-check 0, castor test full 2674/7885 OK.; Orchestrator independent focused validation @ 423243cad: castor deptrac 0 violations (allowed=1137); castor phpstan 0 errors; castor cs-check 0 files fixed; castor test --filter=ToolCallResultHandlerTest 9/38 OK; castor test full suite 2674 tests/7885 assertions OK (12.4s); castor test:tui 8 tests/83 assertions OK (38.4s) incl. TuiToolOutputE2eTest. Stale-worker check: PID 3415 confirmed root-owned (uid 0, Docker messenger, off-limits) — NOT killed; no ineersa-owned workers present.
- Summary: task-to-pr: reviewer APPROVED (clean, no blockers); addressed 2 NTH findings via follow-up fork.

REVIEWER VERDICT: APPROVED. Read testing skill + tests/AGENTS.md + AGENTS.md. Confirmed: extractResultText is defensively written (is_array/is_string guards at every access, returns '' on malformed input, cannot throw); result wired only to ToolExecutionEnd spec (not ToolCallResultReceived); end-to-end flow correct (translator passes string through, projector consumes, renderer shows block text); no backward-compat shim, no empty catches, Castor-only. TUI E2E proof VALID — TuiToolOutputE2eTest is a real TmuxHarness E2E (#[Group('tui-e2e-replay')], isolated dir, replay fixture tui-tool-call-read.json drives a real `read` tool call, asserts actual file content sentinel TOOL_OUTPUT_SENTINEL_131_READ appears in TUI and "read completed" fallback absent). Not a mock/picker-footer substitute.

FOLLOW-UP FORK 423243cad (tests-only, production UNTOUCHED): addressed 2 NTH findings from review — (1) testExtractResultTextMalformedContent comment overstated coverage (claimed "content missing/non-array/null" but only tested null); (2) non-array content / non-array part / missing-content-key guards of extractResultText had no test coverage. Added 3 focused tests (testExtractResultTextNonArrayContentReturnsEmpty, testExtractResultTextNonArrayPartReturnsEmpty, testExtractResultTextMissingContentKeyReturnsEmpty) + fixed the comment. ToolCallResultHandlerTest now 9 tests/38 assertions, all extractResultText branches covered.

Commits on task/issue-131-tui-tool-result-output: f1b87a505 (impl, production + tests + TUI E2E) + 423243cad (follow-up test coverage). 5 files, +866 lines. Worktree clean, NOT pushed.

This is a tool-execution/runtime-visible change, not a provider/LLM-visible change — castor test:llm-real not required.

## Task workflow update - 2026-06-17T21:33:53.488Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Running deterministic castor check in worktree (timeout 480s)...
- castor check passed (34.4s).
- Pushed task/issue-131-tui-tool-result-output to origin.
- branch 'task/issue-131-tui-tool-result-output' set up to track 'origin/task/issue-131-tui-tool-result-output'.
- Created PR: https://github.com/ineersa/agent-core/pull/160

## Task workflow update - 2026-06-17T21:51:33.354Z
- Moved CODE-REVIEW → DONE.
- Merged task/issue-131-tui-tool-result-output into integration checkout.
- Merge made by the 'ort' strategy.
 .../Application/Pipeline/ToolCallResultHandler.php |  47 ++
 .../Pipeline/ToolCallResultHandlerTest.php         | 474 +++++++++++++++++++++
 .../CodingAgent/Runtime/RuntimeEventMapperTest.php |  36 ++
 tests/Tui/E2E/TuiToolOutputE2eTest.php             | 272 ++++++++++++
 tests/Tui/E2E/fixtures/tui-tool-call-read.json     |  37 ++
 5 files changed, 866 insertions(+)
 create mode 100644 tests/Tui/E2E/TuiToolOutputE2eTest.php
 create mode 100644 tests/Tui/E2E/fixtures/tui-tool-call-read.json
- Removed worktree /home/ineersa/projects/agent-core-worktrees/issue-131-tui-tool-result-output.
- Pulled integration checkout: Merge made by the 'ort' strategy..
