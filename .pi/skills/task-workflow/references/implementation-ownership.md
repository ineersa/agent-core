# Implementation ownership

Main owns the routing decision, even when a fork later owns implementation.

## Routing pass

Before assigning implementation, main must:

1. Read the task, referenced documents, and applicable `AGENTS.md` files.
2. Inspect likely entry points, callers, tests, and module boundaries.
3. Identify cohesive implementation slices, important unknowns, and required validation.
4. Decide who owns each slice.

After this pass, main should know the likely area and entry points for each slice, its important unknowns, and the validation it needs. Stop before edit-level investigation for a slice that may go to a fork. Do not replace this pass with a scout report.

## Keep work with main

Main owns implementation by default. Main should keep a slice when:

- it is one cohesive change;
- it stays in one area or a small group of files;
- the entry points and expected behavior are clear;
- it does not need broad discovery;
- focused validation can prove it without repeated live or process-test iteration;
- no useful independent slice exists;
- explaining and reviewing a fork would cost about as much as implementing it.

File count is evidence, not a rule. If the choice is close, main owns the work.

## Use a fork

Use a fork only when all of these statements are true:

1. The slice has a clear boundary and acceptance criteria.
2. The fork can explore, implement, and validate it without making product decisions.
3. Main can review the diff and validation evidence without relearning the whole area.
4. The investigation or validation saved by delegation is large enough to justify the handoff and review.

Good reasons include unfamiliar internals, mechanical changes across many files, an isolated module, an independently testable task item, substantial runtime or test iteration, and external research tied to implementation. Task size alone is not a reason. Main keeps tightly coupled work and asks the user about unresolved behavior instead of sending ambiguity to a fork.

Give the fork the goal, acceptance criteria, constraints, known entry points, ownership boundary, and validation contract. Do not give it a completed implementation design. The fork owns detailed exploration, implementation, and focused validation inside that boundary.

## Execution rules

- One owner implements each slice.
- Every ownership change needs an explicit handoff.
- Write-capable owners work sequentially in one worktree because they share the Git index, generated files, formatters, and test artifacts.
- Parallel writers require separate branches or worktrees and an explicit integration order.
- Scouts, researchers, and reviewers are read-only.
- Independent review remains required.

Use no scout when main has enough context. Use one scout for a bounded unknown in an unfamiliar area. Use parallel scouts only for independent security, high-risk, or cross-module questions. Batch independent subagents in one parallel call. Use a single call for one child or dependent work. Retrieve full artifacts only when the summaries lack evidence needed for the decision.

## Role routing

| Role | Use |
|---|---|
| Main | Initial exploration, ownership decisions, small or cohesive implementation, validation, and task metadata |
| Fork | One bounded slice that meets the fork criteria above |
| Scout | Read-only code exploration and impact analysis for a bounded unknown |
| Researcher | Read-only external documentation or changelog research |
| Reviewer | Independent read-only review |

## Required ownership log

Append this exact record with `update_task(workLog=[...])` when assigning a slice and again when it completes or blocks:

```text
Ownership: owner=<main|fork>; fork_run=<run-id|none>; revision=<target revision/baseline>; scope=<bounded scope>; outcome=<assigned|completed|blocked>; commit=<sha|none>
```

The append-only work log is authoritative across slices and revisions. A latest `Fork run` pointer does not replace it. Preserve each role's handoff format. Task metadata records identity, revision, scope, outcome, validation, and unresolved blockers.
