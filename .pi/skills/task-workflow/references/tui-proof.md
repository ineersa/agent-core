# TUI Behavior Proof

Every touched user-visible TUI behavior requires automated proof at the lowest correct layer:

- **Virtual/in-process** (`castor test`, `VirtualTuiHarness`): layout, widgets, editor input, slash commands, local routing/render.
- **Controller replay** (`castor test:controller-replay`): runtime JSONL, sessions/events, shell/tool ordering.
- **Minimal tmux** (`castor test:tui`, `#[Group('tui-e2e-replay')]`): terminal integration only when virtual/replay cannot prove the contract.

Do not use mocks, service-only DTO tests, custom smoke scripts, or picker/footer visibility assertions as the sole proof. Do not demand tmux for purely virtual behavior. Delegated handoffs must name the layer, test thesis, and commands. Reviewers reject a missing or incorrectly elevated proof layer.

Load the `testing` skill before writing, running, or debugging this proof.
