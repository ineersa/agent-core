# TUI behavior proof

Every changed user-visible TUI behavior needs automated proof at the lowest correct layer:

- **Virtual or in-process** with `castor test` and `VirtualTuiHarness` for layout, widgets, editor input, slash commands, local routing, and rendering.
- **Controller replay** with `castor test:controller-replay` for runtime JSONL, sessions, events, and shell or tool ordering.
- **Minimal tmux** with `castor test:tui` and `#[Group('tui-e2e-replay')]` for terminal integration that virtual tests or controller replay cannot prove.

Mocks, service-only DTO tests, custom smoke scripts, and picker or footer visibility assertions cannot be the only proof. Do not require tmux for behavior that virtual tests can prove. A delegated handoff must name the proof layer, what the test proves, and the commands to run. Reviewers reject missing proof and tests placed at a higher layer than needed.

Read the `testing` skill before writing, running, or debugging tests.
