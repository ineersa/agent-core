# PT-02 Runtime, CLI, and process transport prompt expansion

## Goal
Reference plan: `.pi/plans/prompt-templates-implementation-plan.md`.

Scope:
- Depends on PT-01.
- Expand prompt templates at the in-process runtime boundary so TUI, headless, controller, and process transport all see the same behavior.
- Add CLI flags `--prompt-template <path>` (repeatable) and `--no-prompt-templates` only; no `-np` shortcut.
- Pass prompt-template CLI overrides through `JsonlProcessAgentSessionClient` to the controller child.
- Runtime/model/transcript should follow normal runtime events showing the expanded prompt; do not build special raw `/template args` transcript behavior.

Can run in parallel with PT-03 after PT-01 lands.

## Acceptance criteria
- `InProcessAgentSessionClient::start()` expands initial prompts via concrete `PromptTemplateService` before constructing the user message.
- `InProcessAgentSessionClient::send()` expands only `message`, `steer`, and `follow_up` text; `answer_human` and `answer_tool_question` remain unchanged.
- `AgentCommand` supports repeatable `--prompt-template` and boolean `--no-prompt-templates`, populating `PromptTemplatesRuntimeConfig`; no short alias is implemented.
- `JsonlProcessAgentSessionClient` includes prompt-template CLI override args when spawning the controller child.
- Tests cover start/send expansion, non-template passthrough, single-pass expansion, answer command non-expansion, CLI option population, and process child argument pass-through.
- Because this touches LLM-visible runtime flow, `LLM_MODE=true castor check` must pass before moving this task to CODE-REVIEW; if prerequisites are unavailable, keep the task IN-PROGRESS and record the blocker.

## Workflow metadata
Status: DONE
Branch: task/prompt-templates-02-runtime-cli-process-expansion
Worktree: /home/ineersa/projects/agent-core-worktrees/prompt-templates-02-runtime-cli-process-expansion
Fork run: 0qatpiqlzaue
PR URL: https://github.com/ineersa/agent-core/pull/136
PR Status: merged
Started: 2026-06-12T03:25:41.905Z
Completed: 2026-06-12T16:52:10.609Z

## Work log
- Created: 2026-06-09T00:10:00.375Z

## Task workflow update - 2026-06-12T03:25:41.905Z
- Moved TODO → IN-PROGRESS.
- Created branch task/prompt-templates-02-runtime-cli-process-expansion.
- Created worktree /home/ineersa/projects/agent-core-worktrees/prompt-templates-02-runtime-cli-process-expansion.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/prompt-templates-02-runtime-cli-process-expansion.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/prompt-templates-02-runtime-cli-process-expansion.

## Task workflow update - 2026-06-12T03:34:32.799Z
- Summary: Claimed PT-02 after PT-01 landed. Worktree: `/home/ineersa/projects/agent-core-worktrees/prompt-templates-02-runtime-cli-process-expansion` on branch `task/prompt-templates-02-runtime-cli-process-expansion`. Loaded task-workflow/testing guidance, read task file and prompt-template implementation plan sections for PT-02 runtime/CLI/process scope.
- Scout findings: runtime expansion target is `src/CodingAgent/Runtime/InProcess/InProcessAgentSessionClient.php`. Inject concrete `PromptTemplateService`; expand `StartRunRequest::prompt` before user `AgentMessage`; expand only `UserCommand` types `message`, `steer`, `follow_up`; do not expand `answer_human`, `answer_tool_question`, or `shell_command`. Add `AppPromptTemplate` to `AppRuntimeInternals` deptrac rules.
- Scout findings: CLI target is `src/CodingAgent/CLI/AgentCommand.php`. Add constructor dependency `PromptTemplatesRuntimeConfig`, add Symfony `#[Option]` parameters `array $promptTemplate = []` and `bool $noPromptTemplates = false` with long options only, and populate mutable runtime config near the existing `SkillsConfig` population before controller/headless/TUI branches.
- Scout findings: process target is `src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php`. Inject `PromptTemplatesRuntimeConfig` and append `...$promptTemplatesRuntimeConfig->controllerArgs()` to spawned controller argv after `agent --controller --cwd=<runtimeCwd>`. `PromptTemplatesRuntimeConfig::controllerArgs()` already exists. Add `AppPromptTemplate` to `AppCli` and `AppRuntimeInternals` deptrac rules. Suggested tests: in-process expansion test, AgentCommand option acceptance/population test, process argv pass-through test with fake executable/controller script.

## Task workflow update - 2026-06-12T16:14:25.128Z
- Recorded fork run: 6dbfsfr0omnx
- Validation: review-fix fork: `castor test --filter=PromptTemplateExpansionInProcessTest` OK (11 tests, 25 assertions); review-fix fork: `castor test --filter=AgentCommandPromptTemplatesOptionsTest` OK (8 tests, 11 assertions); review-fix fork: `castor phpstan` OK (0 errors); review-fix fork: `castor cs-fix` OK (files_fixed=0); review-fix fork: `castor cs-check` OK (files_fixed=0); orchestrator: `git status --short --branch` clean on `task/prompt-templates-02-runtime-cli-process-expansion`; `git diff --stat origin/main...HEAD` shows 9 files changed, 685 insertions, 6 deletions
- Summary: Review-fix fork completed and committed `7ee60fc3ff4e0d33826b931e04bbc51b5872f633`. Addressed reviewer suggestions: updated stale `PromptTemplatesRuntimeConfig` docblock; added test-only `IsolatedKernelTestCase::isolatedCwd()` accessor to remove reflection from `PromptTemplateExpansionInProcessTest`; added `send()` non-matching slash passthrough coverage; added `shell_command` non-expansion coverage via local fake `ToolExecutorInterface` spy. Worktree clean at HEAD `7ee60fc3`.

## Task workflow update - 2026-06-12T16:46:46.640Z
- Recorded fork run: 0qatpiqlzaue
- Validation: reviewer: initial review `APPROVE WITH SUGGESTIONS`; review-fix fork addressed all suggestions; reviewer re-review: `REQUEST CHANGES` solely due stale branch base causing apparent SESSION-05/usage-fix regressions; resolved by rebase onto `origin/main` without another reviewer per user instruction; orchestrator: `git status --short --branch` clean on task branch; orchestrator: HEAD `b38a2256ac0af0f33d07c30fd1f65ca1d67710e1`; merge-base with `origin/main` `ff7e2bcc294a528c54e1389cf5bedaef99482619`; orchestrator: `git diff --stat origin/main...HEAD` shows 9 files changed, 685 insertions, 6 deletions, limited to PT-02 files; orchestrator: `castor test` OK (7 suites: agent-core 292/1263, coding-agent-1 286/816, coding-agent-2 382/990, coding-agent-3 413/1112, coding-agent-4 375/1114, tui 664/1660, platform 54/221; 0 failures); orchestrator: `castor deptrac` OK (violations=0, errors=0); orchestrator: `castor phpstan` OK (errors=0, file_errors=0); orchestrator: `castor cs-check` OK (files_fixed=0)
- Summary: Prepared for CODE-REVIEW per user instruction after resolving reviewer blocker without another reviewer pass. Latest reviewer had `REQUEST CHANGES` only because branch base was stale and appeared to revert SESSION-05/usage-fix main changes. Rebase/fix fork updated task branch onto current `origin/main`; orchestrator confirmed worktree clean at rebased HEAD `b38a2256ac0af0f33d07c30fd1f65ca1d67710e1`, merge-base with `origin/main` is `ff7e2bcc294a528c54e1389cf5bedaef99482619`, and `git diff --stat origin/main...HEAD` is limited to 9 PT-02 files (runtime/CLI/process prompt-template files, depfile, and tests). No turn-tree/session-storage deletions remain.
Castor Check Status: passed
Castor Check Commit: b38a2256ac0af0f33d07c30fd1f65ca1d67710e1
Castor Check Command: LLM_MODE=true castor check
Castor Check Timeout: 900s
Castor Check Completed: 2026-06-12T16:48:02.495Z
Castor Check Output SHA256: 2356297ba878ae7cec9618b9ba9a5b8f7ce672fad2397f8b1082de53db1be694

## Task workflow update - 2026-06-12T16:48:06.587Z
- Moved IN-PROGRESS → CODE-REVIEW.
- Castor quality gate passed (900s timeout). Commit: b38a2256ac0a.
- Pushed task/prompt-templates-02-runtime-cli-process-expansion to origin.
- branch 'task/prompt-templates-02-runtime-cli-process-expansion' set up to track 'origin/task/prompt-templates-02-runtime-cli-process-expansion'.
- Created PR: https://github.com/ineersa/agent-core/pull/136
- Validation: pre-PR local: `castor test` OK (7 suites, 0 failures); pre-PR local: `castor deptrac` OK (violations=0, errors=0); pre-PR local: `castor phpstan` OK (errors=0, file_errors=0); pre-PR local: `castor cs-check` OK (files_fixed=0)
- Summary: PT-02 prepared for code review. Runtime/CLI/process prompt-template expansion implemented and rebased onto current origin/main. Local validation passed: castor test, deptrac, phpstan, cs-check. Reviewer suggestions addressed; stale-base blocker resolved by rebase per user instruction without another reviewer pass.

## Task workflow update - 2026-06-12T16:52:10.610Z
- Moved CODE-REVIEW → DONE.
- Merged task/prompt-templates-02-runtime-cli-process-expansion into integration checkout.
- Merge made by the 'ort' strategy.
 depfile.yaml                                       |   2 +
 src/CodingAgent/CLI/AgentCommand.php               |  18 ++
 .../PromptTemplatesRuntimeConfig.php               |   4 +-
 .../InProcess/InProcessAgentSessionClient.php      |  24 +-
 .../Process/JsonlProcessAgentSessionClient.php     |   3 +
 .../CLI/AgentCommandPromptTemplatesOptionsTest.php | 111 +++++++
 .../PromptTemplateExpansionInProcessTest.php       | 325 +++++++++++++++++++++
 .../JsonlProcessPromptTemplateOptionsTest.php      | 194 ++++++++++++
 .../TestCase/IsolatedKernelTestCase.php            |  10 +
 9 files changed, 685 insertions(+), 6 deletions(-)
 create mode 100644 tests/CodingAgent/CLI/AgentCommandPromptTemplatesOptionsTest.php
 create mode 100644 tests/CodingAgent/Runtime/InProcess/PromptTemplateExpansionInProcessTest.php
 create mode 100644 tests/CodingAgent/Runtime/Process/JsonlProcessPromptTemplateOptionsTest.php
- Removed worktree /home/ineersa/projects/agent-core-worktrees/prompt-templates-02-runtime-cli-process-expansion.
- Pulled integration checkout: Merge made by the 'ort' strategy..
- Validation: pre-DONE: `gh pr view https://github.com/ineersa/agent-core/pull/136 --json state,mergeCommit,mergedAt` reported state MERGED, mergedAt 2026-06-12T16:51:31Z, mergeCommit 3c4a3b2714c53c2f5a38da9fff2eb3c89c5278ab
- Summary: PR #136 was already merged on GitHub at 2026-06-12T16:51:31Z (merge commit 3c4a3b2714c53c2f5a38da9fff2eb3c89c5278ab). Completing tracked task and syncing integration checkout.

## Task workflow update - 2026-06-12T16:53:42.781Z
- Updated PR Status: merged
- Validation: post-merge: `LLM_MODE=true castor check` OK; post-merge: PHAR rebuilt and smoke tests OK; post-merge: deptrac OK (1.3s); post-merge: test-agent-core OK (292 tests, 1263 assertions); post-merge: test-coding-agent-1 OK (286 tests, 816 assertions); post-merge: test-coding-agent-2 OK (382 tests, 990 assertions); post-merge: test-coding-agent-3 OK (413 tests, 1112 assertions); post-merge: test-coding-agent-4 OK (375 tests, 1114 assertions); post-merge: test-tui-suite OK (664 tests, 1660 assertions); post-merge: test-platform OK (54 tests, 221 assertions); post-merge: test:controller OK (1 test, 7 assertions); post-merge: test:llm-real OK (5 tests, 51 assertions); post-merge: test:tui OK (16 tests, 51 assertions); post-merge: phpstan OK (0 errors, 0 file_errors); post-merge: cs-check OK; post-merge: quality OK; cleanup: `/home/ineersa/projects/agent-core-worktrees/prompt-templates-02-runtime-cli-process-expansion` removed; cleanup: `git status --short --branch` clean on `main...origin/main`
- Summary: Post-merge completion validation passed. PR #136 was merged and task branch was merged/synced into integration checkout; worktree cleanup completed. Integration checkout is clean and synced with origin/main at `6cc5dc70`.
