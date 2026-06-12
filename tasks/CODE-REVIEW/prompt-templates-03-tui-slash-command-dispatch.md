# PT-03 TUI prompt-template slash commands and runtime dispatch

## Goal
Reference plan: `.pi/plans/prompt-templates-implementation-plan.md`.

Scope:
- Depends on PT-01.
- Register prompt templates as TUI slash commands using the catalog contract from PT-01.
- Keep real slash commands authoritative: template commands fill gaps only and run after real registrars.
- Implement `DispatchRuntime` handling in `SubmitListener` so `/template args` follows the normal start/steer/follow-up runtime path.
- Do not modify `CommandMetadata` or `SlashCommandCompletionProvider` for `argument-hint`; unknown frontmatter hint fields are non-MVP.

Can run in parallel with PT-02 after PT-01 lands.

## Acceptance criteria
- `PromptTemplateCommandRegistrar` registers one virtual slash command per catalog template, lower-priority than real registrars, with real commands winning on name collisions.
- Registered template slash commands return `DispatchRuntime` with the original slash text and generic usage such as `/<name> <args>`.
- `SubmitListener` handles `DispatchRuntime` by forwarding the payload through the same runtime path as normal prompts for initial start, active steer, and idle follow-up.
- TUI command names align with lowercase canonical names from PT-01 and existing `CommandParser` lowercasing; no parser case-behavior change.
- Tests cover registrar metadata, real-command collision skip, handler returns `DispatchRuntime`, SubmitListener start/steer/follow-up forwarding, and runtime error handling.
- Because this touches TUI/runtime flow, `LLM_MODE=true castor check` must pass before moving this task to CODE-REVIEW; if prerequisites are unavailable, keep the task IN-PROGRESS and record the blocker.

## Workflow metadata
Status: CODE-REVIEW
Branch: task/prompt-templates-03-tui-slash-command-dispatch
Worktree: /home/ineersa/projects/agent-core-worktrees/prompt-templates-03-tui-slash-command-dispatch
Fork run: k8t52x1i0r37
PR URL: https://github.com/ineersa/agent-core/pull/138
PR Status: open
Started: 2026-06-12T18:08:24.597Z
Completed:

## Work log
- Created: 2026-06-09T00:10:10.866Z

## Task workflow update - 2026-06-12T18:08:24.597Z
- Moved TODO → IN-PROGRESS.
- Created branch task/prompt-templates-03-tui-slash-command-dispatch.
- Created worktree /home/ineersa/projects/agent-core-worktrees/prompt-templates-03-tui-slash-command-dispatch.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/prompt-templates-03-tui-slash-command-dispatch.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/prompt-templates-03-tui-slash-command-dispatch.
- Summary: Claiming PT-03 for TUI prompt-template slash command registration and SubmitListener DispatchRuntime forwarding. Depends on PT-01/PT-02 now merged. Implementation must include real TmuxHarness + test LLM E2E proof for the user-visible `/template args` TUI path before handoff.

## Task workflow update - 2026-06-12T18:16:07.889Z
- Validation: context: read task file and `.pi/plans/prompt-templates-implementation-plan.md` PT-03 sections; scout: TUI slash command architecture explored (`SlashCommandRegistry`, `CommandParser`, `DispatchRuntime`, `TuiListenerRegistrar`, `services.yaml` tagging); scout: SubmitListener dispatch flow explored; normal prompt dispatch has start/draft-promotion, post-shell restart, steer, follow_up, shared error handling; `DispatchRuntime` currently ignored by `applyCommandResult()`; scout: TUI E2E harness explored; mandatory test should create isolated `.hatfield/prompts/review.md`, launch real TUI with test LLM, type `/review <marker>`, and assert expanded prompt appears in transcript via TmuxHarness
- Summary: Claimed PT-03 and gathered implementation context. Worktree: `/home/ineersa/projects/agent-core-worktrees/prompt-templates-03-tui-slash-command-dispatch` on branch `task/prompt-templates-03-tui-slash-command-dispatch`. Note: integration checkout had unrelated untracked `.pi/plans/mcp-client-implementation-plan.md`; temporarily stashed/restored only to satisfy clean-checkout task claim. It remains untracked on main and is unrelated to PT-03.

## Task workflow update - 2026-06-12T18:26:47.924Z
- Recorded fork run: cqbtioutbwxy
- Validation: castor test --filter=PromptTemplateCommandRegistrarTest — OK (9 tests, 27 assertions); castor test --filter=SubmitListenerDispatchRuntimeTest — OK (11 tests, 47 assertions); LLM_MODE=true castor test:tui --filter=PromptTemplateSlashCommandE2ETest — OK (1 test, 1 assertion; real TmuxHarness + test LLM proof); castor test — OK (7 suites, 2486 tests, 0 failures); castor deptrac — OK (0 violations); castor phpstan — OK (0 errors); castor cs-check — OK (0 fixes); Note from fork: branch merge-base with origin/main is stale (`7e6e7b0f`); rebase before PR/code-review phase is recommended.
- Summary: Implementation fork completed and committed `c7adbed7` (`PT-03: TUI prompt-template slash command registration and DispatchRuntime forwarding`). Worktree clean on branch `task/prompt-templates-03-tui-slash-command-dispatch`. Diff stat against `origin/main...HEAD`: 6 files changed, 1002 insertions, 118 deletions. Implemented `PromptTemplateCommandRegistrar` with priority -100, priority-aware TUI listener iterator wiring, `SubmitListener` DispatchRuntime forwarding through shared runtime dispatch helper, unit tests for registrar and SubmitListener behavior, and mandatory TmuxHarness E2E proof for `/review <marker>` prompt template submission. Scope exclusions respected: no docs/PT-04 work, no built-ins, no CommandMetadata argument-hint changes, no runtime/client PT-02 changes.

## Task workflow update - 2026-06-12T19:20:05.313Z
- Recorded fork run: mzrr2yggobkk, wnmevhdws146, sjfyn0g44gz2
- Validation: reviewer initial verdict: APPROVE WITH SUGGESTIONS — actionable findings addressed by forks; reviewer follow-up verdict: REQUEST CHANGES — immediate working indicator regression from stale base fixed by rebase/fix fork; reviewer final verdict: APPROVED; castor test — OK: agent-core 292/1263, coding-agent-1 286/816, coding-agent-2 382/990, coding-agent-3 413/1112, coding-agent-4 375/1114, tui 686/1746, platform 54/221; total 2488 tests, 0 failures; LLM_MODE=true castor test:tui — OK (20 tests, 59 assertions, 0 failures, 0 skipped); castor deptrac — OK (0 violations, 0 errors); castor phpstan — OK (0 errors); castor cs-check — OK (0 fixes)
- Summary: PT-03 task-to-pr review completed. Initial reviewer returned APPROVE WITH SUGGESTIONS; fix fork `mzrr2yggobkk` committed `d00d0ab8` addressing draft-promotion/shell-restart DispatchRuntime test coverage, DispatchRuntime docblock, registrar test comments, and llm-real E2E group. Follow-up fork `wnmevhdws146` committed `f6a18a65` addressing remaining low-risk NTHs (handler reuse, clearer error-activity test naming, TestDirectoryIsolation for draft-promotion sessions). Final reviewer then found a stale-base regression against origin/main immediate working feedback; rebase/fix fork `sjfyn0g44gz2` rebased branch onto current origin/main and produced final HEAD `41202988`, preserving origin/main immediate `Working...` forced-render behavior inside shared `dispatchToRuntime()`. Final reviewer verdict: APPROVED. Worktree clean; merge-base equals origin/main (`6986d44f`). Diff stat: 7 files changed, 1103 insertions, 127 deletions.

## Task workflow update - 2026-06-12T19:26:59.476Z
- Recorded fork run: t0wmivj7d2pf
- Validation: first move_task quality gate: all steps OK except `test:tui` exit 124; phpunit log showed OK (20 tests, 59 assertions) in 91.948s, just over the 90s check step timeout; LLM_MODE=true castor test:tui --filter=PromptTemplateSlashCommandE2ETest — OK (1 test, 1 assertion) after wait removal; castor cs-check — OK (0 fixes); fork reported full LLM_MODE=true castor test:tui had unrelated/pre-existing LLM response timeouts in other E2E tests during its run; PT-03 test passed
- Summary: After first CODE-REVIEW move attempt, Castor quality gate failed only because `test:tui` hit the 90s step timeout although PHPUnit reported OK at 91.948s. Fix fork `t0wmivj7d2pf` committed `9d5f3c5d`, removing the non-essential optional 15s post-assertion assistant/error wait from `PromptTemplateSlashCommandE2ETest` while preserving the real TmuxHarness + test LLM core proof that expanded `Review: <marker>` appears in tmux history. Worktree remains clean at HEAD `9d5f3c5d`. Diff stat now 7 files changed, 1089 insertions, 127 deletions.

## Task workflow update - 2026-06-12T21:50:30.072Z
- Recorded fork run: i9u6u4tch52o
- Validation: LLM_MODE=true castor test:tui --filter=PromptTemplateSlashCommandE2ETest — OK (1 test, 1 assertion); castor cs-check — OK (0 fixes); castor deptrac — OK (0 violations, 0 errors); castor phpstan — OK (0 errors, 0 file_errors)
- Summary: Per user instruction, skipped reviewer step and launched merge fork `i9u6u4tch52o`. Fork merged current `origin/main` (`a0566611`) into PT-03 branch with `--no-ff`, creating clean merge commit `ed200677` with zero conflicts. PT-03 feature diff remains 7 files, +1089/-127. Worktree clean and ready for CODE-REVIEW retry.
Castor Check Status: passed
Castor Check Commit: 5545d6e28641599d3ac2cd064de27fc95c1ede2c
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 900s
Castor Check Completed: 2026-06-12T22:33:21.734Z
Castor Check Output SHA256: e05f2e8f061e0dd0499bf4f15a5fce174abfc5713a870cf7d6d978b72956489a

## Task workflow update - 2026-06-12T21:51:44.227Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (900s timeout). Commit: ed20067708b4.
- Pushed task/prompt-templates-03-tui-slash-command-dispatch to origin.
- branch 'task/prompt-templates-03-tui-slash-command-dispatch' set up to track 'origin/task/prompt-templates-03-tui-slash-command-dispatch'.
- Created PR: https://github.com/ineersa/agent-core/pull/138
- Validation: merge fork i9u6u4tch52o: `git merge --no-ff origin/main` clean; HEAD `ed200677`; LLM_MODE=true castor test:tui --filter=PromptTemplateSlashCommandE2ETest — OK (1 test, 1 assertion); castor cs-check — OK (0 fixes); castor deptrac — OK (0 violations, 0 errors); castor phpstan — OK (0 errors, 0 file_errors)
- Summary: Moving PT-03 to CODE-REVIEW per user instruction, with no additional reviewer step. Branch was merge-updated from current origin/main by fork `i9u6u4tch52o` at merge commit `ed200677` (clean merge, zero conflicts). Focused validation passed after merge; move_task will run the full `LLM_MODE=true castor check` quality gate before pushing/opening PR.

## Task workflow update - 2026-06-12T22:05:18.808Z
- Recorded fork run: 67zr7nu1f2ms, ogehkokb3cag
- Validation: castor cs-check — OK (0 fixes); manual file inspection: `.hatfield/prompts/report.md` frontmatter contains only supported `description`; body starts with `User reported issue:` fenced `text` block containing `$ARGUMENTS`; git push origin task/prompt-templates-03-tui-slash-command-dispatch — OK; origin updated ed200677..8f012b46
- Summary: Added daily-use project prompt template `.hatfield/prompts/report.md` on the PT-03 PR branch and pushed it to PR #138. The final amended commit is `8f012b46` (`Add report prompt template for /report issue filing with diagnostics`). The template uses `$ARGUMENTS` in the body as the actual prompt-template argument substitution mechanism, rendering `/report <issue>` as a fenced `User reported issue` block before model instructions. The misleading ignored `arguments:` frontmatter was removed. Branch is now synced with origin.

## Task workflow update - 2026-06-12T22:08:10.333Z
- Moved CODE-REVIEW → IN-PROGRESS.
- Summary: Reopening PT-03 for a small prompt-template refinement requested by user: remove gist/upload attachment guidance from `.hatfield/prompts/report.md` and instruct the model to provide absolute local paths to sanitized snapshot/log artifacts instead. PR #138 remains open; no reviewer step required for this prompt-only tweak unless requested.

## Task workflow update - 2026-06-12T22:09:22.355Z
- Recorded fork run: oswftse07w4a
- Validation: castor cs-check — OK (0 fixes); manual file inspection: section 5 is now `Include Diagnostic Artifacts` and lists absolute local snapshot/log paths; `$ARGUMENTS` fenced block remains unchanged at top; git push --force-with-lease origin task/prompt-templates-03-tui-slash-command-dispatch — OK (forced update 8f012b46...9621e8ae)
- Summary: Refined `.hatfield/prompts/report.md` per user feedback: removed gist/upload workaround guidance and now instructs the model to include absolute paths to sanitized local diagnostic artifacts directly in the issue body/final response. Fork amended report-template commit to `9621e8ae`; branch was force-pushed with lease to update PR #138 from prior `8f012b46` to `9621e8ae`. Worktree clean.

## Task workflow update - 2026-06-12T22:13:55.452Z
- Recorded fork run: k8t52x1i0r37
- Validation: castor cs-check — OK (0 fixes); LLM_MODE=true castor test:tui --filter=EditorBorderColorTest — OK (1 test, 5 assertions); git push origin task/prompt-templates-03-tui-slash-command-dispatch — OK (9621e8ae..8cc96467); pre-retry stale process check: no running processes found for PT-03 worktree
- Summary: Merge fork `k8t52x1i0r37` merged current `origin/main` (`148f9033`) into PT-03 branch with zero conflicts, creating merge commit `8cc96467`. Origin/main changes were task metadata only and did not overlap PT-03 feature files. Branch pushed to origin (`9621e8ae..8cc96467`) and remains ready for CODE-REVIEW retry; no reviewer step per user instruction.

## Task workflow update - 2026-06-12T22:33:22.894Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (900s timeout). Commit: 5545d6e28641.
- Pushed task/prompt-templates-03-tui-slash-command-dispatch to origin.
- branch 'task/prompt-templates-03-tui-slash-command-dispatch' set up to track 'origin/task/prompt-templates-03-tui-slash-command-dispatch'.
- Skipped PR creation (pushOnly: true).
- Validation: manual LLM_MODE=true castor check — OK (quality OK across all steps); move_task gate retry failed once on EditorBorderColorTest flake; focused LLM_MODE=true castor test:tui --filter=EditorBorderColorTest immediately passed (1 test, 6 assertions) after clearing stale PT-03 PHAR processes; stale PT-03 PHAR processes were checked/killed before retry
- Summary: Returning PT-03 to CODE-REVIEW after manual full quality gate passed and retrying after transient EditorBorderColorTest flake. Branch includes merge commit `5545d6e2` resolving origin/main conflict, user report-template commit `89ded6a7`, and no reviewer step per user instruction.
