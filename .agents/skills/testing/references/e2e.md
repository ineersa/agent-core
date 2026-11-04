# Controller and terminal proof

## Choose the lowest layer

- Every changed user-visible TUI behavior needs automated proof at the lowest correct layer.
- Use `castor test` and `VirtualTuiHarness` for layout, widgets, editor input, slash commands, local routing, and rendering.
- Use `castor test:controller-replay` for runtime JSONL, sessions, events, and shell or tool ordering.
- Use one minimal `castor test:tui` case with `#[Group('tui-e2e-replay')]` for terminal integration that virtual tests or controller replay cannot prove, or the existing packaged-boot case for that contract.
- Use live LLM only when replay cannot prove provider behavior; read [live-llm.md](live-llm.md).
- Do not require tmux or add journeys for behavior virtual tests can prove. Mocks, service-only DTO tests, custom smoke scripts, and picker or footer visibility assertions cannot be the only proof. Report the proof layer, what the test proves, and exact validation commands; reject missing proof or tests placed at a higher layer than needed.
- If a reported live hang survives replay, reproduce it with the real controller instead of adding more fixture-only assertions.

## Harness and lifecycle

- Extend `ControllerReplayE2eTestCase` for replay; extend `ControllerE2eTestCase` for live proof.
- Behavioral controller/TUI tests run source `bin/console` with `APP_ENV=test`, not a PHAR with unavailable test bundles.
- Replay stays test-layer-only through `ControllerReplayHttpClientFactory`; no production replay-env branches.
- Provide a fixture for every expected LLM turn. Exhaustion must fail, never fabricate successful completion.
- Reuse `indexByType`, `foundAck`, `assertStartRunAcked`, `collectEventsUntil`, and diagnostic helpers.
- Collect the specific event that proves the behavior. Do not wait for `run.completed` when the contract is tool execution.
- Pair app and Messenger transport databases through `TuiE2eDatabaseEnv` or the existing controller base.
- Keep independent PID ownership for each controller tree; teardown the entire owned group and descendants.
- Couple readiness markers to child liveness and report early death. Do not poll a file blindly.
- Never share tmux sessions or fixture cursors. Restore process-global environment changes completely.
- Use `startDetached`, `sendLiteral`, `sendKey`, and bounded `waitForTuiReady` or harness predicates.
- Use positive visible state, events, or artifacts, not stale scrollback absence. Yield in bounded predicate polls; never busy-spin.
- A required product delay must be documented and minimal. If only an elapsed-time window proves the test and no deterministic barrier exists, delete the case with coverage-loss evidence.

## Artifacts

`castor test:tui` ensures a PHAR for `TuiArtifactBootE2eTest`, but behavioral cases remain source-based. Run `castor phar:ensure` before snapshot updates when artifact boot is needed. Use `castor test --filter=PharSmokeTest` for focused packaged boot.

Save ANSI snapshots with `saveAnsiSnapshot` under `var/reports/qa-*/diagnostics/`. Keep the latest 10 completed QA runs, regardless of result; unproved interrupted runs remain protected. Inspect collected events, session artifacts, controller stderr, and transport queues on failure.
