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

Implementation ownership is a context-management decision. The agent with the detailed implementation model normally completes cohesive work; delegate only complete bounded slices where transfer avoids rereading/context growth. One owner edits each slice; hand off explicitly and never edit the same files concurrently. Independent review remains required.

Agents may be dispatched as follows:

| Agent | Use for |
|---|---|
| **Scout subagents** | Codebase exploration, dependency checks, architecture discovery, impact analysis |
| **Researcher subagents** | Web searches, documentation lookups, changelog checks |
| **Worker/fork** | A complete bounded implementation slice, mechanical migration, isolated module, or context-heavy investigation plus implementation |
| **Main agent** | Planning, cohesive implementation it already understands, validation, ownership decisions, and task metadata |

Worker handoffs are compact: commit, changed paths, validation, unresolved risks.

### Subagent dispatch (parallel vs sequential)

- **Independent** scouts/reviewers/researchers: batch them in **one** parallel `subagent` call with a `tasks` array whenever within `agents.max_agents`. Separate single-mode calls for independent work are valid syntax but serialize and waste wall time.
- **Single child** or **dependent** work: use `{"agent":"...","task":"..."}` only for exactly one child, or when a later task cannot be formed until an earlier result returns (follow-up investigation, re-review after a fix, deliberate serialization).
- Outer separate `subagent` tool calls are sequential; children inside one `tasks` array run concurrently. Split across multiple calls only for **cap overflow** or **true dependencies**.
- Parallel results are bounded summaries — use `agent_retrieve` with each `Artifact:` ID for complete handoffs when needed.

## Specification fidelity gate (mandatory)

Before writing fork instructions or accepting review:

1. Map every proposed externally visible addition (setting, API, storage field, command, user-visible behavior) to an **exact finalized task requirement**. Unmapped additions are forbidden.
2. Fork instructions may choose minimal implementation mechanics, but **must not introduce uncited product decisions**. Unresolved ambiguity affecting behavior or public surface goes back to the user — do not invent defaults or surface.
3. Latest explicit task clarification overrides earlier superseded scope.
4. Reviewers must inventory changed external surface and complexity against finalized requirements and return **REQUEST CHANGES** for unmapped functionality or unnecessary complexity.
5. Delete code, branches, prompts, adapters, tests, compatibility paths, and procedures that become dead, unreachable, superseded, or unsupported in the same change. Do not preserve them “just in case” or add uncited fallback behavior. Required error handling and explicitly documented local degradation remain valid.

Root `AGENTS.md` owns the principle; this gate enforces it in task-start and review.

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
2. Scout codebase for affected areas, dependencies, existing patterns. When multiple independent scouts are useful, launch them in **one** parallel `tasks` call (not separate single-mode calls).
3. Researcher for external info when needed (batch independent research with scouts in the same `tasks` call when useful).
4. Present structured plan: summary, affected areas, implementation steps, risks/open questions, suggested validation.
5. Discuss with user. Highlight decision points — do not silently resolve them.
6. When ready to implement, user runs `task-start`.

## Leaked QA workers

**Leaked QA workers:** `castor check` does not auto-kill. Survivors are lifecycle bugs — fix teardown at root cause; do not kill as routine before retry. Use `castor clean:cleanup:workers:list` for diagnostics; `castor clean:cleanup:workers` only as explicit last resort after investigation.
## task-start: Implement (TODO → IN-PROGRESS)

1. `move_task(to="IN-PROGRESS")` — creates worktree branch.
   - Worktree creation copies `vendor/` and `.vera/` into the worktree, and updates the parent worktree IDEA module exclusions when present.
2. Scout codebase for context, researcher for external info. Batch independent scouts/researchers in one parallel `tasks` call; use sequential single-mode only when a later probe depends on an earlier result.
3. Apply the **Specification fidelity gate**: map every proposed externally visible addition to an exact finalized requirement and resolve ambiguity with the user.
4. Build the implementation model, then explicitly choose and record either main-owned cohesive implementation or worker-owned bounded slice(s). Define disjoint file ownership and compact handoff evidence for worker slices.
5. Implement without overlapping ownership. The main agent may complete its cohesive slice; a worker owns any delegated bounded slice.
6. Verify commits/output and run focused validation. Record implementation ownership, changed paths, validation, and unresolved risks via `update_task`.
7. **STOP.** Do not proceed to PR or code review.
   - Do NOT run: `castor check`, `move_task(to="CODE-REVIEW")`, `gh pr create`, `git push`, reviewer subagent.
   - Inform user implementation is done. They run `task-to-pr` when ready.

**Validation from an active Hatfield session:** Full `castor check` in the integration checkout is safe after the Castor stale-worker guard (active session consumers with `HATFIELD_SESSION_ID` are not killed). For task branches, prefer running gates in the task worktree to avoid competing with a live session in the same tree.

**Note on task board changes:** Task metadata/status updates modify files in the external task board
(`/home/ineersa/projects/agent-core-tasks/`). These changes are NOT committed to the agent-core code
repo. The external task board repo must be committed manually when desired.

**Worktree JetBrains lifecycle:** When creating a worktree, the extension (1) updates the parent worktree IDEA module (e.g., `agent-core-worktrees.iml`) with an idempotent sentinel block of `<excludeFolder>` entries so the aggregate worktrees project does not index generated content, (2) creates minimal worktree-local `.idea` metadata derived from the integration primary module (source roots/exclusions only; no workspace/datasources/cross-module refs), and (3) opens that exact worktree via MCP `ide_open_project` (agent-visible `jetbrains-index_ide_open_project`). On DONE/CANCELLED cleanup of an existing worktree, the exact project is closed before removal. IDE/MCP failures are degradation notes, not transition failures. Prefer semantic IDE tools against the exact worktree `project_path`; filesystem tools remain fallback.

**TUI behavior proof for implementation:** For tasks touching TUI behavior, the fork MUST add or update automated proof at the **lowest correct layer** (virtual/in-process, controller-replay, or minimal tmux — see pyramid below). Fork instructions must state the test thesis and layer. Mocks, service-only DTO tests, custom PHP smoke scripts, and picker/footer visibility assertions are NOT acceptable as the only proof. See `## TUI behavior proof requirement` below.

### task-to-pr: Review and create PR (IN-PROGRESS → CODE-REVIEW)

1. Inspect worktree state: `git status`, `git log`, `git diff --stat origin/main...HEAD`.
2. Run reviewer subagent on worktree (`subagent agent="reviewer" cwd=worktree`). Instruct the reviewer to apply the **Specification fidelity gate**: compare changed external surface/complexity to finalized requirements and REQUEST CHANGES for unmapped or unnecessary additions.
   - If REQUEST CHANGES → analyze blockers, fork fixes, re-review. Repeat until APPROVED.
3. Run focused local validation on worktree:
   - focused `castor test --filter=…` for touched areas, `castor deptrac`, `castor phpstan`, `castor cs-check`; run controller-replay or `castor test:tui` only when that proof layer is required. Do not require full `castor test`: CODE-REVIEW runs full `castor check`.
   - When changes touch provider/LLM-visible code (Symfony AI provider, model routing, tool schemas, LLM prompts, streaming conversion), also run `castor test:llm-real` as opt-in focused validation. This is NOT required for every normal task — only when the change affects live provider compatibility path.
   - The orchestrator/user is responsible for focused validation before moving to CODE-REVIEW. `move_task(to="CODE-REVIEW")` automatically runs deterministic `castor check` in the worktree before pushing and creating the PR.
4. Record reviewer decision, commit sha, validation results via `update_task`.
5. `move_task(to="CODE-REVIEW")` — runs castor check in worktree, verifies it is clean, pushes branch, creates PR.

### task-review-iterate: Address PR feedback (CODE-REVIEW → IN-PROGRESS → CODE-REVIEW)

1. Read PR summary via `gh pr view` and inline comments via `gh api repos/<owner>/<repo>/pulls/<n>/comments`. Classify blockers vs suggestions. In Hatfield, resume the prior reviewer with `agent_resume` plus commit/diff and resolution delta when its artifact/run identity is available; launch a new reviewer only when no reviewer can be resumed.
2. `move_task(to="IN-PROGRESS")` before any implementation.
3. Re-apply the **Specification fidelity gate**, choose main-owned cohesive fixes or worker-owned bounded slices, and record disjoint ownership before implementation.
4. Implement fixes, verify output, and run focused Castor validation.
5. Re-review with reviewer subagent (include the specification fidelity gate). If REQUEST CHANGES → repeat from step 3.
6. When APPROVED → `move_task(to="CODE-REVIEW")` (pushes branch, creates/updates PR).
7. Record decisions, commit sha, reviewer result via `update_task`.

### task-done: Merge approved PR (CODE-REVIEW → DONE)

1. Confirm PR is approved/merged on GitHub.
2. `move_task(to="DONE")` — merges task branch into integration checkout, runs `git pull`.
   - If merge conflicts → task stays CODE-REVIEW. Do not force.
3. Post-merge validation: `LLM_MODE=true castor check` on integration checkout.
   - If prerequisites unavailable: focused `castor test --filter=…` for touched areas, `castor deptrac`, `castor phpstan`, `castor cs-check`; run controller-replay or `castor test:tui` only when that proof layer is required. Do not require full `castor test`: CODE-REVIEW runs full `castor check`.
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

## CODE-REVIEW failure runbook

`move_task(CODE-REVIEW)` reports the failing Castor lane, a bounded first failure snippet, and its `var/reports/qa-<id>/check-*.log` path when a lane ran. For lock, setup, preflight, or finalizer failures without a lane log, use the reported QA directory and the real bounded setup error; never invent a lane or log. Treat every flaky test as a product/harness defect: no allowlist, quarantine, blind retry, or timeout increase. Fix its deterministic root cause, document the unrelated fix, re-review, and rerun; escalate only when the proper fix needs a broader product/design decision.

## Reviewer verdict rubric

CRITICAL, BUG, SEC, unmapped surface, dead code, uncited fallback behavior, or missing required proof means **REQUEST CHANGES**. NTH, naming, and pure ponytail micro-shrinks mean **APPROVE WITH SUGGESTIONS** unless correctness is affected. Once blockers are fixed, a remaining tiny line shrink must not block approval.

Status styling is selected by key in `StatusPanelWidget`; keep `setStatus` text plain.
