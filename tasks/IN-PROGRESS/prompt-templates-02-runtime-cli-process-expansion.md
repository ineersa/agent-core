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
Status: IN-PROGRESS
Branch: task/prompt-templates-02-runtime-cli-process-expansion
Worktree: /home/ineersa/projects/agent-core-worktrees/prompt-templates-02-runtime-cli-process-expansion
Fork run: 6dbfsfr0omnx
PR URL:
PR Status:
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
