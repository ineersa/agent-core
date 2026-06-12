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
Status: CODE-REVIEW
Branch: task/prompt-templates-02-runtime-cli-process-expansion
Worktree: /home/ineersa/projects/agent-core-worktrees/prompt-templates-02-runtime-cli-process-expansion
Fork run: 0qatpiqlzaue
PR URL: https://github.com/ineersa/agent-core/pull/136
PR Status: open
Started: 2026-06-12T03:25:41.905Z
Completed:

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
