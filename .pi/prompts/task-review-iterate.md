---
description: Respond to PR review comments with analysis, implementation iteration, and re-review
argument-hint: "<task-or-pr>"
---

Address code review feedback for task or PR: `$ARGUMENTS`

If the argument is empty or still the literal placeholder `<task-or-pr>`, ask the user for the task slug or PR URL/number instead of guessing. Otherwise, address code review feedback for the task or PR identified by `$ARGUMENTS`:

## Orchestrator role

You are an **orchestrator**, not an implementor. Your job is to dispatch work to specialized agents and coordinate their results:

- **Reviewer subagent** — for re-review after fixes are applied.
- **Researcher subagents** — for web searches, external docs referenced in review comments.
- **Implementation owner** — main or worker owns a cohesive slice based on context; delegate only bounded work that reduces rereading. Do not overlap file ownership.
- **Main agent (you)** — reads PR comments, classifies feedback, prepares fork instructions, verifies output, moves task state.

Main may implement cohesive work it already understands; use compact handoffs for worker-owned slices.

## JetBrains checkout targeting (implementation/review)

Prefer semantic JetBrains IDE tools (`ide_*`) for navigation, references/hierarchy, diagnostics, and semantic rename/move. Always pass the exact task worktree as `project_path`. Never assume the integration checkout or the aggregate sibling-worktree project. If the worktree is not open/indexed, open that exact path with `ide_open_project` before code work. Fall back to absolute-path filesystem tools when IDE tools are unavailable. Scouts do not need IDE tools.

1. **Read all PR comments and task metadata**
   - Use `gh pr view <number> --comments` or the task's PR URL from task metadata.
   - Read the task file (usually in the external task board at `/home/ineersa/projects/agent-core-tasks/CODE-REVIEW/`) to retrieve worktree
     path, PR URL, and other metadata needed for the iteration.
   - Read every inline review comment — do not guess or summarize from memory.
   - Identify the task slug from the PR branch name (pattern: `task/<slug>`).

2. **Move back to IN-PROGRESS before implementation**
   - If the task is in CODE-REVIEW, call `move_task` with `to="IN-PROGRESS"` before launching implementation work.
   - Use the existing task worktree from metadata. If it is missing, recreate or recover it before implementation.

3. **Classify feedback**
   - Classify findings by the reviewer verdict rubric: CRITICAL, BUG, SEC, missing proof, dead code, and uncited fallback paths block; NTH, naming, and pure micro-shrinks are suggestions unless correctness is affected.
   - Address blockers; record or optionally apply non-blocking suggestions without preventing approval.
   - Note which comments intersect with already-identified issues on the task.
   - If a comment references external docs (e.g. Doctrine release notes, Symfony changelog), read those too.
   - Use the researcher subagent for web searches or web-based research when up-to-date external information is needed.

4. **Choose ownership and implement**
   - Choose main-owned cohesive fixes or worker-owned bounded slices; specify disjoint files, validation, and limits of authority.
   - **For TUI tasks: require proof at the selected lowest correct layer for the affected feature path.**
   - Main may implement cohesive fixes directly; delegate only bounded slices that reduce rereading.

5. **Verify implementation output**
   - Confirm commits/output and inspect `git diff --stat HEAD~1` or `git show --stat HEAD`.
   - Run focused Castor validation: `castor test --filter=...`, `castor deptrac`, `castor phpstan`, `castor cs-check`.
   - **For TUI tasks: run controller replay or `castor test:tui` only when that selected proof layer is required.**
   - **When changes touch provider/LLM-visible code (Symfony AI provider, model routing, tool schemas, LLM prompts, streaming conversion), also run `castor test:llm-real` as opt-in focused validation.** This is NOT required for every normal task.
   - Verify no unintended changes (only the advertised files changed).

7. **Re-review**
   - Run the reviewer subagent again on the worktree at the new HEAD.
   - **For TUI tasks: instruct the reviewer to verify proof at the lowest correct layer (virtual/in-process, controller replay, or minimal tmux only for PTY/process boot) exists and covers the user-visible feature path.** Reject the iteration if it lacks this proof or substitutes mocks.
   - If REQUEST CHANGES again, repeat from step 4 with the new feedback.

8. **Move back to CODE-REVIEW**
   - When APPROVED, call `move_task` with `to="CODE-REVIEW"`. This automatically runs deterministic `castor check` in the worktree, then pushes the branch and creates/updates the PR.

9. **Update task**
   - Use `update_task` to record decisions, commit sha, reviewer result, and validation.
   - Append work log entries for each iteration.

## Shared workflow policy
Load the platform task-workflow skill and follow the named phase; do not duplicate its procedure here. Implementation ownership follows context: main may finish cohesive work it understands, while workers own complete bounded slices with compact handoffs. Use the TUI proof pyramid (virtual → controller replay → minimal tmux only for PTY/process boot). Before CODE-REVIEW use focused tests for touched areas plus deptrac/phpstan/cs-check; do not mandate full `castor test`. Flakes require deterministic root-cause fixes—never allowlist, quarantine, blind-retry, or increase timeouts. Delete dead or superseded code and uncited fallback paths; required error handling and documented local degradation remain valid.
