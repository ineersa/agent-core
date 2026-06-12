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
Fork run:
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
