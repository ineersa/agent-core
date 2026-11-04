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
- Before launching a fork, record its checkout, baseline, bounded scope, and sole-writer assignment with `fork_run=none`. After launch returns the run ID, immediately append the matching ownership record with that ID.
- Before edits, the fork must verify the recorded checkout, scope, and its own run ID. If attachment is missing or mismatched, notify main and do only independent prerequisite reads; verify the corrected record before editing. A prelaunch assignment is not a verified run-ID attachment.
- Batch independent child work; serialize dependent work. Retrieve omitted evidence only when needed.
- Require independent review before accepting implementation.

## Resuming a tracked fork

Use `agent_resume` for relevant terminal fork context in the current parent lifetime while it remains within the context budget. Wait for a running fork or use `agent_steer` for current guidance; do not launch a duplicate. Before resumed edits, name the checkout and resumed scope, require current-state inspection, and record the handoff. The fork must verify the matching ownership record and stop and request a missing handoff.

After an explicit parent-lifetime or context-budget resume refusal, assign a replacement using the prelaunch and postlaunch steps above. Record why the prior fork cannot resume, carry forward its relevant handoff and current repository state, and make the ownership transfer explicit. Do not retry the same non-resumable child or treat refusal as permission for overlapping writers. Keep these tracked-work rules here, not in global tool prompts.

## Ownership log

Append this exact record with `update_task(workLog=[...])` on assignment and again on completion or blockage:

```text
Ownership: owner=<main|fork>; fork_run=<run-id|none>; revision=<target revision/baseline>; scope=<bounded scope>; outcome=<assigned|completed|blocked>; commit=<sha|none>
```

The append-only log is authoritative; a latest fork pointer does not replace it. Include identity, revision, scope, outcome, validation, and blockers in the handoff.
