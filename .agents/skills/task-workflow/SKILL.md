---
name: task-workflow
description: "Step-by-step procedures for each task workflow phase. Load this skill when: starting any task phase (task-start, task-to-pr, task-review-iterate, task-done), preparing fork instructions, or running reviewer workflows. Covers orchestrator model, phase procedures, and compaction resilience."
---

# Task Workflow Procedures

## Task board location

Tasks live in an **external task board directory** separate from the code repo.

- **Code repo:** `/home/ineersa/projects/agent-core` — git operations (branches, worktrees, PRs)
- **Task board:** `/home/ineersa/projects/agent-core-tasks` — task markdown files

The task board root is configured via `.pi/settings.json`→`taskWorkflow.taskRoot`, or overridden by the `PI_TASK_WORKFLOW_ROOT` environment variable.

**Task status/metadata moves do NOT commit to the agent-core code repo.**
Task board changes are git-auto-detected in the external task repo but not auto-committed,
preventing code-branch pollution. The user commits task board changes manually when desired.

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

#

**Leaked QA workers:** `castor check` does not auto-kill. Survivors are lifecycle bugs — fix teardown at root cause; do not kill as routine before retry. Use `castor clean:cleanup:workers:list` for diagnostics; `castor clean:cleanup:workers` only as explicit last resort after investigation.
## task-start: Implement (TODO → IN-PROGRESS)

1. `move_task(to="IN-PROGRESS")` — creates worktree branch.
   - Worktree creation copies `vendor/` and `.vera/` into the worktree, and updates the parent worktree IDEA module exclusions when present.
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

**Validation from an active Hatfield session:** Full `castor check` in the integration checkout is safe after the Castor stale-worker guard (active session consumers with `HATFIELD_SESSION_ID` are not killed). For task branches, prefer running gates in the task worktree to avoid competing with a live session in the same tree.

**Note on task board changes:** Task metadata/status updates modify files in the external task board
(`/home/ineersa/projects/agent-core-tasks/`). These changes are NOT committed to the agent-core code
repo. The external task board repo must be committed manually when desired.

**Worktree IDEA exclusions:** When creating a worktree, the extension updates the parent worktree IDEA module (e.g., `agent-core-worktrees.iml`) by adding an idempotent sentinel block of `<excludeFolder>` entries for the new worktree. The excluded directories (`.hatfield`, `.vera`, `var`, `vendor`, etc.) prevent IDEA from indexing generated content in the worktree. On DONE cleanup, these exclusions are automatically removed. No per-worktree `.idea/` directory is created or modified.

**TUI behavior proof for implementation:** For tasks touching TUI behavior, the fork MUST add or update automated proof at the **lowest correct layer** (virtual/in-process, controller-replay, or minimal tmux — see pyramid below). Fork instructions must state the test thesis and layer. Mocks, service-only DTO tests, custom PHP smoke scripts, and picker/footer visibility assertions are NOT acceptable as the only proof. See `## TUI behavior proof requirement` below.

### task-to-pr: Review and create PR (IN-PROGRESS → CODE-REVIEW)

1. Inspect worktree state: `git status`, `git log`, `git diff --stat origin/main...HEAD`.
2. Run reviewer subagent on worktree (`subagent agent="reviewer" cwd=worktree`).
   - If REQUEST CHANGES → analyze blockers, fork fixes, re-review. Repeat until APPROVED.
3. Run focused local validation on worktree:
   - `castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check`.
   - For TUI tasks: also run `castor test:tui` as part of local validation.
   - When changes touch provider/LLM-visible code (Symfony AI provider, model routing, tool schemas, LLM prompts, streaming conversion), also run `castor test:llm-real` as opt-in focused validation. This is NOT required for every normal task — only when the change affects live provider compatibility path.
   - The orchestrator/user is responsible for focused validation before moving to CODE-REVIEW. `move_task(to="CODE-REVIEW")` automatically runs deterministic `castor check` in the worktree before pushing and creating the PR.
4. Record reviewer decision, commit sha, validation results via `update_task`.
5. `move_task(to="CODE-REVIEW")` — runs castor check in worktree, verifies it is clean, pushes branch, creates PR.

### task-review-iterate: Address PR feedback (CODE-REVIEW → IN-PROGRESS → CODE-REVIEW)

1. Read all PR comments via `gh pr view`. Classify blockers vs nice-to-have.
2. `move_task(to="IN-PROGRESS")` before any implementation.
3. Prepare exact fork instructions covering each actionable comment.
4. Fork fixes on worktree. Verify output, run focused Castor validation.
5. Re-review with reviewer subagent. If REQUEST CHANGES → repeat from step 3.
6. When APPROVED → `move_task(to="CODE-REVIEW")` (pushes branch, creates/updates PR).
7. Record decisions, commit sha, reviewer result via `update_task`.

### task-done: Merge approved PR (CODE-REVIEW → DONE)

1. Confirm PR is approved/merged on GitHub.
2. `move_task(to="DONE")` — merges task branch into integration checkout, runs `git pull`.
   - If merge conflicts → task stays CODE-REVIEW. Do not force.
3. Post-merge validation: `LLM_MODE=true castor check` on integration checkout.
   - If prerequisites unavailable: `castor test`, `castor deptrac`, `castor phpstan`, `castor cs-check`.
4. Record validation results via `update_task`.
5. Clean up: confirm `git status` clean, verify worktree removed.

## TUI behavior proof requirement (test pyramid)

**TUI implementation is NOT complete until each touched user-visible behavior has automated proof at the lowest correct layer.** Do not require `TmuxHarness` for purely virtual render/input/local-command work.

- **Virtual / in-process** (`castor test`, `VirtualTuiHarness`): layout, widgets, editor input, slash commands, local routing/render.
- **Controller replay** (`castor test:controller-replay`): runtime JSONL, session/events, shell/tool ordering.
- **Minimal tmux** (`castor test:tui`, `#[Group('tui-e2e-replay')]`, replay fixtures, isolated dirs): terminal integration smoke only when virtual/replay cannot prove the contract.

- Do **not** move a TUI task to CODE-REVIEW or DONE without the appropriate layer proof and passing focused Castor validation for that layer. Purely virtual features do **not** need a new tmux test. Require `castor test:tui` only when the change depends on tmux/pty/process boot.
- Fork instructions for TUI tasks must name the layer, test thesis, and commands to run.
- Reviewers must verify layer choice and reject tmux-only proof where virtual/replay suffices, or missing proof for the claimed layer.

**Load the `testing` skill** when: writing, running, or debugging TUI proof tests.

## Compaction resilience

After compaction, the `task-workflow` skill documents next steps. Use `task_list` to inspect active tasks, and load this skill for exact phase procedures.
