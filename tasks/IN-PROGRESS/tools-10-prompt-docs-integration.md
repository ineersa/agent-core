# TOOLS-10 Add prompt and docs integration for final toolbox

## Goal
Update prompts/docs to teach the model how to use the final toolbox.

Plan source: `.pi/plans/toolbox-design-plan.md`.

Dependencies:
- Depends on TOOLS-R00, TOOLS-R02, TOOLS-R03, TOOLS-R04, and SYSTEM-01 for registry-aware prompt assembly, registry-backed Toolbox metadata, settings-backed tool defaults, and final built-in tool definition conventions.
- Depends on SYSTEM-02 for AGENTS.md project context discovery/injection behavior.
- Depends on SYSTEM-03 for skills registry/discovery/preload context behavior.
- Depends on final tool names/schemas from TOOLS-03 (`write`), TOOLS-04 (`view_image`), TOOLS-06 (`edit`), TOOLS-07 (`read`), TOOLS-08 (`bg_status`), and TOOLS-09 (`bash`).

Scope:
- Use the system prompt assembly code from SYSTEM-01 in `src/CodingAgent/`.
- Ensure docs/prompt wording matches SYSTEM-02: AGENTS.md is loaded as an initial user-context message for new sessions, not spliced into SYSTEM.md/system prompt.
- Ensure docs/prompt wording matches SYSTEM-03: skills are exposed through `<skills_instructions>`/`<available_skills>` in the initial user-context message, and `--skills` preloads skill bodies there rather than in the system prompt.
- Add final concise guidance:
  - Use `read` to examine files. Output uses `cat -n` line numbers.
  - Use those line numbers for unified diff `@@` headers in `edit`.
  - Use `edit` for targeted changes to existing files.
  - Use `write` for new files or full rewrites.
  - Use `view_image` for images; `read` is text-only.
  - Use `bash` for commands; long-running commands may be moved to background by user prompt; use `bg_status` for list/log/stop.
- Verify docs/settings already documents the tool settings introduced by TOOLS-R04 and concrete tool tasks; update if final prompt/docs wording reveals gaps.
- Add/adjust tests for prompt assembly if such tests exist.

Out of scope:
- No tool implementation changes unless needed to align names/descriptions.
- No new settings unless already introduced by TOOLS-R04 or concrete tool tasks.

## Acceptance criteria
- Prompt/instructions mention the final tool names and intended usage accurately.
- Edit guidance explicitly says to provide standard unified diffs and use `read` line numbers for `@@` headers.
- Prompt does not claim model-controlled backgrounding; it explains `bg_status` for already-backgrounded processes.
- Existing prompt assembly/context tests pass or new focused tests cover the inserted guidance, AGENTS.md context-channel wording, and skills context-channel wording.
- Focused tests pass with Castor/PHPUnit.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/tools-10-prompt-docs-integration
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-10-prompt-docs-integration
Fork run: ptb2g46gdcoo
PR URL:
PR Status:
Started: 2026-06-16T22:05:47.991Z
Completed:

## Work log
- Created: 2026-05-17T04:42:49.755Z

## Task workflow update - 2026-06-16T22:05:47.991Z
- Moved TODO → IN-PROGRESS.
- Created branch task/tools-10-prompt-docs-integration.
- Created worktree /home/ineersa/projects/agent-core-worktrees/tools-10-prompt-docs-integration.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/tools-10-prompt-docs-integration.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/tools-10-prompt-docs-integration.
- Summary: Started task-start workflow. Orchestrator will gather prompt/docs/test context and launch an implementation fork in the created worktree.

## Task workflow update - 2026-06-16T22:08:52.573Z
- Validation: Read `.agents/skills/testing/SKILL.md` and `tests/AGENTS.md` before proposing tests.; Scout recommended focused Castor tests: `castor test --filter SystemPromptBuilderTest`, tool definition tests for edit/read/write/bash/bg_status/view_image, context tests for AgentsContextRenderer/Discovery and SkillsContextBuilder, plus `castor deptrac`, `castor phpstan`, `castor cs-check`; opt-in `castor test:llm-real` because system prompt/tool guidance is LLM-visible.
- Summary: Read task and plan source `.pi/plans/toolbox-design-plan.md`; claimed worktree `/home/ineersa/projects/agent-core-worktrees/tools-10-prompt-docs-integration`. Loaded task-workflow, subagents, and testing skills plus `tests/AGENTS.md` for validation conventions. Scout found prompt assembly in `config/SYSTEM.md` + `src/CodingAgent/SystemPrompt/SystemPromptBuilder.php`; initial context injection in `src/CodingAgent/Runtime/InProcess/InProcessAgentSessionClient.php`; final tool prompt metadata in `src/CodingAgent/Tool/{ReadFileTool,EditFileTool,WriteFileTool,ViewImageTool,BashTool,BgStatusTool}.php`; settings docs in `docs/settings.md`, `config/hatfield.defaults.yaml`, `.hatfield/settings.yaml`; likely tests in `tests/CodingAgent/SystemPrompt/SystemPromptBuilderTest.php` and relevant tool/context tests. Key gap: `edit` guidance lacks explicit use of `read` line numbers for unified diff `@@` headers. Task is LLM-visible prompt/tool guidance, not a TUI feature; opt-in `castor test:llm-real` should be mentioned for focused provider validation.

## Task workflow update - 2026-06-16T22:09:39.237Z
- Recorded fork run: t3ff1nsgaa75
- Summary: Launched implementation fork `t3ff1nsgaa75` in worktree `/home/ineersa/projects/agent-core-worktrees/tools-10-prompt-docs-integration`. Fork instructions require prompt/tool metadata/docs/test updates, focused Castor validation, optional `castor test:llm-real` for LLM-visible prompt changes, and a final commit on `task/tools-10-prompt-docs-integration`. Main agent will wait for fork handoff before verifying commit/diff and recording results.

## Task workflow update - 2026-06-16T22:16:16.378Z
- Summary: Fork `t3ff1nsgaa75` returned an incomplete/argumentative handoff instead of a completion report. Verification found no implementation commit and dirty worktree changes only: `.hatfield/settings.yaml`, `config/SYSTEM.md`, `src/CodingAgent/Tool/BashTool.php`, `src/CodingAgent/Tool/EditFileTool.php`. The hardcoded `<tool_usage>` block in `config/SYSTEM.md` duplicates tool-owned prompt metadata; user clarified that tool usage information should come from tool definitions. Rejecting this fork output and relaunching with narrower instructions: keep SYSTEM.md generic/context-channel-only, put final toolbox usage in registry-backed tool prompt metadata/guidelines and tests, then commit.

## Task workflow update - 2026-06-16T22:16:49.289Z
- Recorded fork run: 9bh2eow8awjq
- Summary: Launched corrective implementation fork `9bh2eow8awjq` in worktree `/home/ineersa/projects/agent-core-worktrees/tools-10-prompt-docs-integration`. Updated instructions require removing any hardcoded final-tool `<tool_usage>` block from SYSTEM.md, keeping SYSTEM.md generic/context-channel-only, moving final toolbox guidance into registry-backed tool definitions/guidelines, adding focused tests, running Castor validation, and committing the result.

## Task workflow update - 2026-06-16T22:21:44.056Z
- Recorded fork run: 9bh2eow8awjq
- Validation: Fork reports it read `.agents/skills/testing/SKILL.md` and `tests/AGENTS.md` before test work.; `castor test --filter SystemPromptBuilderTest` — OK (20 tests, 71 assertions).; `castor test --filter EditFileToolTest` — OK (22 tests, 53 assertions).; `castor test --filter BashToolTest` — OK (21 tests, 73 assertions).; `castor test --filter "ReadFileToolTest|WriteFileToolTest|BgStatusToolTest|ViewImageToolTest"` — OK (118 tests, 303 assertions).; `castor cs-check` — clean (0 files fixed).; `castor phpstan` — 0 new errors; 2 pre-existing unrelated file_errors in `src/Platform/Bridge/Generic/DurableResultConverter.php`.; `castor deptrac` — OK (0 violations).; `castor test:llm-real` — OK (5 tests, 51 assertions).; Main verification: `git status --porcelain` clean; `git show --stat HEAD` shows expected 7 changed files; `rg '<tool_usage>|</tool_usage>' config/SYSTEM.md` absent.
- Summary: Implementation fork `9bh2eow8awjq` completed successfully. Verified worktree `/home/ineersa/projects/agent-core-worktrees/tools-10-prompt-docs-integration` is clean and committed at `a649a788e1294c412d14782b2c2eac1359b3f54b` (`feat(TOOLS-10): prompt/docs integration for final toolbox`). Diff stat matches expected prompt/tool metadata/test/docs scope: `.hatfield/settings.yaml`, `config/SYSTEM.md`, `src/CodingAgent/Tool/BashTool.php`, `src/CodingAgent/Tool/EditFileTool.php`, `tests/CodingAgent/SystemPrompt/SystemPromptBuilderTest.php`, `tests/CodingAgent/Tool/BashToolTest.php`, `tests/CodingAgent/Tool/EditFileToolTest.php` (7 files, 130 insertions, 6 deletions). Confirmed `<tool_usage>` block is absent from `config/SYSTEM.md`; final toolbox guidance is in registry-backed tool metadata/guidelines. This is not a TUI task, so no TmuxHarness E2E proof is required.

## Task workflow update - 2026-06-16T22:30:12.749Z
- Summary: Reviewer subagent returned `APPROVE WITH SUGGESTIONS` for current HEAD `a649a788e1294c412d14782b2c2eac1359b3f54b`. It read `.agents/skills/testing/SKILL.md` and `tests/AGENTS.md`, confirmed this is not a TUI task and no TmuxHarness proof is required, and found no critical/bug/security issues. Actionable suggestions to address before CODE-REVIEW: strengthen `SystemPromptBuilderTest` assertions so `<skill` is not satisfied by `<skills_instructions>` and `never` is tied to `never spliced into the system prompt`; strengthen `BashToolTest` to assert the model-vs-user backgrounding ownership phrase; optionally clarify `.hatfield/settings.yaml` background process header and EditFileTool guideline with `cat -n` wording. Launching review-iteration fork to apply these non-blocking but sensible improvements.

## Task workflow update - 2026-06-16T22:30:41.008Z
- Recorded fork run: gf5sx5r79xx6
- Summary: Launched review-iteration fork `gf5sx5r79xx6` after reviewer returned APPROVE WITH SUGGESTIONS. Fork instructions: tighten brittle `SystemPromptBuilderTest` context-channel assertions (`<skill name`/`location=` and `never spliced into the system prompt`), strengthen `BashToolTest` model-vs-user backgrounding assertion, optionally clarify EditFileTool `cat -n` wording/test and `.hatfield/settings.yaml` background process header, run focused Castor tests + cs-check, and commit.

## Task workflow update - 2026-06-16T22:32:32.956Z
- Recorded fork run: gf5sx5r79xx6
- Validation: Fork reports it read `.agents/skills/testing/SKILL.md` and `tests/AGENTS.md` before test work.; `castor test --filter SystemPromptBuilderTest` — OK (20 tests, 71 assertions).; `castor test --filter BashToolTest` — OK (21 tests, 74 assertions).; `castor test --filter EditFileToolTest` — OK (22 tests, 54 assertions).; `castor cs-check` — OK (0 files fixed, clean).; Main verification: `git status --short --branch` clean; HEAD `d0081fcfe08934fb30cca10a3643cbe445883540`; `rg '<tool_usage>|</tool_usage>' config/SYSTEM.md` absent.
- Summary: Review-iteration fork `gf5sx5r79xx6` completed and was verified. Current HEAD is `d0081fcfe08934fb30cca10a3643cbe445883540` (`fix(TOOLS-10): tighten review-iteration assertions and polish edit/bash metadata`) on branch `task/tools-10-prompt-docs-integration`; worktree is clean. Diff remains within expected 7-file scope (134 insertions, 6 deletions) and `config/SYSTEM.md` still has no `<tool_usage>` block. Fork applied reviewer suggestions: tightened `SystemPromptBuilderTest` assertions, strengthened `BashToolTest`, connected EditFileTool guidance to `read`/`cat -n`, added `cat -n` assertion, and clarified `.hatfield/settings.yaml` background process header.

## Task workflow update - 2026-06-16T22:53:00.652Z
- Summary: During task-to-pr focused validation, `castor test:llm-real` produced a passing JUnit report in ~20.4s but left the Castor task and PHAR messenger consumers alive for ~10 minutes after success. User requested hardening: enforce a 30s hard wall-clock kill for `test:llm-real` and all descendant processes. Treating this as a validation-infrastructure review-iteration fix on the task branch before CODE-REVIEW; implementation must use fork per orchestrator rules.

## Task workflow update - 2026-06-16T22:53:28.939Z
- Recorded fork run: ptb2g46gdcoo
- Summary: Launched review-iteration fork `ptb2g46gdcoo` to harden `castor test:llm-real` per user request. Fork instructions: replace plain `passthru()` execution in `.castor/e2e.php::test_llm_real()` with session-aware `run_commands_parallel()` or equivalent, enforce a 30s hard timeout, kill all PHPUnit/PHAR controller/messenger descendant processes on timeout and normal completion, add/adjust hard-stop smoke proof, run `castor test:timeout-hardstop`, `castor test:llm-real`, and `castor cs-check`, then commit.

## Task workflow update - 2026-06-16T22:59:06.736Z
- Recorded fork run: ptb2g46gdcoo
- Summary: Review-iteration fork `ptb2g46gdcoo` completed and committed `0eb266fcc301cc8111a5a6e2720795efab4bdb09` (`Harden test:llm-real with session-aware process runner and 30s hard timeout`). Changes: `.castor/e2e.php` replaces raw `passthru()` in `test_llm_real()` with session-aware `run_commands_parallel()` using a 30s timeout and report log; `.castor/process.php` adds Test E to `test:timeout-hardstop` simulating a successful PHPUnit run leaving a PHAR-like `messenger:consume` worker alive, verifying fast cleanup/no orphans. Fork reported reading `.agents/skills/testing/SKILL.md` and `tests/AGENTS.md` before test work, and validation passed: `castor test:timeout-hardstop`, `castor cs-check`, `castor test:llm-real` (5 tests, 51 assertions OK in 24.5s), no stale PHAR workers after llm-real, focused prompt/tool tests passed. Parent inspection confirmed commit/diff and no `<tool_usage>` block in `config/SYSTEM.md`.

## Task workflow update - 2026-06-16T23:18:14.204Z
- Summary: Read-only reviewer rerun completed for HEAD `0eb266fcc301cc8111a5a6e2720795efab4bdb09`: APPROVED. Reviewer confirmed `.agents/skills/testing/SKILL.md` and `tests/AGENTS.md` were read first, no files edited, no mutation/long validation commands run. Findings: `test_llm_real()` now satisfies user request by running PHPUnit in isolated `setsid` session via `run_commands_parallel()` with 30s hard timeout; `_reap_session()` + `_reap_process_group()` + PID sweep kill all same-session PHPUnit/PHAR/messenger descendants on timeout and normal completion; preflight outside timeout is acceptable because `check_llm_generation_ready()` is separately bounded; no hardcoded `<tool_usage>` block; prompt/tool guidance remains intact. Only nits/minors noted around `elseif` readability, Test E quoting comment, and buffered output live visibility; no requested changes.

## Task workflow update - 2026-06-16T23:20:32.081Z
- Validation: Current HEAD: `0eb266fcc301cc8111a5a6e2720795efab4bdb09` plus task metadata recorded in integration checkout; worktree clean.; `castor test:timeout-hardstop` PASS: Tests A-E all passed, including new PHPUnit/PHAR-worker leak regression proof; no tools-10 orphan worker increase observed.; `castor test:llm-real` PASS: llama.cpp generation readiness OK; PHPUnit 5 tests, 51 assertions OK; Castor task completed in 22.1s. Follow-up tools-10 scoped process scan found no lingering PHAR/controller/messenger/PHPUnit workers (only scanner self-match in inline scan, clean second scan).; `castor test` PASS: 2561 tests, 7520 assertions OK in 11.9s.; `castor deptrac` PASS: 0 violations, 0 errors, 1131 allowed, 815 uncovered.; `castor cs-check` PASS: PHP CS Fixer clean, files_fixed=0.; `castor phpstan` FAILS with known unrelated/pre-existing mainline issue: 2 file_errors in `src/Platform/Bridge/Generic/DurableResultConverter.php:146` for missing `Symfony\AI\Platform\Exception\IncompleteStreamException`. Verified `git diff` has no task changes to that file and `origin/main` contains the same use/throw; `rg` finds no class definition in vendor/src. This is outside tools-10 scope and unchanged by this branch.
- Summary: Focused validation after llm-real hardening completed. All task-relevant checks pass (`test:timeout-hardstop`, `test:llm-real`, full `test`, `deptrac`, `cs-check`); `phpstan` remains blocked only by the known unrelated `IncompleteStreamException` mainline error in `src/Platform/Bridge/Generic/DurableResultConverter.php:146`, unchanged by this branch. Worktree is clean.

## Task workflow update - 2026-06-16T23:22:00.287Z
- Validation: Official CODE-REVIEW transition attempt: FAILED due to deterministic `castor check` phpstan lane only (`quality failed: - phpstan: exit code 1`).; Task status after failed transition: still `tasks/IN-PROGRESS/tools-10-prompt-docs-integration.md`; no PR created.; Checkouts after failed transition: integration `main...origin/main` clean; worktree `task/tools-10-prompt-docs-integration` clean.
- Summary: Attempted official `move_task(to=CODE-REVIEW)` after reviewer approval and focused validation. The automatic deterministic `castor check` gate failed only on `phpstan` (exit code 1), so the task remains IN-PROGRESS and no PR was created. Worktree and integration checkout are clean. Gate output: `quality failed: - phpstan: exit code 1`. `var/reports/check-phpstan.log` shows the same known unrelated/mainline failure: `src/Platform/Bridge/Generic/DurableResultConverter.php:146` instantiates/throws missing `Symfony\AI\Platform\Exception\IncompleteStreamException` (2 file_errors).
