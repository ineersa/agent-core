---
name: task-workflow
description: "Step-by-step procedures for each task workflow phase. Load this skill when: starting any task phase (task-start, task-to-pr, task-review-iterate, task-done), after compaction when you see `<current_task>` and need to recall exact steps, preparing fork instructions, or running reviewer workflows. Covers orchestrator model, phase procedures, and compaction resilience."
---

# Task Workflow Procedures

## Orchestrator model

The main agent is an **orchestrator**, not an implementor. Work is dispatched to specialized agents:

| Agent | Use for |
|---|---|
| **Scout subagents** | Codebase exploration, dependency checks, architecture discovery, impact analysis |
| **Researcher subagents** | Web searches, documentation lookups, changelog checks |
| **Fork (tool)** | ALL implementation work — editing files, writing code, fixing tests, updating configs |
| **Main agent (you)** | Reads context, plans work, writes fork instructions, records results, updates task metadata |

**Never edit files directly in the main agent.** If you catch yourself about to open an editor, write a file, or run a code change — launch a fork instead.

## Workflow phases

```
task-explain → task-start → task-to-pr → task-done
 (discuss)     (implement)  (review+PR)  (merge)
                  ↕
            task-review-iterate
              (address feedback)
```

### task-explain: Discuss before implementing

Read-only planning. No status changes, no file edits, no forks.

1. Read task file and referenced docs.
2. Scout codebase for affected areas, dependencies, existing patterns.
3. Researcher for external info when needed.
4. Present structured plan: summary, affected areas, implementation steps, risks/open questions, suggested validation.
5. Discuss with user. Highlight decision points — do not silently resolve them.
6. When ready to implement, user runs `task-start`.

### task-start: Implement (TODO → IN-PROGRESS)

1. `move_task(to="IN-PROGRESS")` — creates worktree branch.
2. Scout codebase for context, researcher for external info.
3. Prepare exact fork instructions: files to touch, old/new patterns, validation commands, boundaries.
4. Launch fork on worktree (`cwd=worktree`). Fork implements, you don't.
5. When fork report arrives:
   - Verify commit exists, inspect `git diff --stat`, confirm expected files changed.
   - Record fork run id, summary, validation results via `update_task`.
   - If fork failed or produced unacceptable output → re-launch with narrower instructions.
6. **STOP.** Do not proceed to PR or code review.
   - Do NOT run: `castor check`, `move_task(to="CODE-REVIEW")`, `gh pr create`, `git push`, reviewer subagent.
   - Inform user implementation is done. They run `task-to-pr` when ready.

### task-to-pr: Review and create PR (IN-PROGRESS → CODE-REVIEW)

1. Inspect worktree state: `git status`, `git log`, `git diff --stat origin/main...HEAD`.
2. Run reviewer subagent on worktree (`subagent agent="reviewer" cwd=worktree`).
   - If REQUEST CHANGES → analyze blockers, fork fixes, re-review. Repeat until APPROVED.
3. Run focused local validation on worktree:
   - `castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check`.
   - Do NOT run `LLM_MODE=true castor check` — `move_task(to="CODE-REVIEW")` runs the full gate automatically.
4. Record reviewer decision, commit sha, validation results via `update_task`.
5. `move_task(to="CODE-REVIEW")` — runs Castor quality gate, pushes branch, creates PR.
   - If gate fails → task stays IN-PROGRESS. Fork fixes, retry.

### task-review-iterate: Address PR feedback (CODE-REVIEW → IN-PROGRESS → CODE-REVIEW)

1. Read all PR comments via `gh pr view`. Classify blockers vs nice-to-have.
2. `move_task(to="IN-PROGRESS")` before any implementation.
3. Prepare exact fork instructions covering each actionable comment.
4. Fork fixes on worktree. Verify output, run focused Castor validation.
5. Re-review with reviewer subagent. If REQUEST CHANGES → repeat from step 3.
6. When APPROVED → `move_task(to="CODE-REVIEW")` (reruns full Castor gate before push).
7. Record decisions, commit sha, reviewer result via `update_task`.

### task-done: Merge approved PR (CODE-REVIEW → DONE)

1. Confirm PR is approved/merged on GitHub.
2. `move_task(to="DONE")` — merges task branch into integration checkout, runs `git pull`.
   - If merge conflicts → task stays CODE-REVIEW. Do not force.
3. Post-merge validation: `LLM_MODE=true castor check` on integration checkout.
   - If prerequisites unavailable: `castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check`.
4. Record validation results via `update_task`.
5. Clean up: confirm `git status` clean, verify worktree removed.

## Compaction resilience

After compaction, the `task-workflow` extension reinjects `<current_task>` as a custom message containing:
- Active task identity (status, branch, worktree, PR URL)
- Acceptance criteria and recent work log
- Orchestrator role reminder
- Phase-specific STOP boundary

This ensures the model stays oriented after context compression. Full procedures (this file) live in the `task-workflow` skill and prompt files. The injection only provides identity and boundaries — load this skill when you need exact workflow steps.
