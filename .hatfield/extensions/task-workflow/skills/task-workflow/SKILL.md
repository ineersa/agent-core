---
name: task-workflow
description: "Routes task workflow phases to focused procedures. Load for task-explain, task-start, task-to-pr, task-review-iterate, task-done, implementation ownership, reviewer workflows, or compaction recovery."
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
