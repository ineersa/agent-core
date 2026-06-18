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
Fork run: n3bshp0a0nzw
PR URL:
PR Status:
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
