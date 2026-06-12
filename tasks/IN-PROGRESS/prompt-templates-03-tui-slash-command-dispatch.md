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
Fork run: cqbtioutbwxy
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

## Task workflow update - 2026-06-12T18:16:07.889Z
- Validation: context: read task file and `.pi/plans/prompt-templates-implementation-plan.md` PT-03 sections; scout: TUI slash command architecture explored (`SlashCommandRegistry`, `CommandParser`, `DispatchRuntime`, `TuiListenerRegistrar`, `services.yaml` tagging); scout: SubmitListener dispatch flow explored; normal prompt dispatch has start/draft-promotion, post-shell restart, steer, follow_up, shared error handling; `DispatchRuntime` currently ignored by `applyCommandResult()`; scout: TUI E2E harness explored; mandatory test should create isolated `.hatfield/prompts/review.md`, launch real TUI with test LLM, type `/review <marker>`, and assert expanded prompt appears in transcript via TmuxHarness
- Summary: Claimed PT-03 and gathered implementation context. Worktree: `/home/ineersa/projects/agent-core-worktrees/prompt-templates-03-tui-slash-command-dispatch` on branch `task/prompt-templates-03-tui-slash-command-dispatch`. Note: integration checkout had unrelated untracked `.pi/plans/mcp-client-implementation-plan.md`; temporarily stashed/restored only to satisfy clean-checkout task claim. It remains untracked on main and is unrelated to PT-03.

## Task workflow update - 2026-06-12T18:26:47.924Z
- Recorded fork run: cqbtioutbwxy
- Validation: castor test --filter=PromptTemplateCommandRegistrarTest — OK (9 tests, 27 assertions); castor test --filter=SubmitListenerDispatchRuntimeTest — OK (11 tests, 47 assertions); LLM_MODE=true castor test:tui --filter=PromptTemplateSlashCommandE2ETest — OK (1 test, 1 assertion; real TmuxHarness + test LLM proof); castor test — OK (7 suites, 2486 tests, 0 failures); castor deptrac — OK (0 violations); castor phpstan — OK (0 errors); castor cs-check — OK (0 fixes); Note from fork: branch merge-base with origin/main is stale (`7e6e7b0f`); rebase before PR/code-review phase is recommended.
- Summary: Implementation fork completed and committed `c7adbed7` (`PT-03: TUI prompt-template slash command registration and DispatchRuntime forwarding`). Worktree clean on branch `task/prompt-templates-03-tui-slash-command-dispatch`. Diff stat against `origin/main...HEAD`: 6 files changed, 1002 insertions, 118 deletions. Implemented `PromptTemplateCommandRegistrar` with priority -100, priority-aware TUI listener iterator wiring, `SubmitListener` DispatchRuntime forwarding through shared runtime dispatch helper, unit tests for registrar and SubmitListener behavior, and mandatory TmuxHarness E2E proof for `/review <marker>` prompt template submission. Scope exclusions respected: no docs/PT-04 work, no built-ins, no CommandMetadata argument-hint changes, no runtime/client PT-02 changes.
