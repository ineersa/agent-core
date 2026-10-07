# Implementation ownership

## Route before delegating

Main must read the task and applicable instructions, inspect entry points, callers, tests, and boundaries, then identify cohesive slices, unknowns, and validation. Do not replace this pass with a scout report. Stop before designing edits for a slice assigned to a fork.

Main coordinates, explores initial scope, and owns transitions. Keep a small one-off implementation that touches 1–2 files with main. Delegate every larger implementation slice to a fork.

Give the fork the goal, criteria, constraints, entry points, ownership boundary, and validation contract, not a completed edit design.

## Roles

- Forks implement bounded slices.
- Scouts explore the codebase read-only.
- Reviewers review revisions read-only.
- Researchers find web information read-only.
- Do not use scouts, reviewers, or researchers for ownership decisions or implementation.

## Execution

- Use one owner per slice and one writer per worktree. Parallel writers need separate worktrees and an integration order.
- Make every ownership change an explicit handoff.
- Batch independent child work; serialize dependent work. Retrieve omitted evidence only when needed.
- Require independent review before accepting implementation.

## Ownership log

Append this exact record with `update_task(workLog=[...])` on assignment and again on completion or blockage:

```text
Ownership: owner=<main|fork>; fork_run=<run-id|none>; revision=<target revision/baseline>; scope=<bounded scope>; outcome=<assigned|completed|blocked>; commit=<sha|none>
```

The append-only log is authoritative; a latest fork pointer does not replace it. Include identity, revision, scope, outcome, validation, and blockers in the handoff.
