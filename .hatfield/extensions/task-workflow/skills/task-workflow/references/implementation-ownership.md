# Implementation ownership

## Route before delegating

Main must complete the investigation, inspect the task, applicable instructions, entry points, callers, tests, and boundaries, and decide the implementation plan before delegation. Resolve design questions and investigation unknowns before assigning implementation. Do not replace this pass with a scout report.

Main coordinates, explores initial scope, and owns transitions. Keep a small one-off implementation that touches 1–2 files with main. Delegate every larger implementation slice to a fork.

Give the fork a precise, self-contained implementation plan and exact task: affected code, required behavior and invariants, and validation. The fork implements that plan. Do not impose mandatory headings. Exclude orchestration history, prior-agent failures, status chatter, and rules already provided by project instructions.

Apply this content rule to initial assignments, resumes, and replacements. Include necessary existing-draft facts, such as current changes to preserve, but not why a previous writer stopped. Keep ownership records and launch/resume logistics in workflow metadata, not implementation assignment text.

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

Use `agent_resume` for relevant terminal fork context in the current parent lifetime while it remains within the context budget. Wait for a running fork or use `agent_steer` for current guidance; do not launch a duplicate. Before resumed edits, record the checkout, resumed scope, and handoff in workflow metadata and require current-state inspection. The fork must verify the matching ownership record and stop and request a missing handoff.

After an explicit parent-lifetime or context-budget resume refusal, assign a replacement using the prelaunch and postlaunch steps above and the same implementation-plan content rule. Record the refusal and make the ownership transfer explicit in workflow metadata; include only necessary current-state and existing-draft facts in the implementation assignment. Do not retry the same non-resumable child or treat refusal as permission for overlapping writers. Keep these tracked-work rules here, not in global tool prompts.

## Ownership log

Append this exact record with `update_task(workLog=[...])` on assignment and again on completion or blockage:

```text
Ownership: owner=<main|fork>; fork_run=<run-id|none>; revision=<target revision/baseline>; scope=<bounded scope>; outcome=<assigned|completed|blocked>; commit=<sha|none>
```

The append-only log is authoritative; a latest fork pointer does not replace it. Include identity, revision, scope, outcome, validation, and blockers in the handoff.
