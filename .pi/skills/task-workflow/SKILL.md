---
name: task-workflow
description: "Load for task phases, ownership, review, or compaction recovery."
---

# Task workflow

- Use task tools for board transitions and metadata; never edit or move board files manually.
- Before phase work or `move_task`, read the exact procedure below and its required references. Load only files needed for the current operation.
- Main owns transitions; forks must not transition tasks.
- Before ownership decisions outside a phase, read [ownership](references/implementation-ownership.md). Before review, also read [specification fidelity](references/specification-fidelity.md).
- After compaction, run `task_list`, reload this router, and read the current procedure. Reread the procedure after every phase change.

| Phase | Procedure |
|---|---|
| `task-explain` | [Explain](references/task-explain.md) |
| `task-start` | [Start](references/task-start.md) |
| `task-to-pr` | [Submit](references/task-to-pr.md) |
| `task-review-iterate` | [Iterate](references/task-review-iterate.md) |
| `task-done` | [Merge](references/task-done.md) |

For board configuration, read [board](references/board.md).
