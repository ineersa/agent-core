---
name: task-workflow
description: "Routes task workflow phases to focused procedures. Load for task-explain, task-start, task-to-pr, task-review-iterate, task-done, implementation ownership, reviewer workflows, or compaction recovery."
version: 1.1.0
---

# Task workflow router

Do not perform phase work from this router alone. Before starting a phase or calling `move_task`, main must read the exact phase procedure linked below. Read one phase procedure at a time.

| Phase | Procedure |
|---|---|
| `task-explain` | [references/task-explain.md](references/task-explain.md) |
| `task-start` | [references/task-start.md](references/task-start.md) |
| `task-to-pr` | [references/task-to-pr.md](references/task-to-pr.md) |
| `task-review-iterate` | [references/task-review-iterate.md](references/task-review-iterate.md) |
| `task-done` | [references/task-done.md](references/task-done.md) |

The phase procedure links any other reference needed for that phase. Read those linked references before acting. Do not load unrelated phase files.

When deciding implementation ownership outside a phase, read [references/implementation-ownership.md](references/implementation-ownership.md). When reviewing outside a phase, also read [references/specification-fidelity.md](references/specification-fidelity.md).

## Tracked-work fork resume

When tracked task work uses `agent_resume` on an eligible terminal fork, treat the follow-up as a tracked ownership handoff:

1. Name the exact checkout or worktree and the resumed scope in the follow-up task text.
2. Require the resumed fork to inspect current file state before editing.
3. If that ownership handoff is missing, the resumed fork must stop and ask the parent for it.
4. Record the ownership change with the required ownership log in [references/implementation-ownership.md](references/implementation-ownership.md).

Do not encode these tracked-work rules in global `fork` or `agent_resume` tool text. Keep them in this skill and its references.

## Task board

Tasks live outside the code repository:

- Code: `/home/ineersa/projects/agent-core`
- Board: `/home/ineersa/projects/agent-core-tasks`
- Configuration: `extensions.settings.task_workflow.task_root` in Hatfield settings, overridden by `HATFIELD_TASK_WORKFLOW_ROOT`

Use task tools for status transitions and metadata; never move or edit task files manually. Board changes do not commit to `agent-core`; the user commits the external board separately when desired.

## Workflow

```text
task-explain → task-start → task-to-pr → task-done
                  ↕
            task-review-iterate
```

Read a new phase procedure whenever the phase changes. After compaction, run `task_list`, reload this router, and read the current phase procedure before continuing.
