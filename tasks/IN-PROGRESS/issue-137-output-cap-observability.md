# Issue 137: improve output-cap guidance and transcript observability

## Goal
## Source

GitHub issue: https://github.com/ineersa/agent-core/issues/137

## Problem summary

OutputCap currently feels awkward for models. The model sees capped tool output, then often tries to read the saved capped file wholesale, retries large reads, or falls back to raw shell inspection. The issue also calls out a broader observability problem: hiding assistant/internal messages from the TUI makes output-cap, SafeGuard, approvals, and extension behavior difficult to understand.

## Scout findings

- Model-visible system prompt is built by `src/CodingAgent/SystemPrompt/SystemPromptBuilder.php` from `config/SYSTEM.md` using tool prompt lines/guidelines from `ToolRegistryInterface`.
- Provider-visible tool schemas/descriptions are produced by `src/CodingAgent/Tool/RegistryBackedToolbox.php` from `ToolDefinitionDTO`.
- Read-tool model guidance is in `src/CodingAgent/Tool/ReadFileTool.php`; current wording says offset/limit exist but does not strongly instruct what to do after capped output.
- OutputCap notice is built by `src/CodingAgent/Tool/OutputCap.php`; current hint suggests `head`/`grep`, encouraging shell/file-inspection behavior instead of using the available agent tools safely.
- Central LLM-visible capping is in `src/CodingAgent/Tool/OutputCapLlmTransformHook.php`; it transforms the provider-facing copy before conversion, but the transformed/capped provider message is not canonically persisted as "what the model saw".
- Potential false-cap/double-cap concern: the central hook calls OutputCap with `path=null`, so file-originated doc outputs can be recapped with `defaultCap` instead of `docCap`.
- Canonical events are stored via `src/CodingAgent/Session/SessionRunEventStore.php` as `.hatfield/sessions/<runId>/events.jsonl`.
- `src/CodingAgent/Runtime/Protocol/RuntimeEventTranslator.php` maps only known AgentCore event types; extension events created by `RunEvent::extension()` fall through to `status.updated` and are not projected meaningfully.
- SafeGuard block/auto-deny currently returns a tool result with `denied: true`; it is shown as a generic tool failure/result, not a clear SafeGuard/system transcript block.
- Runtime approval event types and `HitlProjectionSubscriber::onApprovalRequested()` exist, but SafeGuard approval currently flows through generic `waiting_human`/`human_input.requested`, so the TUI sees a question rather than an approval block.

## Discussion / open decisions

1. Scope split: one broad task vs smaller tasks for (a) OutputCap/model guidance and false-cap fixes, (b) canonical internal notice events, (c) SafeGuard approval/block projection.
2. Canonical event shape: add dedicated AgentCore events, use `ext_*` events with a real runtime projection, or add a generic `system.notice`/`internal_notice` event type.
3. Provider-facing audit: whether to persist full transformed provider messages, hashes/previews only, or explicit structured notices (safer) for OutputCap/SafeGuard decisions.
4. TUI rendering: whether OutputCap/SafeGuard/extension messages should render as `System` blocks, specialized `Approval` blocks, tool-result annotations, or a mix.
5. Prompt policy: whether OutputCap notices should mention shell commands at all, or always prefer first-class tools (`read` with offset/limit, task-specific search tools) when available.

## Testing/validation notes

Testing skill and `tests/AGENTS.md` were read before proposing validation. This task touches LLM-visible prompts/tool conversion and TUI/runtime projection, so implementation must use Castor only and include deterministic runtime/TUI proof. If TUI rendering changes, a real `TmuxHarness` E2E proof is required.

## Acceptance criteria
- OutputCap notice and read-tool prompt/schema guidance clearly explain how the model should proceed after capped output, preferring targeted first-class tools and avoiding full rereads/raw shell fallback.
- Any false or double capping found in the central LLM-visible cap path is fixed; file/doc cap semantics are preserved where appropriate.
- OutputCap/SafeGuard/extension internal notices that matter to the user are represented in canonical events.jsonl and survive resume/replay.
- SafeGuard block/auto-deny and approval-request/answer flows render as clear user-visible transcript blocks, not only as buried generic tool results/questions.
- Runtime projection handles the chosen internal/extension notice event shape explicitly instead of falling through to generic status.updated.
- Automated tests cover prompt/notice behavior, capping edge cases, canonical event persistence/replay, and transcript projection.
- If TUI rendering changes, a real replay-backed TmuxHarness E2E test proves the visible behavior; `castor test:tui` must pass before CODE-REVIEW.
- Focused validation uses Castor only: relevant `castor test --filter=...`, `castor test:controller-replay` as needed, `castor test:tui` for TUI proof, `castor deptrac`, `castor phpstan`, `castor cs-check`, and final deterministic `castor check` via task workflow.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/issue-137-output-cap-observability
Worktree: /home/ineersa/projects/agent-core-worktrees/issue-137-output-cap-observability
Fork run: osilw4kokk9s
PR URL: https://github.com/ineersa/agent-core/pull/164
PR Status: open
Started: 2026-06-17T23:00:56.520Z
Completed:

## Work log
- Created: 2026-06-17T22:52:38.824Z

## Task workflow update - 2026-06-17T23:00:56.520Z
- Moved TODO → IN-PROGRESS.
- Created branch task/issue-137-output-cap-observability.
- Created worktree /home/ineersa/projects/agent-core-worktrees/issue-137-output-cap-observability.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/issue-137-output-cap-observability.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/issue-137-output-cap-observability.
- Summary: User chose one broad PR. Round 1 scope: update OutputCap/read-tool model guidance properly and fix central/double cap behavior. SafeGuard-specific implementation is deferred to round 2; after round 1 finishes, inspect changes and launch a second fork for observability.

## Task workflow update - 2026-06-17T23:24:23.219Z
- Validation: Fork reported `castor test --filter=OutputCap`: PASS (46 tests, 121 assertions) after cleanup.; Fork reported `castor phpstan --path=src/CodingAgent/Tool/OutputCap.php`: PASS (0 errors).; Fork reported `castor deptrac`: PASS (0 violations, 0 errors).; Fork reported `castor cs-check`: reported 10 pre-existing unrelated fopen mode issues; worktree left clean and task changes not dirty.
- Summary: Round 1 implementation completed in worktree `/home/ineersa/projects/agent-core-worktrees/issue-137-output-cap-observability`. Commits: `a67f6dc25` initial OutputCap/read guidance and double-cap work, `70de1453c` path-aware central cap fix with tool arguments, `b98655368` cleanup restoring tool-first notice wording and PHPDoc placement. Parent inspected diffs and verified worktree clean. Next step: launch round 2 fork for events.jsonl/TUI observability; SafeGuard-specific behavior remains deferred unless covered by generic observability plumbing.

## Task workflow update - 2026-06-18T01:29:46.889Z
- Validation: Manual smoke: `read(path: "/home/ineersa/projects/agent-core-worktrees/issue-137-output-cap-observability/docs/tui-architecture.md")` did not cap at ~37k chars due doc cap policy.; Manual smoke: `read(path: ".pi/extensions/task-workflow.ts")` did cap, showing `[Output capped to 20000 characters, full output saved to .../.hatfield/tmp/output-cap/...txt]`.
- Summary: User smoke-tested the task worktree and found follow-up issues before CODE-REVIEW: reading `docs/tui-architecture.md` (~37k chars, .md) did not cap because current doc-like `doc_cap` is 50k; code file `.pi/extensions/task-workflow.ts` did cap at 20k; TUI only showed a compact cap line and did not make it obvious what model-facing instructions were sent; user requested nicer styling/icons/theme colors for cap notices. SafeGuard-specific UX remains intentionally deferred; current SafeGuard UI is considered acceptable except the denied assistant message visibility, which will be left for later.

## Task workflow update - 2026-06-18T01:31:18.093Z
- Summary: Scope clarification after user smoke: non-capping of `docs/tui-architecture.md` is acceptable if caused by configurable `tools.output_cap.doc_cap` setting (currently 50k for doc-like `.md/.txt/.toon`). Do NOT treat doc-cap policy itself as a bug. Follow-up should preserve settings semantics; focus instead on real notice parser mismatch (`Saved to:` vs `Saved for audit at:`), making TUI cap notice show that model-facing guidance was sent, and improving output-cap visual styling/icons/theme treatment.

## Task workflow update - 2026-06-18T01:42:27.475Z
- Recorded fork run: j30em8bj6s4n
- Validation: Fork reported `castor test --filter="TranscriptProjectorTest"`: PASS (92/92).; Fork reported `castor test --filter="OutputCap"`: PASS (50/50).; Fork reported `castor test:tui --filter=OutputCap`: PASS for TUI proof (52 tests, 157 assertions, 0 failures, 1 expected live-LLM skip).; Fork reported `castor phpstan --path src/CodingAgent/Runtime/ProjectionPipeline/ToolProjectionSubscriber.php`: PASS (0 errors).; Fork reported `castor deptrac`: PASS (0 violations).
- Summary: Follow-up correction fork `j30em8bj6s4n` accepted. Commit `301e79545` fixes inaccurate TUI output-cap wording: the System notice now says the output exceeded the cap and that the model was shown a cap notice, rather than implying the model saw `20,000 visible chars` of original content. It updated `ToolProjectionSubscriber`, `TranscriptProjectorTest`, and `TuiOutputCapNoticeE2eTest`. Parent verified worktree clean and inspected the changed snippets. `doc_cap` semantics and SafeGuard UX were not changed.

## Task workflow update - 2026-06-18T01:46:47.778Z
- Summary: User clarified a broader invariant: the TUI/events log must show the exact model-facing generated messages, no paraphrase/no summaries — all assistant/tool/runtime/extension-generated messages that are sent to the model from output cap, SafeGuard, tools, or other hooks must be visible exactly as sent. The currently running fork `gzyeocryqsws` only addresses output-cap exactness and should be treated as a partial step, not sufficient for final acceptance of the broader requirement.

## Task workflow update - 2026-06-18T01:55:00.940Z
- Recorded fork run: gzyeocryqsws
- Validation: Fork reported `castor test --filter="TranscriptProjectorTest"`: PASS (92/92).; Fork reported `castor test --filter="OutputCap"`: PASS (50/50).; Fork reported `castor test --filter="TranscriptBlockRendererTest"`: PASS (30/30).; Fork reported `castor test --filter="RuntimeEventMapperTest"`: PASS (47/47).; Fork reported `castor test:tui --filter=OutputCap`: PASS (52 tests, 161 assertions, 0 failures, 1 expected live-LLM skip).; Fork reported scoped `castor phpstan`: PASS (0 errors).; Fork reported `castor deptrac`: PASS (0 violations).
- Summary: Fork `gzyeocryqsws` committed `f95db6f59`, removing the synthesized output-cap System notice and styling the existing output-cap ToolResult block (`notice_type=output_cap`) with warning icon/color. Parent notes this is still not sufficient for the user's clarified broader invariant: all model-facing generated messages/notices/nudges from tools, output cap, SafeGuard, image gating, or extensions must be visible in the TUI exactly as sent to the model. A follow-up implementation is needed to capture/project exact post-transform/pre-provider model input messages generically, not just output-cap-specific ToolResult text.

## Task workflow update - 2026-06-18T02:11:02.343Z
- Recorded fork run: cwo32syp3ok0
- Validation: Fork reported reading `.agents/skills/testing/SKILL.md` and `tests/AGENTS.md`.; Fork reported `castor test --filter=...`: PASS (221 tests, 746 assertions).; Fork reported `castor test:tui`: PASS (9 tests, 94 assertions).; Fork reported `castor phpstan`: PASS (0 errors).; Fork reported `castor deptrac`: PASS (0 violations).; Fork reported `castor cs-check`: clean for changed files; parent notes all QA must use Castor in final validation.
- Summary: Fork `cwo32syp3ok0` committed and pushed `219739d3d`, adding a generic capture path for final provider-facing `ToolCallMessage` content after transform/convert hooks. Verified commit exists on `task/issue-137-output-cap-observability`; worktree is clean; `git show --stat HEAD` reports 10 files changed (438 insertions, 4 deletions). Parent review accepted the core direction but found remaining gaps before CODE-REVIEW: the new DTO is named `ModelToolInput` without an explicit semantic suffix and only covers tool-role messages; generated synthetic user notices from tool conversion/image handling are not captured; model input payloads are dropped on LLM failed/aborted result branches. A follow-up fork is needed before PR readiness to satisfy the broader invariant that generated model-facing notices/messages are shown exactly, not just successful tool-role inputs.

## Task workflow update - 2026-06-18T02:25:04.001Z
- Recorded fork run: cwo32syp3ok0
- Validation: castor test --filter="RuntimeEventMapperTest|TranscriptProjectorTest|PlatformIntegrationTest|OutputCapTest|OutputCapLlmTransformHookTest": 203/203 OK (777 assertions).; castor test (full): 108/109 OK (1 pre-existing CommandMailboxPolicyTest failure).; castor test:tui --filter=OutputCap: 52 tests, 161 assertions, 0 failures (1 expected live-LLM skip).; castor deptrac: 0 violations.; castor phpstan: 0 errors.; castor cs-check: Only 10 pre-existing noise files remain (binary-mode fopen).
- Summary: Fork completed: replaced ModelToolInput with broader ModelInputMessageDTO; added capture of generated user-role messages (image placeholders); model_input_messages now persisted on failed/aborted paths; ToolProjectionSubscriber handles both tool-role (ToolResult update) and user-role (System block) inputs; adapter-level test proves transform hook changes reflected in captured text; projection tests for user-role System blocks, SafeGuard-like exact JSON denial, and failed/aborted path coverage; all validation passes.

## Task workflow update - 2026-06-18T02:39:35.418Z
- Recorded fork run: n3bshp0a0nzw
- Validation: Fork reported reading `.agents/skills/testing/SKILL.md` and `tests/AGENTS.md`.; Fork reported focused `castor test --filter="RuntimeEventMapperTest|TranscriptProjectorTest|PlatformIntegrationTest|OutputCapTest|OutputCapLlmTransformHookTest"`: PASS (203 tests, 777 assertions).; Fork reported full `castor test`: 108/109 OK with known pre-existing `CommandMailboxPolicyTest` failure.; Fork reported `castor test:tui --filter=OutputCap`: PASS (52 tests, 161 assertions, 1 expected live-LLM skip).; Fork reported `castor deptrac`: PASS (0 violations).; Fork reported `castor phpstan`: PASS (0 errors).; Fork reported `castor cs-check`: only 10 pre-existing binary-mode fopen noise files unchanged.
- Summary: Fork `n3bshp0a0nzw` committed and pushed `01158c86c`, replacing `ModelToolInput` with `ModelInputMessageDTO`, capturing provider-facing tool-role and marked generated user-role model inputs, threading them through completed/failed/aborted LLM outcomes, and projecting exact text into ToolResult/System blocks. Parent verification found remaining cleanup/blockers before PR readiness: production `RuntimeEventTranslator` still has a backward-compat fallback for old `model_tool_inputs` despite active-development no-compat rule; tests/comments still contain stale `ModelToolInput`/`model_tool_inputs` names; the TmuxHarness E2E still only proves native tool-level cap via `tool_execution_end.result`, not the central `model_input_messages` projection path or generated user-role System block. A final cleanup/E2E-proof fork is needed before reviewer/CODE-REVIEW.

## Task workflow update - 2026-06-18T02:45:00.016Z
- Recorded fork run: final-cleanup-e2e-proof-acb41a38f
- Validation: castor test --filter="RuntimeEventMapperTest|TranscriptProjectorTest|PlatformIntegrationTest|OutputCapTest|OutputCapLlmTransformHookTest": PASS 203 tests, 777 assertions; castor test:tui --filter=OutputCap: PASS 52 tests, 163 assertions (1 pre-existing skip); castor deptrac: 0 violations; castor phpstan: 0 errors; castor cs-check: only 10 pre-existing noise files unchanged; castor cs-fix: 1 file auto-fixed (cs-fx stylistic changes only)
- Summary: Final cleanup/E2E-proof fork completed at commit `acb41a38f`. 3 files changed, 80 insertions, 41 deletions. (1) Removed backward-compat fallback for `model_tool_inputs` key in RuntimeEventTranslator — production now reads only `model_input_messages`. (2) Renamed 4 stale test method names and updated comments in TranscriptProjectorTest from `ModelToolInput`/`model_tool_inputs` to `ModelInputMessages`/`model_input_messages`. (3) Strengthened `TuiOutputCapNoticeE2eTest`: `tool_execution_end.result` changed from cap notice to raw uncapped text; `llm_step_completed` now carries `model_input_messages` with role=tool (exact cap notice) and role=user (generated image message) to prove the central-cap projection path. Assertions verify raw text NOT visible, generated user System block IS visible, and stale paraphrases still absent. All validation passes: 203 unit tests/777 assertions, 52 TUI E2E tests/163 assertions, 0 phpstan errors, 0 deptrac violations. No stale `ModelToolInput`/`model_tool_inputs` references remain in production or test code.

## Task workflow update - 2026-06-18T02:46:13.115Z
- Recorded fork run: nahs6w6vbp12
- Validation: Fork reported reading `.agents/skills/testing/SKILL.md` and `tests/AGENTS.md`.; Fork reported `castor test --filter="RuntimeEventMapperTest|TranscriptProjectorTest|PlatformIntegrationTest|OutputCapTest|OutputCapLlmTransformHookTest"`: PASS (203 tests, 777 assertions).; Fork reported `castor test:tui --filter=OutputCap`: PASS (52 tests, 163 assertions, 1 pre-existing live-LLM skip).; Fork reported `castor deptrac`: PASS (0 violations).; Fork reported `castor phpstan`: PASS (0 errors).; Fork reported `castor cs-check`: only 10 pre-existing binary-mode fopen noise files unchanged.; Parent verification: `git status --short` clean; `rg -n 'model_tool_inputs|ModelToolInput|model_tool_input' --glob '!vendor/**' --glob '!tasks/**' .` returned no hits.
- Summary: Fork `nahs6w6vbp12` committed and pushed `acb41a38f`: removed the active-development `model_tool_inputs` compatibility fallback from production, renamed stale `ModelToolInput`/`model_tool_inputs` test names/comments, and strengthened `TuiOutputCapNoticeE2eTest` so the TmuxHarness resume fixture proves `model_input_messages` projection for both role=tool exact cap notice replacement and role=user generated System block text. Parent verification: worktree clean at `acb41a38f`; grep for `model_tool_inputs|ModelToolInput|model_tool_input` outside vendor/tasks returns no hits; diff stat vs origin/main shows 22 files changed. Proceeding to task-to-pr reviewer/validation phase.

## Task workflow update - 2026-06-18T02:54:38.187Z
- Validation: Reviewer subagent: APPROVE WITH SUGGESTIONS (no REQUEST CHANGES blockers).
- Summary: Reviewer subagent completed task-to-pr review with verdict APPROVE WITH SUGGESTIONS. No blocking correctness/security/architecture issues were reported. Non-blocking notes included: consider renaming preserved `raw_result` metadata to avoid future confusion, deterministic empty-text fallback in `SystemNoticeProjectionSubscriber::buildBlockId()`, optional diagnostics for missing ToolResult blocks, and possible future constants/enums for `ModelInputMessageDTO::source` values. Proceeding with local Castor validation before CODE-REVIEW move.

## Task workflow update - 2026-06-18T02:56:42.125Z
- Validation: `castor check`: FAIL overall.; `castor check` lanes: deptrac OK (0.9s); test FAIL (1 known failure in `CommandMailboxPolicyTest::testStopBoundaryReturnsShouldContinueTrueWhenFollowUpApplied`); test:controller-replay OK (1 test, 14 assertions); test:tui OK (9 tests, 96 assertions); phpstan OK (0 errors); cs-check FAIL on 10 pre-existing files: `.castor/helpers.php`, `AgentCommand.php`, `FileMentionIndexBuilder.php`, `SymfonyAiProviderFactory.php`, `LogReader.php`, `RuntimeEventEmitter.php`, `StdoutRuntimeEventSink.php`, `JsonlIdempotencyStore.php`, `EditFileTool.php`, `ViewImageTool.php`.; Worktree status after validation: clean.
- Summary: Local task-to-pr validation ran `castor check` in the worktree. Deterministic TUI/controller replay/deptrac/phpstan lanes passed, but the full gate is not green because of known unrelated baseline failures: `CommandMailboxPolicyTest::testStopBoundaryReturnsShouldContinueTrueWhenFollowUpApplied` and cs-check on 10 pre-existing binary-mode fopen files. Worktree remains clean. CODE-REVIEW move is blocked until either those gate failures are fixed/accepted or user chooses how to handle unrelated gate cleanup.

## Task workflow update - 2026-06-18T03:21:14.487Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Running deterministic castor check in worktree (timeout 1200s)...
- castor check passed (39.4s).
- Pushed task/issue-137-output-cap-observability to origin.
- branch 'task/issue-137-output-cap-observability' set up to track 'origin/task/issue-137-output-cap-observability'.
- Created PR: https://github.com/ineersa/agent-core/pull/164
- Validation: Fork 242i86ergvo7: read testing skill and tests/AGENTS.md; merged origin/main into task branch; resolved 4 conflicts with branch evolved versions; fixed PlatformIntegrationTest for AgentMessageToolCallSequenceValidator and RuntimeEventTypeTest for SystemNotice enum.; Fork 242i86ergvo7: castor test targeted filter — 210/210 pass.; Fork 242i86ergvo7: castor test:tui --filter=OutputCap — pass (52 tests, 1 skipped llm-real).; Fork 242i86ergvo7: castor deptrac — 0 violations; castor phpstan — 0 errors; castor cs-check — clean.; Fork 242i86ergvo7: full castor check — pass (2736 tests, 8246 assertions, deptrac, controller-replay, TUI, phpstan, cs-check).
- Summary: Ready for PR after synchronizing task branch with latest origin/main. Branch now includes merge commit f06014e4b, is 0 behind / 13 ahead, and preserves exact model-facing tool/generated-message visibility plus output-cap guidance/TUI behavior. SafeGuard-specific styling remains deferred by decision.

## Task workflow update - 2026-06-18T14:06:47.946Z
- Validation: Reviewer inspected core output cap, model-input capture/projection, runtime translator/projector, renderer, and relevant unit/E2E tests.; Reviewer verified stale backward-compat names ModelToolInput/model_tool_inputs absent from production/test PHP files.; Reviewer noted castor check passed during move_task CODE-REVIEW and accepted validation evidence.
- Summary: Reviewer subagent completed review of PR #164 / branch task/issue-137-output-cap-observability at f06014e4b. Decision: APPROVE WITH SUGGESTIONS. No critical/blocking issues found. Non-blocking suggestions: clarify OutputCapLlmTransformHook raw_result vs combinedText skip semantics; remove redundant output_cap_notice key/fallback in favor of output_capped; verify/remove unused ModelInputMessageDTO::fromArray if dead; optionally add a test documenting raw_result-under-cap but combinedText-over-cap behavior. Reviewer confirmed TmuxHarness E2E proof exists and validation evidence is credible.

## Task workflow update - 2026-06-18T14:07:53.698Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: Review iteration requested by user after reviewer APPROVE WITH SUGGESTIONS. Scope: address sensible non-blocking cleanup suggestions without changing task behavior: remove redundant output_cap_notice key/fallback, clarify/document OutputCap raw_result vs model-text cap semantics with test if appropriate, and remove unused ModelInputMessageDTO::fromArray if confirmed dead. Keep exact model-facing TUI invariant unchanged.

## Task workflow update - 2026-06-18T14:09:36.913Z
- Recorded fork run: ogyf6t15ad4l
- Summary: Launched review-iteration fork ogyf6t15ad4l to address sensible reviewer suggestions: remove redundant output_cap_notice key/fallback, fix/clarify OutputCapLlmTransformHook cap decision against actual model-facing combinedText with regression tests, and remove ModelInputMessageDTO::fromArray if confirmed unused. Fork instructed to run focused Castor validation and push the task branch.

## Task workflow update - 2026-06-18T14:14:54.579Z
- Recorded fork run: ogyf6t15ad4l
- Validation: ogyf6t15ad4l: rg output_cap_notice/model_tool_inputs/ModelToolInput across src/tests — clean.; ogyf6t15ad4l: castor test focused filters — 173/173 and 150/150 pass.; ogyf6t15ad4l: castor test:tui --filter=OutputCap — pass (53 tests, 1 skipped llm-real).; ogyf6t15ad4l: castor deptrac — 0 violations; castor phpstan — 0 errors; castor cs-check — clean.
- Summary: Review-iteration fork ogyf6t15ad4l completed and pushed commit efe66a8ce. Changes: removed redundant output_cap_notice writer/fallback and assertions, fixed OutputCapLlmTransformHook skip semantics to compare actual model-facing combinedText against path-specific cap, removed unused ModelInputMessageDTO::fromArray(), and added regression test for raw output under cap but JSON/model-facing text over cap.
- Launched tiny follow-up fork osilw4kokk9s after parent verification noticed stale OutputCapLlmTransformHook comments still described raw_result/raw tool output length even though behavior now compares combined model-facing text. Scope: comment/PHPDoc cleanup only plus cs/phpstan/focused test.

## Task workflow update - 2026-06-18T14:17:01.689Z
- Recorded fork run: osilw4kokk9s
- Validation: osilw4kokk9s: castor cs-check — clean.; osilw4kokk9s: castor phpstan — 0 errors.; osilw4kokk9s: castor test --filter=OutputCapLlmTransformHookTest — 14/14 pass, 62 assertions.; Parent verification: worktree clean at f7d8cf239; rg output_cap_notice/model_tool_inputs/ModelToolInput across src/tests — no hits.
- Summary: Tiny follow-up fork osilw4kokk9s completed and pushed commit f7d8cf239. Change is comment/PHPDoc only in OutputCapLlmTransformHook: stale references to checking raw tool output were updated to say path cap is resolved via OutputCap::capForPath() and actual model-facing combinedText (including JSON wrapping) is compared against that cap. No behavior changes.
