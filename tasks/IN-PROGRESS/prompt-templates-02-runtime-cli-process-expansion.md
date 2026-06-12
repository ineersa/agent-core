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
Fork run: z6u6x8tpitlr
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

## Task workflow update - 2026-06-12T04:04:26.895Z
- Recorded fork run: z6u6x8tpitlr
- Validation: fork: `castor test --filter=PromptTemplateExpansionInProcessTest` OK (9 tests, 21 assertions); fork: `castor test --filter=AgentCommandPromptTemplatesOptionsTest` OK (8 tests, 11 assertions); fork: `castor test --filter=JsonlProcessPromptTemplateOptionsTest` OK (4 tests, 28 assertions); fork: `castor test` OK (7 suites, 2,441 tests, 0 failures); fork: `castor deptrac` OK (violations=0, errors=0); fork: `castor phpstan` OK (errors=0, file_errors=0); fork: `castor cs-fix` then `castor cs-check` OK (files_fixed=0); orchestrator: `git status --short` clean; `git diff --stat origin/main...HEAD` shows 7 files changed, 630 insertions, 4 deletions
- Summary: Implementation fork completed and committed `49fb395d2c18c6ff48001144e82addda64fc6c8d` on branch `task/prompt-templates-02-runtime-cli-process-expansion`. Worktree is clean. Implemented PT-02 runtime/CLI/process scope: in-process prompt-template expansion for start/message/steer/follow_up only; CLI options `--prompt-template` (repeatable) and `--no-prompt-templates` (long-only); JSONL process child argv pass-through via `PromptTemplatesRuntimeConfig::controllerArgs()`; deptrac AppPromptTemplate allowances for CLI/runtime internals. Added tests for in-process expansion, AgentCommand prompt-template options, and JsonlProcess argv pass-through. No PT-03 TUI slash command registration, SubmitListener changes, docs, or TUI E2E were implemented.
