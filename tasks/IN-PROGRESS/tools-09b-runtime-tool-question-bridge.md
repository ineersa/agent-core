# TOOLS-09B Runtime bridge for bash background confirmation questions

## Goal
## Goal
Wire the bash tool's user-controlled background confirmation prompt to the existing TUI question infrastructure without importing TUI code into tool/runtime workers.

This follows TOOLS-09, which implements bash as a background-managed foreground-supervised tool with a prompt adapter that defaults to decline. TOOLS-09B replaces/augments that default adapter with a real runtime/TUI bridge.

## Scope
- Reuse existing TUI question infrastructure:
  - `src/Tui/Question/QuestionRequest.php`
  - `src/Tui/Question/QuestionCoordinator.php`
  - `src/Tui/Question/QuestionController.php`
  - `QuestionSource::Tui`, `QuestionKind::Confirm`, `transcript=false`.
- Add a runtime-safe tool question request/answer bridge for local tool prompts such as `Command still running after 30s. Move to background?`.
- The tool/runtime side must not depend on `src/Tui/` classes.
- Expose a runtime event/protocol payload for a pending tool-local question, including at minimum:
  - `request_id`
  - `run_id` / session id where available
  - `tool_call_id` where available
  - `tool_name` = `bash`
  - `pid`
  - `log_path`
  - safe command preview, not raw sensitive output
  - prompt text
  - kind = confirm
  - transcript = false
- TUI `RuntimeEventPoller` or an adjacent deptrac-safe coordinator detects the runtime event and enqueues a local `QuestionRequest(source: QuestionSource::Tui, kind: QuestionKind::Confirm, transcript: false, allowOther: false)`.
- User answer is sent back through `AgentSessionClient` / runtime protocol, not by directly injecting TUI services into tools.
- Add a runtime command for answering local tool questions, e.g. `answer_tool_prompt`, or an equivalent typed protocol command.
- Bash supervisor receives the decision:
  - accept: return `Moved to background. PID: N, Log: <path>` and leave process running under `BackgroundProcessManager`.
  - decline: continue foreground supervision until completion, timeout, cancellation, or a future prompt policy decision.
  - cancel/reject: treat as decline unless product decision says otherwise; document chosen semantics.
- Local tool questions must not create transcript blocks or canonical HITL `answer_human` traffic.
- Keep live log streaming/display out of scope; the runtime event may carry `log_path` so a later task can implement TUI log tailing.

## Out of scope
- Do not implement live bash output streaming in the TUI.
- Do not use `ask_human` / `answer_human` for local bash background prompts.
- Do not persist local bash prompts as transcript blocks.
- Do not make the model choose backgrounding via a tool parameter.

## Implementation notes
- TOOLS-09's `BashBackgroundPromptAdapterInterface` (or equivalent) should become the tool-side abstraction. This task provides a production implementation that waits for/resolves a runtime-mediated decision.
- Prefer a small DTO/protocol object under `src/CodingAgent/Runtime/Contract` or `Protocol`, plus controller/process transport support, over ad-hoc arrays.
- Respect architecture boundaries: TUI talks through `AgentSessionClient` and runtime protocol only; `src/Tui/` must not import AgentCore internals or `BackgroundProcessManager`.
- If the command/log path is shown to the user, cap/truncate command preview and avoid raw prompt/tool output in logs per `docs/datadog.md` privacy rules.

## Dependencies
- Depends on TOOLS-09.
- Reuses QH-01/QH-02 question DTO/controller infrastructure already present.
- Related to but distinct from QH-07: QH-07 binds AgentCore HITL (`ask_human`/`answer_human`) to questions; this task binds local tool prompts and must keep `transcript=false`.

## Acceptance criteria
- A long-running bash command triggers a TUI confirmation question at the configured threshold.
- The TUI question uses existing `QuestionCoordinator` / `QuestionController` and is `QuestionSource::Tui`, `QuestionKind::Confirm`, `transcript=false`.
- Accepting leaves the already-started `BackgroundProcessManager` process running and returns PID/log path to the model.
- Declining keeps bash supervised in foreground until completion, timeout, or cancellation.
- No duplicate command is launched when accepting backgrounding.
- No local bash background prompt is persisted as a transcript block or sent as `answer_human`.
- TUI/runtime boundary remains deptrac-clean; TUI does not depend on `BackgroundProcessManager`, and tool code does not depend on `src/Tui/`.
- Focused tests cover accept, decline, cancellation/rejection semantics, and protocol serialization/transport where applicable.
- Required validation is run through Castor, including `castor check` before handoff unless environment prerequisites are unavailable.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/tools-09b-runtime-tool-question-bridge
Worktree: /home/ineersa/projects/agent-core-worktrees/tools-09b-runtime-tool-question-bridge
Fork run: 90wun3bqege1
PR URL:
PR Status:
Started: 2026-06-06T23:33:30.150Z
Completed:

## Work log
- Created: 2026-05-31T18:02:46.913Z

## Task workflow update - 2026-06-06T23:33:02.611Z
- Summary: Design clarification after review: maximize reuse of the existing HITL/TUI question infrastructure. Do not build a parallel question system for bash background prompts. Reuse QuestionCoordinator, QuestionRequest, QuestionController overlay, RuntimeEventPoller/TickPollListener callback wiring, and AgentSessionClient/UserCommand transport patterns wherever possible. The distinction from SafeGuard is lifecycle, not UI: SafeGuard is pre-tool approval that enters AgentCore WaitingHuman and resumes via answer_human; bash background confirmation is a mid-running local tool prompt inside BashTool::shouldBackground() that needs a boolean answer and must remain transcript=false. Implementation should add only minimal adapter/bridge glue needed to surface a local tool prompt to the existing question overlay and return the answer to the blocked bash tool. Do not route local bash background prompts through ask_human/answer_human or canonical WaitingHuman unless the task is explicitly re-scoped. Any dedicated protocol/event/command should be minimal and justified by the mid-tool/cross-process answer-delivery requirement, not by creating a new question architecture. Prefer extracting/shared helpers from the existing human_input.requested handling if that avoids duplication. Avoid introducing a DB-backed pending-question resolver unless exploration proves existing runtime mechanisms cannot safely deliver the answer back to the tool worker process.
- User clarified that existing SafeGuard/HITL question infrastructure is already implemented and working; TOOLS-09B must maximize reuse rather than inventing a separate question/resolver system.
- Implementation guidance updated: reuse existing TUI question coordinator/overlay/DTOs and runtime listener shape; add only the smallest local-tool bridge necessary for BashTool background confirmation.

## Task workflow update - 2026-06-06T23:33:30.150Z
- Moved TODO → IN-PROGRESS.
- Created branch task/tools-09b-runtime-tool-question-bridge.
- Created worktree /home/ineersa/projects/agent-core-worktrees/tools-09b-runtime-tool-question-bridge.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/tools-09b-runtime-tool-question-bridge.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/tools-09b-runtime-tool-question-bridge.

## Task workflow update - 2026-06-06T23:40:15.135Z
- Recorded fork run: cqv6k03pf40f
- Summary: Task started and implementation fork launched in worktree /home/ineersa/projects/agent-core-worktrees/tools-09b-runtime-tool-question-bridge. Scout findings: BashTool already has the correct single seam, BashBackgroundPromptAdapterInterface::shouldBackground(command, pid, logPath, elapsedSeconds): bool, called once from the supervision loop after BackgroundProcessManager::start(); accepting returns a background notice and leaves the already-started process running, preserving no-duplicate-command behavior. ToolContext via StackToolExecutionContextAccessor provides runId, toolCallId, toolName, cancellationToken, and timeoutSeconds. Existing TUI question infrastructure already supports local callbacks through QuestionCoordinator/QuestionRequest/QuestionController with QuestionSource::Tui, QuestionKind::Confirm, transcript=false. Existing TickPollListener currently hardcodes human_input.requested as AgentCore Approval transcript=true with answer_human callbacks; TOOLS-09B should add an adjacent/reused tool_question path instead. RuntimeEventPoller can be extended with a second optional callback for tool_question.requested. Process-mode TUI is default, and tool workers do not have stdout polled by HeadlessController (only LLM stdout is polled), so the fork was instructed to implement the minimal cross-process pending answer bridge necessary while reusing the existing question UI and runtime command patterns.
- Launched two scout subagents in the task worktree: one for BashTool/background prompt internals and one for existing HITL/TUI question/runtime reuse seams.
- Launched implementation fork cqv6k03pf40f with reuse-first instructions: add minimal tool_question.requested / answer_tool_question bridge, reuse QuestionCoordinator/QuestionController, keep transcript=false, avoid ask_human/answer_human, preserve no-duplicate bash execution, add focused tests, run Castor focused validation, commit, and stop before PR/gate steps.

## Task workflow update - 2026-06-06T23:51:02.907Z
- Recorded fork run: cqv6k03pf40f
- Validation: Verified implementation commit/status/stat in worktree: `git status --short` clean, `git log -1 --oneline` = e3096703 TOOLS-09B: Runtime bridge for bash background confirmation questions, `git diff --stat HEAD~1..HEAD` = 20 files changed, 1259 insertions(+), 17 deletions(-).; Fork-reported focused Castor validation: `castor test` passed (1823 tests, 0 failures, 0 errors, 4 skipped).; Fork-reported focused Castor validation: `castor phpstan` passed (0 errors).; Fork-reported focused Castor validation: `castor deptrac` passed (0 violations, 0 errors, 704 uncovered).; Fork-reported focused Castor validation: `castor cs-check` passed (0 files fixed).; Not run in task-start phase: `LLM_MODE=true castor check`; task-to-pr must run the full gate before PR.
- Summary: Implementation fork cqv6k03pf40f completed successfully on branch task/tools-09b-runtime-tool-question-bridge. Verified worktree is clean, latest commit is e3096703 (TOOLS-09B: Runtime bridge for bash background confirmation questions), and git diff stat shows 20 files changed with 1259 insertions/17 deletions. Implementation adds a reuse-first runtime bridge for BashTool background confirmation prompts: new tool_question.requested runtime event, answer_tool_question user command path, Doctrine-backed ToolQuestion persistence for cross-process controller mode, RuntimeBashBackgroundPromptAdapter bound as the production BashBackgroundPromptAdapterInterface implementation, ToolQuestionPoller in the controller, AnswerToolQuestionHandler, InProcess/JsonlProcess answer handling, and TUI TickPollListener/RuntimeEventPoller integration that enqueues existing QuestionCoordinator Confirm questions with source=Tui and transcript=false. No PR/gate steps were run per task-start boundary; full LLM_MODE=true castor check remains for task-to-pr.
- Recorded implementation fork result for cqv6k03pf40f. Key files added/modified include ToolQuestion entity/store/status/interface, RuntimeBashBackgroundPromptAdapter, ToolQuestionPoller, AnswerToolQuestionHandler, RuntimeEventTypeEnum/UserCommand protocol updates, Jsonl/InProcess answer handling, TickPollListener/RuntimeEventPoller TUI question integration, services.yaml binding, migration Version20260606140000, ApplicationMigrationExecutor registration, depfile.yaml update, and handler/enum tests.
- Known gap carried forward: no full TUI/LLM E2E validation yet because task-start phase stops before castor check; task-to-pr must run the full quality gate. Fork also noted stale pending ToolQuestions may remain if a run terminates before answer; no lifecycle cleanup hook was added in this task.

## Task workflow update - 2026-06-07T00:19:30.667Z
- Recorded fork run: 1ommiw6zs466
- Summary: task-to-pr review phase started. Worktree inspected: branch task/tools-09b-runtime-tool-question-bridge, clean status, latest implementation commit e3096703, diff origin/main...HEAD shows 20 files changed with 1259 insertions/17 deletions. Reviewer subagent returned APPROVE WITH SUGGESTIONS, no critical issues. Actionable findings are being treated as fix-before-PR per user instructions: duplicate AppTool deptrac entry, idempotent ToolQuestionStore answer/cancel behavior, remove QueryBuilder leak/dead interface method, consolidate duplicated answer parsing, and add low-risk stale pending question startup cancellation/active-run handling. Implementation fork 1ommiw6zs466 launched to apply those fixes, run focused Castor validation, commit, and stop before PR/gate steps.
- task-to-pr initial inspection: git status clean, latest commit e3096703, full diff stat 20 files changed / 1259 insertions / 17 deletions.
- Reviewer subagent verdict: APPROVE WITH SUGGESTIONS. No critical issues; actionable suggestions forwarded to fork 1ommiw6zs466 for fix-before-PR.
- Fork 1ommiw6zs466 instructions: remove duplicate deptrac AppTool entry, make ToolQuestionStore answer/cancel idempotent, replace ToolQuestionStoreInterface QueryBuilder leak with explicit finder, extract ToolQuestionAnswerResolver, handle stale pending tool questions on controller startup or active-run filtering, add/update tests, run castor test/deptrac/phpstan/cs-check, commit, stop.

## Task workflow update - 2026-06-07T00:31:45.307Z
- Recorded fork run: 6rve3gftz4ui
- Summary: Second reviewer pass after commit b1d8b010 returned APPROVE WITH SUGGESTIONS. Reviewer confirmed all first-pass findings were addressed and found no critical issues. Two sensible actionable follow-ups are being fixed before PR: add missing RuntimeEventTypeEnum family test coverage for ToolQuestionRequested/tool_question, and set updatedAt explicitly in ToolQuestionStore::cancelPendingQuestionsCreatedBefore() bulk DQL cleanup. The theoretical TOCTOU locking suggestion was intentionally not forwarded because reviewer described the blast radius as negligible in the current single-controller/single-answer SQLite flow, and strict locking would require disproportionate SELECT FOR UPDATE/optimistic-lock changes before PR. Follow-up fork 6rve3gftz4ui launched to apply the two small fixes, run Castor validation, commit, and stop.
- Second reviewer subagent verdict on current HEAD b1d8b010: APPROVE WITH SUGGESTIONS. Prior review findings verified fixed; no architecture/deptrac/runtime/TUI blockers found.
- Fork 6rve3gftz4ui launched for final review follow-up: add ToolQuestionRequested familyProvider/helper predicate coverage and set updatedAt in stale pending ToolQuestion bulk cleanup.

## Task workflow update - 2026-06-07T00:43:34.517Z
- Recorded fork run: djx7ma1em7ui
- Summary: Final reviewer pass after c6774966 returned APPROVE WITH SUGGESTIONS with no Issues and no Critical Issues. Reviewer verified all prior findings fixed: deptrac duplicate removed, ToolQuestionStore answer/cancel idempotent, QueryBuilder/dead API removed, answer parsing consolidated, startup stale cleanup safe, bulk cleanup updates updatedAt, and ToolQuestionRequested family/helper tests present. One reasonable high-value NTH remains: add kernel-backed ToolQuestionStore integration coverage for create/poll/answer/cancel/stale cleanup. Fork djx7ma1em7ui launched with default fork settings to add that test using IsolatedKernelTestCase/test container, run Castor validation, commit, and stop. Other NTHs intentionally skipped: no debug log for no questions found (would add log noise), no extra entity factory validation because the single production caller already caps command preview.
- Final reviewer subagent verdict on HEAD c6774966: APPROVE WITH SUGGESTIONS, with no actionable Issues/Critical Issues. Security notes and design notes were non-blocking.
- Fork djx7ma1em7ui launched to address the only reasonable pre-PR NTH: kernel-backed ToolQuestionStore integration test.

## Task workflow update - 2026-06-07T00:56:24.430Z
- Recorded fork run: f3ob1q2ca6au
- Validation: `castor test` on HEAD 8143aaf2: PHPUnit completed with tests=1851, assertions=5393, errors=0, failures=0, skipped=4, but command exit code was 1 because PHAR ensure/composer install failed: symfony/ai-agent and symfony/ai-generic-platform are locked at v0.9.0 while composer.json requires dev-main.; Inspected composer state: origin/main and current branch both have composer.json symfony/ai-agent=dev-main, symfony/ai-generic-platform=dev-main, symfony/ai-platform=^0.9, while composer.lock has ai-agent/generic-platform/platform v0.9.0; this is not in the TOOLS-09B diff but blocks validation.
- Summary: During task-to-pr focused validation on HEAD 8143aaf2, final reviewer returned APPROVE WITH SUGGESTIONS with no Issues/Critical Issues. Remaining NTHs were analyzed and not changed: STARTUP_CLEANUP_CUTOFF already has rationale comments, and RuntimeBashBackgroundPromptAdapter's poll loop is bounded by ToolExecutor's max(1) timeout policy plus BashTool's pre-prompt timeout check. However, `castor test` exited nonzero even though PHPUnit passed (1851 tests, 0 failures/errors) because PHAR ensure/composer install failed on an existing base-branch composer.json/composer.lock mismatch: composer.json requires symfony/ai-agent and symfony/ai-generic-platform dev-main, while composer.lock has v0.9.0. The mismatch is present on origin/main and not introduced by TOOLS-09B (diff origin/main...HEAD has no composer changes), but it blocks required validation/gate. Fork f3ob1q2ca6au launched to minimally sync composer.lock for current constraints, validate with Castor, commit if safe, or report if broad/unrelated.
- Final reviewer on HEAD 8143aaf2: APPROVE WITH SUGGESTIONS, no Issues/Critical Issues. No more code-review blockers found.
- Focused validation found a base composer lock mismatch causing `castor test` nonzero despite PHPUnit success. Fork f3ob1q2ca6au launched to minimally sync the lock and rerun focused Castor validation.

## Task workflow update - 2026-06-07T00:57:17.455Z
- Recorded fork run: f3ob1q2ca6au
- Validation: Before lock update on HEAD 8143aaf2: `castor test` exited 1 because PHAR ensure/composer install failed on composer.json/composer.lock mismatch, though PHPUnit passed 1851 tests / 5393 assertions / 0 failures / 0 errors / 4 skipped.; Intermediate after targeted composer update (restored, not committed): AI packages resolved to symfony/ai-agent dev-main 754ad104 and symfony/ai-generic-platform dev-main 1ab8b824; symfony/ai-platform stayed v0.9.0. `castor test` exited 0 with 1851 tests passing. Changes were too broad and were discarded.; Post-fork state: no commit made; worktree restored clean at HEAD 8143aaf2.
- Summary: Fork f3ob1q2ca6au investigated the Composer lock mismatch blocking Castor validation. It confirmed current branch and origin/main both have composer.json requiring symfony/ai-agent and symfony/ai-generic-platform as dev-main while composer.lock still locks both at v0.9.0. Targeted composer update resolved the AI packages to dev-main commits (ai-agent 754ad104, ai-generic-platform 1ab8b824, ai-platform remained v0.9.0 due ^0.9 constraints), and `castor test` then exited 0. However, the update also produced broad unrelated lock normalization/dependency bumps (20 files / ~3000 lines, including Symfony, PHPUnit, PHPStan, Monolog, Twig, etc.), violating the explicit safe-minimal-lock-sync boundary. Fork therefore did not commit and restored the worktree to clean pre-update state. Parent/user decision needed: accept broad lock normalization in a separate/base task, merge/fix update-symfony-81-ai-main first if applicable, or explicitly authorize risky manual lock surgery. TOOLS-09B implementation commits remain unchanged and ready, but task-to-pr validation is blocked until the base Composer lock mismatch is resolved.
- Fork f3ob1q2ca6au completed with no commit: Composer update fixed validation but produced broad unrelated dependency changes, so fork stopped per instructions and restored a clean worktree.
- Current blocker: origin/main itself has composer.json requiring dev-main AI packages while composer.lock still locks v0.9.0; this blocks Castor PHAR ensure and therefore task-to-pr quality gate for TOOLS-09B until resolved outside or explicitly authorized.

## Task workflow update - 2026-06-07T16:06:04.641Z
- Recorded fork run: ffhjocf4swsm
- Summary: User accepted the broader Composer lock normalization as fine because it makes Composer/Castor work. Fork ffhjocf4swsm launched to apply the targeted Composer update despite broader lock refresh, run focused Castor validation (`castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check`), commit, and stop before PR/gate/push/status move.
- User decision: broad Composer lock refresh is acceptable if Composer/Castor works. Fork ffhjocf4swsm launched to apply and validate it.

## Task workflow update - 2026-06-07T16:07:18.769Z
- Recorded fork run: 8ipsqnxrrju4
- Summary: Fork ffhjocf4swsm returned a corrupted/incomplete artifact (`The fork tool appears to map to subagent with context: fork. Let me retry:`) and produced no usable handoff. Retrieval returned the same incomplete text. Worktree was verified clean at HEAD 8143aaf2. Replacement fork 8ipsqnxrrju4 launched with explicit instruction to do the Composer lock refresh itself, not delegate to subagents/forks, run focused Castor validation, commit, and stop.
- Fork ffhjocf4swsm result was unusable/corrupted; no evidence of changes or validation. Worktree remained clean at HEAD 8143aaf2.
- Replacement fork 8ipsqnxrrju4 launched to apply the user-authorized broad Composer lock refresh and focused validation.

## Task workflow update - 2026-06-07T16:09:53.458Z
- Recorded fork run: 8ipsqnxrrju4
- Validation: `castor test`: exit 0; 1851 tests, 5405 assertions, 0 failures, 0 errors; PHAR build succeeded.; `castor deptrac`: exit 0; 0 violations, 0 errors.; `castor phpstan`: exit 0; 0 errors.; `castor cs-check`: exit 0; 0 files fixed.
- Summary: Fork 8ipsqnxrrju4 completed the user-authorized Composer lock refresh and committed aeb14d2b (`TOOLS-09B: Refresh Composer lock for validation`). Worktree verified clean at HEAD aeb14d2b. Only composer.lock changed (122 insertions, 114 deletions). The lock now satisfies composer.json: symfony/ai-agent resolved to dev-main 754ad104d44778e87aef79fd4335e21f8deb288b, symfony/ai-generic-platform resolved to dev-main 1ab8b8242959a230d0fa36056237a8d6328ba547, and symfony/ai-platform remains v0.9.0. Broader transitive lock normalization was explicitly user-authorized because it makes Composer/Castor work. No source changes, no push, no PR/status move, no full castor check.
- Fork 8ipsqnxrrju4 result verified: commit aeb14d2b exists on task/tools-09b-runtime-tool-question-bridge, worktree clean, composer.lock-only stat 122+/114-.
- TOOLS-09B implementation phase complete and ready for user-initiated task-to-pr.

## Task workflow update - 2026-06-07T16:36:54.269Z
- Recorded fork run: 043idi2hapr7
- Summary: task-to-pr reviewer pass on HEAD aeb14d2b returned APPROVE WITH SUGGESTIONS. No critical issues, but actionable findings are being addressed before PR per user instruction: duplicate ToolQuestionStore::create request_id edge case, redundant JsonlProcessAgentSessionClient answer payload assignment, pollAnswer false/cancelled semantics doc clarity, ToolQuestion factory requestId/commandPreview validation, enum parameter style consistency, DQL bulk update lifecycle comment, and high-value ToolQuestionPoller test coverage if practical. Non-actionable/subjective suggestions intentionally skipped: renaming ToolQuestionStoreInterface, pinning AI dev-main to a tag, changing startup cleanup cutoff/config, and payload privacy changes for intentionally user-visible log_path/capped command_preview. Fork 043idi2hapr7 launched to implement fixes, run focused Castor validation, commit, and stop.
- Reviewer on current HEAD aeb14d2b: APPROVE WITH SUGGESTIONS, no critical issues/blockers. Actionable suggestions forwarded to fork 043idi2hapr7 for fix-before-PR.
- Fork 043idi2hapr7 launched for edge-case/test/doc cleanup before re-review.

## Task workflow update - 2026-06-07T16:43:01.072Z
- Recorded fork run: 043idi2hapr7
- Validation: Fork-reported targeted tests: 99 tests, 457 assertions, 0 failures.; Fork-reported `castor test`: 1861 tests, 5433 assertions, 0 failures, 0 errors, 0 skipped.; Fork-reported `castor deptrac`: 0 violations, 0 errors.; Fork-reported `castor phpstan`: 0 errors.; Fork-reported `castor cs-fix`: fixed 1 styling issue before commit; `castor cs-check`: 0 files fixed.
- Summary: Fork 043idi2hapr7 completed reviewer-fix pass and committed 837b31bf (`TOOLS-09B: Tighten tool question edge cases`). Worktree verified clean at HEAD 837b31bf. The commit changed 6 files (359 insertions, 17 deletions): ToolQuestion entity factory validation, ToolQuestionStore duplicate create idempotency/race fallback + enum/doc cleanup, ToolQuestionStoreInterface pollAnswer semantics doc, JsonlProcessAgentSessionClient answer_tool_question payload cleanup, new ToolQuestionPollerTest, and expanded ToolQuestionStoreTest. Actionable reviewer findings addressed: duplicate request_id create edge case, redundant JSONL answer assignment, false/cancelled pollAnswer semantics doc clarity, requestId and commandPreview entity guards, enum parameter consistency, DQL bulk update lifecycle comment, and ToolQuestionPoller coverage. Non-actionable suggestions skipped as previously decided: interface rename, AI tag pinning, cutoff configurability, DI-only resolver, and privacy changes for intentionally user-visible log_path/capped command_preview.
- Verified fork 043idi2hapr7 commit 837b31bf on task/tools-09b-runtime-tool-question-bridge, worktree clean, commit stat 6 files / 359+ / 17-.
- Next task-to-pr step: re-run reviewer on current HEAD 837b31bf.

## Task workflow update - 2026-06-07T16:51:45.332Z
- Recorded fork run: 90wun3bqege1
- Summary: Re-review of HEAD 837b31bf returned APPROVE WITH SUGGESTIONS with no critical issues. Remaining sensible/actionable suggestions are being addressed before PR: isolate/log ToolQuestionPoller emit/markEmitted failures while continuing subsequent questions, document intentional log_path/capped command_preview payload privacy boundary, share ToolQuestion command preview length constant with RuntimeBashBackgroundPromptAdapter, add additional ToolQuestion factory guards and explicit column lengths, and add adapter polling-loop rationale comment. Explicitly skipped as non-actionable/design tradeoffs: replacing EntityManager::clear() with DBAL fresh reads, moving test spy helper, PHP typed const compatibility (project PHP >=8.5), startup cleanup cutoff configurability, and changing private test seam to production API. Fork 90wun3bqege1 launched to implement these fixes, validate, commit, and stop.
- Second reviewer on HEAD 837b31bf: APPROVE WITH SUGGESTIONS, no critical issues. Actionable/sensible suggestions forwarded to fork 90wun3bqege1.
- Fork 90wun3bqege1 launched for final review polish before another reviewer pass.
