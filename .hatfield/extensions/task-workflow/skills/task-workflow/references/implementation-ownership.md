# Implementation ownership

## Route before delegating

Main must read the task and applicable instructions, inspect entry points, callers, tests, and boundaries, then identify cohesive slices, unknowns, and validation. Do not replace this pass with a scout report. Stop before designing edits for a slice assigned to a fork.

Keep cohesive, clear work with main. If delegation would cost as much as implementation, main owns it. File count alone does not decide ownership.

Use a fork only when all four conditions hold:

1. Scope and acceptance criteria are clear.
2. The fork can explore, implement, and validate without product decisions.
3. Main can review the diff and evidence without relearning the area.
4. Delegation saves meaningful investigation or context.

Give the fork the goal, criteria, constraints, entry points, ownership boundary, and validation contract, not a completed edit design.

## Execution

- Use one owner per slice and one writer per worktree. Parallel writers need separate worktrees and an integration order.
- Make every ownership change an explicit handoff.
- Keep scouts, researchers, and reviewers read-only. Use them for bounded unknowns or independent review, not ownership decisions.
- Batch independent child work; serialize dependent work. Retrieve omitted evidence only when needed.
- Require independent review before accepting implementation.

## Resuming a tracked fork

Use `agent_resume` when the existing parent-scoped fork context applies. Before edits, name the checkout and resumed scope, require current-state inspection, and record the handoff. The fork must stop and request a missing handoff. Keep these tracked-work rules here, not in global tool prompts.

## Ownership log

Append this exact record with `update_task(workLog=[...])` on assignment and again on completion or blockage:

```text
Ownership: owner=<main|fork>; fork_run=<run-id|none>; revision=<target revision/baseline>; scope=<bounded scope>; outcome=<assigned|completed|blocked>; commit=<sha|none>
```

The append-only log is authoritative; a latest fork pointer does not replace it. Include identity, revision, scope, outcome, validation, and blockers in the handoff.
