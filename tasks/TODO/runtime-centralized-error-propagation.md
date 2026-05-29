# Centralized error propagation and TUI-visible error capture

## Goal
Scout audit summary: the repo has ~85 PHP catch blocks across ~34 files. Propagation is inconsistent: some blocks rethrow/wrap or return structured error DTOs, but many log-only/return-null/silent fallback paths can leave the user staring at a stuck TUI with no explanation. Goal: every non-intentional exception/error must be propagated to a user-visible runtime/TUI error path unless explicitly documented as safe local degradation.

User intent/requirements:
- User MUST see what is happening in the TUI when runtime/controller/TUI infrastructure fails.
- Add centralized error catching/showing instead of one-off catch/log/ignore paths.
- Add env knob `HATFIELD_CAPTURE_ERRORS=1` by default.
  - When enabled/default: capture exceptions and convert them into user-visible runtime/TUI failures where possible.
  - When disabled (`HATFIELD_CAPTURE_ERRORS=0`): let errors crash/propagate normally so deterministic tests/model agent runs can catch real failures.
- Add a hard rule to `AGENTS.md`: every caught error/exception must be propagated forward to the user/runtime/TUI, or explicitly documented as intentional local degradation. Mention a static guard approach if feasible (Rector/PHP-Parser/nikic-php-parser or PHPStan custom rule) to prevent empty/log-only catch blocks.
- Use Castor for validation only.

Important existing propagation mechanisms to reuse:
- `RuntimeEventTypeEnum` already has `RunFailed`, `TurnFailed`, `AssistantMessageFailed`, `ToolExecutionFailed`, `ProtocolError`, `CommandRejected`.
- TUI already renders `TranscriptBlockFactory::error()` as `TranscriptBlockKindEnum::Error`.
- `RuntimeEventPoller::updateActivity()` maps failure event types to `RunActivityStateEnum::Failed`.
- `SubmitListener::register()` has a good TUI-visible error pattern: set activity Failed and append an error transcript block.
- `HeadlessController::emitCommandRejected()` / decode-command protocol errors are examples of controller-to-runtime event propagation.
- `AgentCommand::__invoke()` currently logs and rethrows top-level CLI exceptions.

Critical/problematic sites from scout audit (prioritize):
1. `src/AgentCore/Application/Pipeline/RunCommit.php`
   - catch blocks around event persistence, hot prompt rebuild, effect dispatch, after-turn commit hook.
   - `commit()` can return false or log-only without a runtime failure event. Event persist failure can diverge run state from `events.jsonl` and user sees nothing.
   - Requirement: terminal commit/integrity failures must emit/record `run_failed` or otherwise mark run failed with error visible in TUI.
2. `src/CodingAgent/Runtime/Controller/ConsumerSupervisor.php`
   - `launch()` catches `Throwable`, logs `Failed to launch messenger consumer`, then returns.
   - If consumers fail to launch, controller may report ready while no work is processed; TUI hangs.
   - Requirement: emit a runtime/controller error event to stdout/TUI or fail process when capture disabled.
3. `src/CodingAgent/Runtime/Controller/HeadlessController.php`
   - event drain catch logs warning only; TUI can stop receiving events with no visible error.
   - `pollLlmStdout()` catches malformed JSONL and debug-logs only; repeated malformed output can silently drop deltas.
   - Requirement: controller errors should be routed through centralized runtime error handler; repeated malformed stdout should become visible after a threshold.
4. `src/Tui/Runtime/RuntimeEventPoller.php`
   - first two non-fatal polling errors only log/store state; no immediate visible feedback.
   - Requirement: show at least transient status/working warning immediately, then fail after threshold if needed.
5. `src/Tui/Listener/CancelListener.php`
   - cancel command failure logs warning but still shows `Cancelling...`, possibly forever.
   - Requirement: cancel failure must append an error block/status and not leave fake cancelling state.
6. `src/CodingAgent/Session/SessionRunEventStore.php`
   - corrupt JSONL lines silently skipped in `allFor()`.
   - Requirement: do not silently erase replay data; log and/or surface corruption on resume/replay depending on capture mode.
7. `src/CodingAgent/Session/SessionRunStore.php`
   - corrupt `state.json` returns null, indistinguishable from missing run.
   - Requirement: distinguish not-found vs corruption; make resume error visible.
8. `src/CodingAgent/Extension/ExtensionManager.php`
   - extension registration failure logs only.
   - Requirement: fail-open may be okay, but surface startup diagnostics/error block/status so user knows an extension did not load.
9. `src/Tui/Theme/ThemeRegistry.php`, `src/Tui/Theme/DefaultTheme.php`
   - broken theme files / invalid colors silently skipped/fallback.
   - Requirement: if retained as local degradation, add explicit comments and diagnostic logging/startup warning.
10. `src/Tui/Picker/ModelPickerController.php`, `src/Tui/Picker/FavoritePickerController.php`
    - favorite toggle failure logs only and consumes input.
    - Requirement: show visible status flash or error block.
11. `src/Tui/Listener/ModelCommandHandler.php`
    - failed ordered model fetch degrades to empty list and misleading `No AI models configured`.
    - Requirement: distinguish `failed to load models` from `no models configured`.
12. Empty/silent intentional catch blocks needing explicit documentation or debug logging:
    - `src/AgentCore/Domain/Message/AgentMessage.php` timestamp parse failure.
    - `src/AgentCore/Schema/EventPayloadNormalizer.php` timestamp parse failure.
    - `src/CodingAgent/Tool/ImageProcessing/ImageAttachmentProcessor.php` image processing/exif fallback.
    - `src/CodingAgent/Logging/LogParser.php` malformed log line parse (probably correct local degradation).
    - `src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php` malformed stdout is buffered; ensure user can see if transport is broken.

Recommended design direction:
- Add a narrow centralized service/contract, e.g. `RuntimeErrorHandlerInterface` / `RuntimeErrorCaptureService`, owned by the runtime/app boundary (not TUI depending on internals). It should log, classify context, and publish/emit the correct user-visible failure event or status. Keep architecture boundaries intact.
- Support contexts that have a run id and contexts that are startup/controller-level with no run id yet.
- Terminal infrastructure/data-integrity errors: produce `run_failed`/`protocol.error`/runtime-visible error and set failed activity/state.
- Recoverable local degradation: allowed only with explicit comment and debug/warning diagnostics; do not call it propagated.
- Respect `HATFIELD_CAPTURE_ERRORS`: enabled by default; disabled means rethrow/let crash after logging so tests can fail loudly.
- Consider wrapping Revolt/TUI/controller callbacks so uncaught exceptions from callbacks are captured when enabled and crash when disabled.
- Add docs/settings update for `HATFIELD_CAPTURE_ERRORS` if settings docs cover env knobs.
- Add AGENTS.md rule. Also investigate a static guard: Rector rule using nikic/php-parser or PHPStan custom rule to flag empty catch, log-only catch, return-null/default-only catch, and catch blocks without `throw`, structured error return, or call to centralized error handler. If a full static rule is too large, document a follow-up and add a minimal grep/Castor guard where practical.

Validation expectations:
- Unit/integration coverage for the centralized error handler and the most critical conversions.
- Tests should cover both capture enabled and disabled where practical.
- Because this touches runtime/TUI visible behavior, also run a product-level Castor workflow per AGENTS.md: `castor test:controller`, `castor test:tui`, or `castor run:agent-test` as appropriate, in addition to `castor test`/targeted tests.

## Acceptance criteria
- `HATFIELD_CAPTURE_ERRORS` is implemented with default enabled (`1`) and an explicit disabled mode (`0`) that rethrows/crashes instead of swallowing captured errors.
- A centralized runtime/app error-capture path exists and is used by critical runtime/controller/TUI catch blocks instead of log-only swallowing.
- Critical findings in `RunCommit`, `ConsumerSupervisor`, `HeadlessController`, `RuntimeEventPoller`, and `CancelListener` produce user-visible TUI/runtime errors when capture is enabled.
- Corrupt session state/event replay errors are distinguishable from missing data and are visible/logged appropriately.
- Intentional local-degradation catches are documented with comments and at least debug/warning diagnostics where useful; empty catch blocks are removed or justified.
- `AGENTS.md` includes a hard rule requiring caught exceptions/errors to be propagated forward or explicitly documented as intentional local degradation.
- A static enforcement approach is added or documented: Rector/PHP-Parser/PHPStan rule or a Castor-integrated guard for empty/log-only/unpropagated catch blocks.
- Docs/settings are updated for `HATFIELD_CAPTURE_ERRORS` if applicable.
- Castor validation is reported, including a product-level runtime/TUI workflow (`castor test:controller`, `castor test:tui`, or `castor run:agent-test`) because this touches user-visible runtime/TUI behavior.

## Workflow metadata
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-05-29T15:37:21.939Z
