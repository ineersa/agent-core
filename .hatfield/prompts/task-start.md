---
description: Start a tracked task by moving TODO -> IN-PROGRESS and launching a fork
argument-hint: "<task>"
---

Start tracked task: `$ARGUMENTS`

If the task argument is empty or still the literal placeholder `<task>`, ask the user for the task slug instead of guessing. Otherwise, start the tracked task named by `$ARGUMENTS` in the project task workflow:

## Orchestrator role

You are an **orchestrator**, not an implementor. Your job is to dispatch work to specialized agents and coordinate their results:

- **Scout subagents** — for codebase exploration, dependency checks, architecture discovery, file search.
- **Researcher subagents** — for web searches, documentation lookups, changelog checks, anything requiring up-to-date external information.
- **Fork (tool)** — for ALL implementation work: editing files, writing code, fixing tests, updating configs. You MUST use a fork for any file modification. Never edit files directly in the main agent.
- **Main agent (you)** — reads context, plans work, writes fork instructions, records results, updates task metadata.

If you catch yourself about to open an editor, write a file, or run a code change — stop and launch a fork instead.

1. **Inspect task context**
   - Use `task_list` to find the task file (typically in the external task board at `/home/ineersa/projects/agent-core-tasks/TODO/`).
   - Read the task file to understand what it's about, its body, and acceptance criteria.
   - Read any docs, plans, or referenced artifacts the task body mentions.

2. **Claim the task**
   - Call `move_task` with the task slug from `$ARGUMENTS` and `to="IN-PROGRESS"`. This creates a task worktree branch.
   - Record the worktree path returned in the notes.

3. **Prepare exact fork instructions**
   - Read the task file again if moved, then collect the required code, config, test, and docs context.
   - Launch scout subagents when useful to gather focused codebase context before implementation.
   - **Dispatch rule:** batch independent scouts/researchers in **one** parallel `subagent` call with a `tasks` array (within `agents.max_agents`). Use single-mode only for one child or work that must wait on a prior result. Separate single-mode calls for independent recon serialize — that is an anti-pattern.
   - Use the researcher subagent for web searches or web-based research when up-to-date external information is needed; include independent research in the same `tasks` batch when useful.
   - Create exact implementation instructions for the fork: files to touch, old/new patterns, validation commands, and boundaries.
   - **For TUI tasks: the implementation scope MUST include a real `TmuxHarness` E2E proof (replay-backed, no live LLM required) exercising the user-visible feature path.** Mocks, service-only DTO tests, custom PHP smoke scripts, and picker/footer visibility checks are NOT acceptable substitutes. The fork must add this as a required deliverable.
   - When the task touches provider/LLM-visible code (Symfony AI provider, model routing, tool schemas, LLM prompts, streaming conversion), the fork instructions should mention `castor test:llm-real` as opt-in focused validation. This is NOT required for every normal task — only when the change affects live provider compatibility.
   - Record useful context or updates on the task with `update_task` when helpful.

4. **Launch a fork**
   - Launch a single fork on the task worktree with `cwd` set to the worktree directory.
   - Include the exact implementation plan as the fork task, with file paths, edit patterns, and required validation.
   - Do NOT implement directly — the fork implements.
   - Do not wait idle for the fork; it will return a report when finished.

5. **Handle fork report**
   - When the fork report arrives, verify the commit exists, inspect `git diff --stat`, and confirm the expected files changed.
   - **For TUI tasks: verify the fork added a real `TmuxHarness` E2E proof (replay-backed, no live LLM required) exercising the actual feature path.** If the fork only reports abstract validations or mock-based tests, reject it and re-launch with explicit E2E test requirements.
   - Record fork run id, summary, and validation results via `update_task`.
   - If the fork failed or produced unacceptable output, re-launch with narrower instructions.

6. **STOP — do not proceed to PR or code review**
   - Your responsibility ends with implementation and recording the fork result.
   - Do NOT run `castor check`, `move_task(to="CODE-REVIEW")`, `gh pr create`, `git push`, or any review/gate step.
   - Do NOT run the reviewer subagent.
   - PR preparation, review, and push are handled by the `task-to-pr` prompt — not this one.
   - Inform the user the implementation is done and they should run `task-to-pr` when ready.
