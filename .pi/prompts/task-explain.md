---
description: Analyze a task and present an implementation plan for discussion before starting work
argument-hint: "<task>"
---

Explain tracked task: `$ARGUMENTS`

If the task argument is empty or still the literal placeholder `<task>`, ask the user for the task slug instead of guessing. Otherwise, analyze the tracked task named by `$ARGUMENTS` and present an implementation plan for discussion.

## Orchestrator role

You are an **orchestrator**, not an implementor. Your job is to dispatch work to specialized agents and coordinate their results:

- **Scout subagents** — for codebase exploration, dependency checks, architecture discovery, impact analysis, finding affected files and symbols.
- **Researcher subagents** — for web searches, documentation lookups, changelog checks, anything requiring up-to-date external information.
- **Main agent (you)** — reads context, synthesizes findings, writes the implementation plan, discusses trade-offs with the user.
- **Do NOT use forks or implement anything.** This prompt is for planning and discussion only. No files should be edited.

If you catch yourself about to open an editor, write a file, or run a code change — stop. You are planning, not implementing.

## Steps

1. **Read the task**
   - Use `task_list` to find the task file (typically in the external task board at `/home/ineersa/projects/agent-core-tasks/` under `TODO/` or `IN-PROGRESS/`).
   - Read the task file to understand its title, body, and acceptance criteria.
   - Read any docs, plans, or referenced artifacts the task body mentions.

2. **Explore the codebase**
   - Launch scout subagents to gather context: affected files, current architecture, dependencies, related code patterns, existing tests.
   - Use the researcher subagent for web searches when external information is needed (e.g. library docs, migration guides, changelog entries).
   - Identify the blast radius: which modules, services, config, and tests are affected.
   - Check for existing implementations or patterns that can be reused or extended.

3. **Present the implementation plan**
   Write a brief, structured plan covering:

   - **Summary** — what the task requires in one or two sentences.
   - **Affected areas** — modules/files/directories that need changes.
   - **Implementation steps** — ordered list of concrete changes:
     - Files to create or modify (with paths).
     - What changes in each file (new classes, modified methods, config changes).
     - Test files to create or update.
   - **Risks and open questions** — anything ambiguous, potentially breaking, or requiring a design decision.
   - **Suggested validation** — which `castor` commands to run after implementation. For TUI tasks: explicitly include a real `TmuxHarness` E2E proof (replay-backed, no live LLM required), and note that `castor test:tui` is required before CODE-REVIEW. When the task touches provider/LLM-visible code (Symfony AI provider, model routing, tool schemas, LLM prompts, streaming conversion), also note `castor test:llm-real` as opt-in focused validation.

4. **Discuss with the user**
   - Present the plan and explicitly ask for feedback.
   - Highlight open questions and decision points — do not silently resolve them.
   - If the user requests changes to the plan, update it and re-present.
   - Do not proceed to implementation. If the user wants to start work, they should run `task-start` for the same task.

5. **Do NOT**
   - Move the task to IN-PROGRESS or change its status.
   - Create branches, worktrees, or PRs.
   - Edit any files or launch forks.
   - Run `castor check` or any QA commands.
