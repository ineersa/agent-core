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
Status: IN-PROGRESS
Branch: task/prompt-templates-03-tui-slash-command-dispatch
Worktree: /home/ineersa/projects/agent-core-worktrees/prompt-templates-03-tui-slash-command-dispatch
Fork run:
PR URL:
PR Status:
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
