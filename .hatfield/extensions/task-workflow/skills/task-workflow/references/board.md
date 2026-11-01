# Task board

- Use the code checkout and resolved external task board from the active "Project task workflow" prompt context. The checkout is the host's current working directory; task tools use the same resolved board.
- Configure the board with `extensions.settings.task_workflow.task_root`; `HATFIELD_TASK_WORKFLOW_ROOT` overrides it. Without either, the extension discovers a valid sibling `<checkout-name>-tasks` board.
- Keep this installed guidance portable. Do not write resolved paths into skill files or infer a board from paths in another project's instructions.
- Board metadata is not committed to the code repository. Use task tools; the user commits the board separately when desired.
