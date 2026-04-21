# Refactor Progress — Stage 12 (RunOrchestrator Deepening)

## 2026-04-21 — Phase 1 completed (extraction slice)

Implemented a first safe slice of `implementation/12-refactor-run-orchestrator-deepening.md`.

### ✅ Done in this phase

1. **Extracted durable commit lifecycle**
   - Added `src/Application/Orchestrator/RunCommit.php`.
   - Moved commit responsibilities out of monolithic orchestrator:
     - CAS persistence
     - event append / rollback-on-failure
     - outbox projection
     - replay hot-state rebuild
     - effect dispatch
     - after-turn hook dispatch
     - structured commit/event logging
     - commit metrics updates
     - `persistence.commit` tracing span

2. **Extracted mailbox boundary policy**
   - Added `src/Application/Orchestrator/CommandMailboxPolicy.php`.
   - Moved command mailbox logic out of monolithic orchestrator:
     - turn-start boundary command application
     - stop-boundary command application
     - steer superseding behavior (`one_at_a_time` vs `all`)
     - extension command boundary handling
     - continue-command admissibility checks
     - cancel-safe extension command checks

3. **Rewired `RunOrchestrator` to delegate**
   - Updated `src/Application/Orchestrator/RunOrchestrator.php`:
     - now composes `RunCommit` + `CommandMailboxPolicy`
     - commit and mailbox methods delegate to extracted collaborators
     - removed now-obsolete private helper logic from orchestrator body
   - Kept external behavior stable for existing scenarios/tests.

4. **Service wiring and architecture docs**
   - Updated `config/services.php` to register:
     - `RunCommit`
     - `CommandMailboxPolicy` (with configured `steerDrainMode`)
   - Updated `src/Application/AGENTS.md` to reflect `RunCommit` ownership in commit flow.

5. **AI index/docs regeneration + quality gates**
   - Ran `LLM_MODE=true castor dev:check` successfully.
   - Regenerated `src/**/ai-index.toon` and `src/**/docs/*.toon` outputs, including:
     - `src/Application/Orchestrator/docs/RunCommit.toon`
     - `src/Application/Orchestrator/docs/CommandMailboxPolicy.toon`

---

## 2026-04-21 — Phase 2 completed (processor + dedicated handlers)

Implemented the requested Phase 2 slice from `implementation/12-refactor-run-orchestrator-deepening.md`.

### ✅ Done in this phase

1. **Introduced handler contract + transition envelope**
   - Added `src/Application/Orchestrator/RunMessageHandler.php`
   - Added `src/Application/Orchestrator/HandlerResult.php`

2. **Introduced shared message-processing runtime**
   - Added `src/Application/Orchestrator/RunMessageProcessor.php`
   - Processor now owns shared flow:
     - lock by `runId`
     - idempotency guard
     - state load
     - handler routing via `supports()`
     - commit via `RunCommit`
     - post-commit effect dispatch (`postCommitEffects`)
     - post-commit callbacks (`postCommit`)
     - final idempotency mark

3. **Added dedicated per-message handlers**
   - `StartRunHandler`
   - `ApplyCommandHandler`
   - `AdvanceRunHandler`
   - `LlmStepResultHandler`
   - `ToolCallResultHandler`

4. **Extracted shared state/event/message helper utilities**
   - Added `src/Application/Orchestrator/RunMessageStateTools.php`
   - Centralized helper behavior previously in monolithic orchestrator:
     - immutable `RunState` copy helpers
     - event creation/spec expansion
     - stale-result checks/version bump
     - assistant/tool/human message hydration
     - tool-call extraction and interrupt payload parsing

5. **Thinned `RunOrchestrator` entrypoint**
   - Rewrote `src/Application/Orchestrator/RunOrchestrator.php` into a bus entrypoint only:
     - retains `#[AsMessageHandler]` methods + root tracing spans
     - delegates runtime handling to `RunMessageProcessor`
     - removes direct state mutation/event assembly logic from entrypoint methods

6. **Service wiring and architecture docs**
   - Updated `config/services.php`:
     - registers processor/handlers/state-tools services
     - tags `RunMessageHandler` implementations for processor routing
   - Updated `src/Application/AGENTS.md` message routing map to include processor + dedicated handlers.
   - Updated `docs/request-flow.md` to reflect processor/handler/commit architecture (no reducer-centric flow narrative in runtime path).

7. **AI index/docs regeneration + quality gates**
   - Ran `LLM_MODE=true castor dev:check` successfully.
   - Regenerated `src/**/ai-index.toon` and `src/**/docs/*.toon`, including new orchestrator docs:
     - `AdvanceRunHandler.toon`
     - `ApplyCommandHandler.toon`
     - `HandlerResult.toon`
     - `LlmStepResultHandler.toon`
     - `RunMessageHandler.toon`
     - `RunMessageProcessor.toon`
     - `RunMessageStateTools.toon`
     - `StartRunHandler.toon`
     - `ToolCallResultHandler.toon`

---

## 2026-04-21 — Phase 3 completed (runtime de-legacy + handler-shape tests)

Implemented the requested cleanup slice for `implementation/12-refactor-run-orchestrator-deepening.md`.

### ✅ Done in this phase

1. **Removed reducer from runtime path**
   - Reworked `StartRunHandler` to perform direct state transition (no `RunReducer` delegation).
   - Added `RunMessageStateTools::messagesFromPayload()` for shared start-payload hydration.

2. **Removed manual/fallback orchestrator assembly**
   - Simplified `RunOrchestrator` constructor to DI-only dependencies:
     - `RunMessageProcessor`
     - optional `RunTracer`
   - Removed fallback/manual construction branch and obsolete constructor dependencies.
   - Updated `config/services.php` wiring accordingly.

3. **Eliminated obsolete reducer artifacts from active codebase**
   - Removed `src/Application/Reducer/RunReducer.php`.
   - Removed `src/Application/Reducer/ReduceResult.php`.
   - Removed `tests/Application/Reducer/RunReducerTransitionTest.php`.
   - Removed remaining service aliases/import references to reducer internals in test kernel/integration setup.

4. **Added dedicated per-handler unit tests for `HandlerResult` shape**
   - Added:
     - `tests/Application/Orchestrator/StartRunHandlerTest.php`
     - `tests/Application/Orchestrator/ApplyCommandHandlerTest.php`
     - `tests/Application/Orchestrator/AdvanceRunHandlerTest.php`
     - `tests/Application/Orchestrator/LlmStepResultHandlerTest.php`
     - `tests/Application/Orchestrator/ToolCallResultHandlerTest.php`
   - Tests assert key `HandlerResult` dimensions (`nextState`, `events`, `effects`, `postCommitEffects`, `postCommit`, `markHandled`).

5. **Docs + workflow updates**
   - Updated `docs/architecture.md` to describe processor/handler/commit flow and remove reducer-centric narrative.
   - Updated root `AGENTS.md` refactor workflow to require semantic impact mapping via `jetbrains_index_ide_find_references` before text search.

6. **AI index/docs regeneration + quality gates**
   - Ran `LLM_MODE=true castor dev:check` successfully.
   - Regenerated `src/**/ai-index.toon` and `src/**/docs/*.toon` outputs after structural removals and test additions.

---

## Next follow-ups (optional)

- Add schema-level support for DI/wiring edges in generated `.toon` files.
- Add terminology linting for class summaries (event vs command/message/effect role accuracy).
