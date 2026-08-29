---
name: task-workflow
description: "Routes task workflow phases to focused procedures. Load for task-explain, task-start, task-to-pr, task-review-iterate, task-done, implementation ownership, reviewer workflows, or compaction recovery."
---

# Task Workflow Router

Read this router, then load **only** the reference for the active phase:

| Phase | Procedure |
|---|---|
| `task-explain` | [references/task-explain.md](references/task-explain.md) |
| `task-start` | [references/task-start.md](references/task-start.md) |
| `task-to-pr` | [references/task-to-pr.md](references/task-to-pr.md) |
| `task-review-iterate` | [references/task-review-iterate.md](references/task-review-iterate.md) |
| `task-done` | [references/task-done.md](references/task-done.md) |

Phase procedures link any additional reference required for that phase. Do not load unrelated phase references.

When defining implementation ownership outside a phase, load [references/implementation-ownership.md](references/implementation-ownership.md). When reviewing outside a phase, also load [references/specification-fidelity.md](references/specification-fidelity.md).

## Task board

Tasks live outside the code repository:

- Code: `/home/ineersa/projects/agent-core`
- Board: `/home/ineersa/projects/agent-core-tasks`
- Configuration: `.pi/settings.json` → `taskWorkflow.taskRoot`, overridden by `PI_TASK_WORKFLOW_ROOT`

Use task tools for status transitions and metadata; never move or edit task files manually. Board changes do not commit to `agent-core`; the user commits the external board separately when desired.

## Workflow

```text
task-explain → task-start → task-to-pr → task-done
                  ↕
            task-review-iterate
```

After compaction, run `task_list`, reload this router, then load only the current phase procedure.
