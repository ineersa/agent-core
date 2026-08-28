# Implementation Ownership

Choose ownership after a shallow routing pass and before deep implementation exploration.

- **Main owns** a cohesive slice when it already has the detailed implementation model.
- **One fork owns** each bounded slice when investigation plus implementation would otherwise create noisy parent context. Give it the goal, acceptance criteria, constraints, known entry points, ownership boundary, and validation contract—not a parent-completed design.
- Scouts, researchers, and reviewers are read-only.
- Write-capable owners execute sequentially in one worktree. Parallel writers require separate branches/worktrees and an explicit integration order.
- Independent review remains required.

Use zero scouts when context is sufficient, one for an unfamiliar bounded scope, and parallel scouts only for genuinely independent high-risk, security, or cross-module lenses. Batch independent subagents in one parallel call; use a single call for one child or dependent work. Retrieve full artifacts only when their bounded summaries are insufficient.

## Role routing

| Role | Use |
|---|---|
| Scout | Read-only codebase exploration and impact analysis |
| Researcher | Read-only external documentation or changelog research |
| Fork | One complete bounded investigation-and-implementation slice |
| Main | Cohesive implementation it already understands, validation, and task metadata |

## Required ownership log

Append this exact record with `update_task(workLog=[...])` when assigning a slice and again when it completes or blocks:

```text
Ownership: owner=<main|fork>; fork_run=<run-id|none>; revision=<target revision/baseline>; scope=<bounded scope>; outcome=<assigned|completed|blocked>; commit=<sha|none>
```

The append-only work log is authoritative across slices and revisions. A latest `Fork run` pointer never replaces it. Preserve each role's own handoff format; task metadata records identity, revision, scope, outcome, validation, and unresolved blockers.
