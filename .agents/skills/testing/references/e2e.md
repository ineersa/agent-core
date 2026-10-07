# Controller and terminal proof

## Choose the lowest layer

- Use `castor test` and `VirtualTuiHarness` for layout, input, local commands, and widget rendering.
- Use `castor test:controller-replay` for runtime JSONL, events, and tool ordering.
- Use one minimal `castor test:tui` case for a unique real TTY or packaged-boot contract.
- Use live LLM only when replay cannot prove provider behavior; read [live-llm.md](live-llm.md).
- Do not add journeys that repeat virtual proofs. Custom smoke scripts or service DTO assertions are not sole runtime/TUI proof.
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

Save ANSI snapshots with `saveAnsiSnapshot`. Passing snapshots remain under `var/tmp/tui-e2e-*/`; failures go to `var/tmp/tui-failures/`. Inspect collected events, session artifacts, controller stderr, and transport queues on failure.
