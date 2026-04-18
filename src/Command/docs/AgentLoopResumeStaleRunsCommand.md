# AgentLoopResumeStaleRunsCommand

**File**: `src/Command/AgentLoopResumeStaleRunsCommand.php`
**Type**: `final class`
**Namespace**: `Ineersa\AgentCore\Command`

## Responsibility

Console command `agent-loop:resume-stale-runs` — finds stale running runs after worker restart, rebuilds hot prompt state if missing, and dispatches `AdvanceRun` to resume them.

## Dependencies

- `RunStoreInterface` — finds stale running runs via `findRunningStaleBefore()`
- `PromptStateStoreInterface` — checks for missing hot prompt state
- `ReplayService` — rebuilds hot prompt state from events when missing
- `RunLockManager` — per-run locking during recovery
- `MessageBusInterface` (command bus) — dispatches `AdvanceRun`

## Configuration

- `staleAfterSeconds` — threshold (default: 120s) via constructor injection, configured from `agent_loop.commands.resume_stale_after_seconds`

## Behavior

1. Queries `RunStoreInterface::findRunningStaleBefore()` for runs with `Running` status older than the stale threshold.
2. For each stale run, acquires per-run lock via `RunLockManager::synchronized()`.
3. Checks if `PromptStateStore` has a prompt state for the run; if not, rebuilds it via `ReplayService`.
4. Dispatches `AdvanceRun` to the command bus to resume the run.
5. Reports count of resumed runs to console output.
