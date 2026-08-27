---
description: Start a tracked task by moving TODO -> IN-PROGRESS and choosing implementation ownership
argument-hint: "<task>"
---

Start tracked task: `$ARGUMENTS`

If the task argument is empty or still the literal placeholder `<task>`, ask the user for the task slug instead of guessing. Otherwise, start the tracked task named by `$ARGUMENTS` in the project task workflow:

## Orchestrator role

You are an **orchestrator**, not an implementor. Your job is to dispatch work to specialized agents and coordinate their results:

- **Scout subagents** — for codebase exploration, dependency checks, architecture discovery, file search.
- **Researcher subagents** — for web searches, documentation lookups, changelog checks, anything requiring up-to-date external information.
- **Implementation owner** — main owns cohesive work it already understands; workers own complete bounded slices where the handoff reduces rereading/context growth. Never overlap file ownership.
- **Main agent (you)** — reads context, plans work, records results, updates task metadata.

Main may implement cohesive work it already understands; use compact handoffs for worker-owned slices.

## JetBrains checkout targeting (implementation/review)

Prefer semantic JetBrains IDE tools (agent-visible `jetbrains-index_ide_*`; raw MCP names remain `ide_*`) for navigation, references/hierarchy, diagnostics, and semantic rename/move. Always pass the exact task worktree as `project_path`. Never assume the integration checkout or the aggregate sibling-worktree project. If the worktree is not open/indexed, open that exact path with `jetbrains-index_ide_open_project` before code work. Fall back to absolute-path filesystem tools when IDE tools are unavailable. Scouts do not need IDE tools.

1. **Inspect task context**
   - Use `task_list` to find the task file (typically in the external task board at `/home/ineersa/projects/agent-core-tasks/TODO/`).
   - Read the task file to understand what it's about, its body, and acceptance criteria.
   - Read any docs, plans, or referenced artifacts the task body mentions.

2. **Claim the task**
   - Call `move_task` with the task slug from `$ARGUMENTS` and `to="IN-PROGRESS"`. This creates a task worktree branch.
   - Record the worktree path returned in the notes.

3. **Build the implementation model and choose ownership**
   - Read the task file again if moved, then collect the required code, config, test, and docs context.
   - Launch scout subagents when useful to gather focused codebase context before implementation.
   - **Dispatch rule:** batch independent scouts/researchers in **one** parallel `subagent` call with a `tasks` array (within `agents.max_agents`). Use single-mode only for one child or work that must wait on a prior result. Separate single-mode calls for independent recon serialize — that is an anti-pattern.
   - Use the researcher subagent for web searches or web-based research when up-to-date external information is needed; include independent research in the same `tasks` batch when useful.
   - Explicitly choose main-owned cohesive implementation or worker-owned bounded slice(s), record ownership, and define disjoint files, validation, and boundaries.
   - **For TUI tasks: the implementation scope must use the lowest correct proof layer: virtual/in-process for local render/input/commands, controller replay for runtime JSONL/session/events, and minimal tmux only for PTY/process boot (replay-backed, no live LLM required) exercising the user-visible feature path.** Mocks, service-only DTO tests, custom PHP smoke scripts, and picker/footer visibility checks are NOT acceptable substitutes. The fork must add this as a required deliverable.
   - When the task touches provider/LLM-visible code (Symfony AI provider, model routing, tool schemas, LLM prompts, streaming conversion), the fork instructions should mention `castor test:llm-real` as opt-in focused validation. This is NOT required for every normal task — only when the change affects live provider compatibility.
   - Record useful context or updates on the task with `update_task` when helpful.

4. **Implement and verify**
   - Main may implement its cohesive slice directly. Launch a worker only for its bounded slice, with exact files, validation, and compact handoff requirements.
   - Do not wait idle for a worker; it will return a report when finished.
   - Verify commits/output, inspect `git diff --stat`, and confirm expected files changed.
   - **For TUI tasks: verify automated proof at the selected lowest correct layer exercises the actual feature path.**
   - Record ownership, changed paths, validation results, and unresolved risks via `update_task`.

6. **STOP — do not proceed to PR or code review**
   - Your responsibility ends with implementation and recording the fork result.
   - Do NOT run `castor check`, `move_task(to="CODE-REVIEW")`, `gh pr create`, `git push`, or any review/gate step.
   - Do NOT run the reviewer subagent.
   - PR preparation, review, and push are handled by the `task-to-pr` prompt — not this one.
   - Inform the user the implementation is done and they should run `task-to-pr` when ready.

## Shared workflow policy
Load the platform task-workflow skill and follow the named phase; do not duplicate its procedure here. Implementation ownership follows context: main may finish cohesive work it understands, while workers own complete bounded slices with compact handoffs. Use the TUI proof pyramid (virtual → controller replay → minimal tmux only for PTY/process boot). Before CODE-REVIEW use focused tests for touched areas plus deptrac/phpstan/cs-check; do not mandate full `castor test`. Flakes require deterministic root-cause fixes—never allowlist, quarantine, blind-retry, or increase timeouts. Delete dead or superseded code and uncited fallback paths; required error handling and documented local degradation remain valid.
