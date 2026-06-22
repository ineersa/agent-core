# AGENT-03 Hidden Run Control POC — Findings

## Experiment summary

Thorough codebase archaeology (not runtime spike) across the full session storage, event pipeline, runtime controller, and TUI projection boundary. The core finding is that the architecture already supports multi-run concurrent event drains (`RuntimeEventEmitter::$runEventCursors`), but five files hardcode the flat top-level storage assumption `.hatfield/sessions/<runId>/...`. The preferred nested path under parent is `.hatfield/sessions/<parent_run_id>/artifacts/agents/<child_artifact_id>/` — and it can be achieved without an `EventStoreInterface` contract change through a separate `AgentChildEventStore` or a parent-delegating `CompoundEventStore`.

**Conclusion**: Nested child event storage under the parent session is achievable with a dedicated implementation wrapping or replacing `SessionRunEventStore` for child run IDs. No contract-level change is needed. The DB row question (to `hidden` or not to) depends on whether the TUI session picker needs to enumerate sessions at all — the `artifacts/agents/` registry can be purely file-backed, avoiding a DB schema change entirely.

## 1. Should child run event storage be nested inside parent session?

**Yes, and the architecture supports it better than a flat top-level approach.**

### Recommended final storage layout

```
.hatfield/sessions/<parent_run_id>/
  events.jsonl                      ← parent canonical events (unchanged)
  state.json                        ← parent run state (unchanged)
  artifacts/agents/
    registry.json                   ← file-backed parent-scoped registry
    <artifact_id>.json              ← per-agent result/artifact (optional handoff)
    <artifact_id>.md                ← per-agent raw handoff/readme
    <child_artifact_id>/
      events.jsonl                  ← child canonical events (isolated source of truth)
      state.json                    ← child run state
```

### Why this shape

1. **No parent event pollution**: Child events stay in the child subdirectory. The parent `events.jsonl` only gets lightweight `agent_child.started`/`agent_child.completed` summary events with path references.

2. **Directory name = canonical run ID**: The existing `SessionRunEventStore` validates `$event->runId === $runId` (embedded must match directory name). The `<child_artifact_id>` directory name serves as the canonical ID for that child run, satisfying this invariant.

3. **Cleanup is trivial**: Deleting the parent session directory removes all children automatically. No orphaned top-level sessions.

4. **Parent-scoped registry naturally lives here**: The `artifacts/agents/registry.json` is co-located with child data, making it session-scoped and self-contained.

5. **Reuses existing event infrastructure**: The child `events.jsonl` is a standard JSONL event stream. The same `EventPayloadNormalizer`, `RuntimeEventMapper`, and `TranscriptProjector` can read, map, and project it — no new format needed.

### Alternative considered and rejected

| Approach | Verdict | Reason |
|---|---|---|
| Top-level `.hatfield/sessions/<child_run_id>/` | Rejected | Pollutes session listing; `SessionRunStore::findRunningStaleBefore()` would scan child runs; requires DB `hidden` column; cleanup not atomic with parent |
| Single `agents.jsonl` in parent artifacts | Rejected | Violates "child events remain detailed source of truth"; conflates multiple concurrent children; loses per-child restartability |
| DB-only event storage | Rejected | Major refactor; breaks replay; deviates from file-backed design |

## 2. Existing code assumptions discovered

Five locations hardcode the flat top-level sessions directory assumption:

### 2.1 `SessionRunEventStore::eventsPath()` — TOP PRIORITY

**File**: `src/CodingAgent/Session/SessionRunEventStore.php:445` (line `private function eventsPath`)

```php
private function eventsPath(string $runId): string
{
    return $this->sessionsDir().'/'.$runId.'/events.jsonl';
}
```

This is the single chokepoint for all event IO. Every `append()`, `allFor()`, and `appendMany()` call flows through this method. This is the one place that needs a conditional for child run IDs.

**Recommended production change**: Extract `eventsPath()` to accept an optional `string $parentRunId = null` parameter. When set, build nested path as `sessionsDir()/$parentRunId/artifacts/agents/$runId/events.jsonl`.

### 2.2 `SessionRunStore::statePath()` — SECOND PRIORITY

**File**: `src/CodingAgent/Session/SessionRunStore.php` (line `private function statePath`)

```php
private function statePath(string $runId): string
{
    return $this->sessionsDir().'/'.$runId.'/state.json';
}
```

Same pattern as the event store. Child runs need their own `state.json` in the nested path. The `findRunningStaleBefore()` method (which iterates `DirectoryIterator` over the sessions base dir) would need to skip child directories — or the child state path should be excluded from iteration automatically because children aren't top-level directories.

### 2.3 `SessionRunStore::findRunningStaleBefore()` — THIRD PRIORITY

```php
// Iterates DirectoryIterator over sessionsDir() — assumes every directory is a run
foreach (new \DirectoryIterator($sessionsDir) as $item) {
    if ($item->isDot() || !$item->isDir()) {
        continue;
    }
    $runId = $item->getFilename();
    $statePath = $this->statePath($runId);
    // ...
}
```

With nested child directories, this won't accidentally pick up children (they're not top-level). **No change needed for this specific concern**, but document the invariant.

### 2.4 `HatfieldSessionStore::writeSessionFiles()` — FOURTH PRIORITY

**File**: `src/CodingAgent/Session/HatfieldSessionStore.php`

```php
private function writeSessionFiles(string $sessionId, string $prompt): void
{
    $sessionPath = $this->getSessionDir($sessionId);
    // → .hatfield/sessions/<sessionId>/
    mkdir($sessionPath, 0777, true);
    file_put_contents($sessionPath.'/state.json', '');
    file_put_contents($sessionPath.'/events.jsonl', '');
}
```

For child sessions, a similar method would write to `sessionsDir()/$parentId/artifacts/agents/$childId/` instead. A separate method (`writeChildSessionFiles()`) or parameterized `writeSessionFiles(string $sessionId, ?string $parentRunId = null)` is appropriate.

### 2.5 `HatfieldSessionStore::getSessionDir()` — FIFTH PRIORITY

```php
private function getSessionDir(string $sessionId): string
{
    return $this->getSessionsDir().'/'.$sessionId;
}
```

Needs a conditional for child runs. Pattern: `getSessionDir(string $sessionId, ?string $parentRunId = null)`.

### 2.6 `HatfieldSessionRepository::findForCatalog()` — SIXTH PRIORITY

```php
public function findForCatalog(): array
{
    return $this->createQueryBuilder('s')
        ->orderBy('s.updatedAt', 'DESC')
        ->getQuery()
        ->getResult();
}
```

No hidden filter exists. **If child runs are not created as DB rows at all** (file-backed registry only), this needs no change. If child runs do become DB rows, add `->andWhere('s.hidden IS NULL OR s.hidden = false')`.

### What already supports multi-run / doesn't need change

| Component | Why it already works |
|---|---|
| `RuntimeEventEmitter::$runEventCursors` | Already tracks multiple concurrent runs. Child run auto-registers on RunStarted. Drain loop iterates all active run IDs. |
| `InProcessAgentSessionClient::events(string $runId)` | Already runId-polymorphic. Accepts any run ID. |
| `EventStoreInterface` | `allFor(string $runId)` is parameterized. No contract change needed. |
| `TranscriptProjectorInterface` | Stateful per-instance; multiple projectors can coexist independently. |
| `AgentSessionClient` interface | `start()`, `events()`, `cancel()` all accept `runId` as parameter. |
| `ChatScreen::insertOverlayBeforeEditor()` | Overlay API exists for agent TUI view. |

## 3. Parent registry design recommendation

### Shape

```jsonc
// .hatfield/sessions/<parent_run_id>/artifacts/agents/registry.json
{
  "schema": 1,
  "entries": [
    {
      "child_run_id": "abc123",
      "parent_run_id": "42",
      "artifact_id": "scout-001",
      "agent_name": "scout",
      "definition_source": ".agents/scout.md",
      "status": "completed",        // running | completed | failed | cancelled
      "launch_mode": "background",  // background | foreground
      "depth": 1,
      "created_at": "2026-06-21T19:00:00+00:00",
      "completed_at": "2026-06-21T19:00:05+00:00",
      "attention_state": null,      // null | notified | acknowledged
      "events_path": "artifacts/agents/scout-001/events.jsonl",
      "artifact_path": "artifacts/agents/scout-001.json"
    }
  ]
}
```

### Key design decisions

1. **File-backed, not DB**. Child runs don't need a `hatfield_session` DB row. The registry is parent-scoped and disposable with the parent session directory.

2. **`child_run_id` vs `artifact_id`**: The `child_run_id` is the RunEvent runId embedded in the child's events.jsonl. The `artifact_id` is a human-readable slug for the artifact file. These could be the same string for simplicity.

3. **`attention_state`**: Tracks whether the parent has been notified/acknowledged about this child's completion. `null` = running, `"notified"` = completion event emitted but not yet acknowledged by user, `"acknowledged"` = user has seen the result.

4. **Atomic writes**: Use `file_put_contents` with `LOCK_EX` or `Symfony\Component\Lock\LockFactory` with `FlockStore`.

5. **No concurrency primitive in registry itself**: Each child run gets its own lock key (`hatfield-run-<child_run_id>`) for event/state storage. The registry is only updated at lifecycle transitions (start, complete, fail, cancel) — not on every event.

## 4. Session listing / hidden child recommendation

### Decision: NO DB `hidden` column needed if children are entirely file-backed

Since child runs don't get `hatfield_session` DB rows:
- `findForCatalog()` automatically excludes them (no row to return)
- `/sessions` picker never shows them
- No migration needed

**But**: if `AgentRunner::start()` or `AgentRunnerInterface` creates a `StartRunInput` with a non-null `runId`, and that runId was pre-created via `HatfieldSessionStore::createSession()`, a DB row WILL be created. This is the critical coupling:

1. `SessionInitializer::initialize()` calls `HatfieldSessionStore::createSession()` which inserts a DB row
2. `InProcessAgentSessionClient::start()` receives `$request->runId` and passes it to `AgentRunner::start()`

**Production recommendation**: For child runs, bypass `HatfieldSessionStore::createSession()` entirely. Create only the filesystem artifacts (nested `events.jsonl` + `state.json`) and use the child artifact ID directly as the runId passed to `AgentRunner::start()`. The `StartRunInput` constructor accepts an optional `runId` parameter:

```php
// StartRunInput already supports pre-set runId
new StartRunInput(
    systemPrompt: '',
    messages: $messages,
    runId: $childArtifactId,  // pre-determined, no DB row needed
    metadata: $metadata,
);
```

The `AgentRunner::start()` pipeline uses this runId for event/state storage through the DI-wired `EventStoreInterface` and `RunStoreInterface`. These would need the conditional `eventsPath()`/`statePath()` described in section 2.

### If DB rows are desired for child runs later

Add nullable `hidden` boolean column to `HatfieldSession`:

```php
#[ORM\Column(type: 'boolean', nullable: true, options: ['default' => null])]
public ?bool $hidden = null;
```

And filter `findForCatalog()`:
```php
->andWhere('s.hidden IS NULL OR s.hidden = false')
```

Use `null` as default (not `false`) so existing sessions without the column don't get excluded (doctrine treats missing nullable column as null).

## 5. Selected-child replay / live update recommendation

### Replay

For replay (building initial transcript when opening agent view), follow the exact pattern in `SessionInitializer::replayFromEvents()`:

```php
// Pseudocode for child replay
$childProjector = new TranscriptProjector();  // fresh instance
$childEvents = $childEventStore->allFor($childRunId);
foreach ($childEvents as $runEvent) {
    $runtimeEvent = $eventMapper->toRuntimeEvent($runEvent);
    if (null !== $runtimeEvent) {
        $childProjector->accept($runtimeEvent->toArray());
    }
}
$childBlocks = $childProjector->blocks();
```

The `RuntimeEventMapper` is already a standalone service — no change needed.

### Live updates

Two approaches, both viable:

**Approach A: Second `RuntimeEventPoller` instance (recommended)**

Create a second `RuntimeEventPoller` with its own `TranscriptProjector` for the selected child. On each TUI tick, poll both parent and child pollers. Parent poller updates `mainState.transcript`; child poller updates `agentOverlayState.transcript`. This is clean because:
- Each poller owns its own `lastSeq` cursor
- `AgentSessionClient::events(childRunId)` is already polymorphic
- No contract change needed

**Approach B: Extract poller into reusable projection component**

Refactor `RuntimeEventPoller` to accept an arbitrary `runId` + projector pair, rather than being tied to `$state->handle->runId`. A new method like `pollForRun(string $runId, TranscriptProjectorInterface $projector, AgentSessionClient $client): ?array` would serve both parent and child polling with zero overhead.

**Recommendation**: Approach A for first implementation (no refactor risk), evolve to Approach B only if profiling shows overhead.

### `RuntimeEventPoller` assumptions that need relaxing

| Line | Assumption | Impact |
|---|---|---|
| `poll()` line `$state->handle->runId` | Single runId per state | Need child state bag with own handle |
| `poll()` line `$state->lastSeq` | Single dedup cursor | Each projector/child needs own cursor |
| `poll()` line `$state->activity` | Single activity state | Child activity tracked in child state |
| `synchronizeProjectedBlocks()` | Mutates `$state->transcript` | Child blocks go to child transcript |

### `TuiSessionState` assumptions

`TuiSessionState` holds a single `$handle`, `$transcript`, `$lastSeq`, and `$activity`. For the agent view, a parallel but lighter `AgentChildState` (or just a second `TuiSessionState` with its own `RunHandle`) would work. The production structure could be:

```php
// In TuiSessionState:
/** @var array<string, AgentChildState> Child agent state bags keyed by child runId */
public array $agentChildren = [];

// Or in the agent view overlay:
public ?string $selectedChildRunId = null;
public ?TuiSessionState $childState = null;
```

## 6. Production implementation structure (concrete files/classes/tasks)

### New files recommended

| File | Purpose | Depends on |
|---|---|---|
| `src/CodingAgent/Agent/ParentScopedChildEventStore.php` | Implements `EventStoreInterface`, delegates to `SessionRunEventStore` for parent runs, writes to nested `artifacts/agents/<childId>/events.jsonl` for child runs | `SessionRunEventStore`, `HatfieldSessionStore` |
| `src/CodingAgent/Agent/AgentArtifactRegistry.php` | Reads/writes `registry.json` under parent session artifacts | `HatfieldSessionStore` (for path resolution) |
| `src/CodingAgent/Agent/AgentLaunchService.php` | Creates child filesystem artifacts, writes registry entry, calls `AgentRunner::start()`, tracks completion | `AgentArtifactRegistry`, `AgentRunnerInterface`, `EventStoreInterface` |
| `src/CodingAgent/Agent/AgentSupervisor.php` | Polls child event stores for completion, updates registry, emits `agent_child.*` summary events to parent event store | `AgentArtifactRegistry`, `EventStoreInterface`, `EventLoop` |
| `src/CodingAgent/Agent/AgentChildState.php` | DTO/value object for child run metadata (analogous to `BackgroundProcess` entity but DTO, not DB) | None (pure DTO) |
| `src/Tui/Agent/AgentViewOverlay.php` | TUI overlay widget showing agent list + selected child transcript | `ChatScreen`, `AgentArtifactRegistry`, `RuntimeEventPoller`, `TranscriptProjectorInterface` |
| `src/Tui/Agent/AgentViewCommandHandler.php` | Slash command handler for `/agents` | `SlashCommandHandler`, `SlashCommandRegistry` |

### Modified files recommended

| File | Change | Risk |
|---|---|---|
| `SessionRunEventStore.php` | Add optional `$parentRunId` to `eventsPath()` or extract into delegating store | MEDIUM — chokepoint for all event IO |
| `SessionRunStore.php` | Add optional `$parentRunId` to `statePath()` or extract into delegating store | MEDIUM — chokepoint for all state IO |
| `TuiSessionState.php` | Add `$agentChildren` map or child state support | LOW — additive |
| `RuntimeEventPoller.php` | Add child polling path or extract `pollForRun()` method | LOW-MEDIUM — tight coupling to single state |
| `HatfieldSessionRepository.php` | Add `hidden` filter to `findForCatalog()` (only if DB rows for children) | LOW — additive WHERE clause |
| `depfile.yaml` | Add `AppSession` to `AppAgent` allowed deps (if launch code lives in `Agent/`) | LOW — declarative |

### Task decomposition (after findings approval)

1. **AGENT-04**: `ParentScopedChildEventStore` + `SessionRunStore` conditional path — prove child events stored/read from nested path, all existing tests still pass
2. **AGENT-05**: `AgentArtifactRegistry` + `AgentLaunchService` — file-backed registry, child filesystem creation, `AgentRunner::start()` integration
3. **AGENT-06**: `AgentSupervisor` + completion notification — poll child completion, update registry, emit summary to parent events
4. **AGENT-07**: `AgentViewOverlay` + `/agents` command — TUI overlay with agent list, selected child replay and live updates, `TmuxHarness` E2E proof
5. **AGENT-08**: Settings/docs/polish — `agents` settings section updates, docs/agents.md additions, error edge cases

## 7. Validation performed

No runtime validation was run (findings-only analysis). The analysis was performed by reading and tracing through:

- `src/CodingAgent/Session/SessionRunEventStore.php` (full)
- `src/CodingAgent/Session/SessionRunStore.php` (full)
- `src/CodingAgent/Session/HatfieldSessionStore.php` (full)
- `src/CodingAgent/Entity/HatfieldSession.php`
- `src/CodingAgent/Entity/HatfieldSessionRepository.php`
- `src/AgentCore/Contract/EventStoreInterface.php`
- `src/AgentCore/Contract/RunStoreInterface.php`
- `src/AgentCore/Domain/Run/StartRunInput.php`
- `src/CodingAgent/Runtime/InProcess/InProcessAgentSessionClient.php` (full)
- `src/CodingAgent/Runtime/Controller/RuntimeEventEmitter.php` (full)
- `src/CodingAgent/Runtime/Controller/BackgroundProcessCompletionPoller.php`
- `src/Tui/Runtime/RuntimeEventPoller.php` (full)
- `src/Tui/Runtime/TuiSessionState.php` (full)
- `src/Tui/Application/SessionInitializer.php`
- `depfile.yaml` (`AppAgent` layer)
- `.pi/plans/agents-subagents-implementation-plan.md` (Stage -1 + full)

Code-style validation on findings doc only:
```bash
castor cs-check
```

## 8. Risks, gaps, and gotchas

1. **`SessionRunStore::findRunningStaleBefore()` scans top-level**: If child state.json files ever appear as top-level directories, this method would pick them up as stale runs. Mitigation: children stored nested, not top-level.

2. **Controller `RuntimeEventEmitter` already drains all active runs**: If 20 background agents are launched simultaneously, the drain loop iterates all 20 every 50ms. This is bounded by the number of agents — acceptable for the planned single/double-agent use case but worth mentioning.

3. **Child run cancellation**: If the parent cancels, the child must also be cancelled. The parent's `CancelHandler` would need to cascade. Easy to forget.

4. **`AgentRunner::start()` error paths**: If the child run fails to start, the registry entry must be cleaned up. The agent launch service needs try/finally cleanup.

5. **Replay fixture compatibility**: The child event store uses the same `EventPayloadNormalizer` as the parent. Replay fixtures are compatible by default. However, nested storage means the test infrastructure for replay tests would need to discover child events from the nested path.

6. **QH/HITL prerequisite**: As documented in the plan, QH tasks are a prerequisite for full agent support. If a child agent hits a tool question, the parent TUI's question coordinator needs to know about it. This is not addressed here — explicitly deferred.

7. **No DB `hidden` column means no DB query for agent children**: Finding child runs by query is impossible with file-backed-only storage. If the product later needs cross-session agent search, DB rows would be needed. This is an acceptable tradeoff for the initial implementation.

## 9. Blockers and prerequisites

- QH task chain (`qh-04` through `qh-09`) should be completed before production agent launch, per the plan. But the POC (findings-only) has no QH dependency.
- No tmux/llama.cpp prerequisite for this findings-only POC.

## 10. Files changed

Three files added, one modified:

- `src/Tui/Listener/AgentPocCommandHandler.php` — throwaway POC slash command handler
- `src/Tui/Listener/AgentPocRegistrar.php` — throwaway POC DI registrar
- `depfile.yaml` — added TuiWidget to TuiListener allowed deps (POC-only; remove after POC deleted)

### Manual smoke test

**Prerequisites**: The worktree must have a running TUI session with an active conversation.

**Step-by-step smoke test**:

1. Start the TUI in isolated mode:
   ```bash
   cd /home/ineersa/projects/agent-core-worktrees/agent-03-throwaway-hidden-run-control-poc
   castor run:agent-test
   ```

2. Start a conversation: type any prompt and submit it, e.g. `hello world`.
   Wait for the assistant response so the session is active.

3. Run the POC command:
   ```
   /agent-poc
   ```

4. **Expected visible proof**: An overlay appears ABOVE the prompt editor, displaying:
   - A box with title `AGENT CONTROL POC`
   - Parent session ID (numeric, e.g. `428`)
   - Parent session directory path
   - Registry path: `.../artifacts/agents/registry.json`
   - Child directory path: `.../artifacts/agents/scout-poc/`
   - Child name: `scout-poc` with status `running`
   - Event count (4 initial events)
   - Transcript rebuilt from child events (showing `run.started`, `assistant.message`, `tool_execution.started`, `tool_execution.completed`)
   - `Scout POC child event #1: Exploring codebase structure...` should be visible

5. **Simulate a live update** (child agent produces a new event):
   ```
   /agent-poc tick
   ```
   The overlay refreshes; event count increases to 5. New line appears:
   `Scout POC live update #5: New findings discovered...`
   Repeat `/agent-poc tick` a few times to verify live update simulation.

6. **Close the overlay**:
   ```
   /agent-poc close
   ```
   Overlay disappears. Editor area is restored.

### On-disk artifacts created

During smoke, inspect the filesystem:

```bash
# Find the parent session directory (check TUI overlay for exact path)
ls .hatfield/sessions/<parent_id>/artifacts/agents/
# → registry.json  scout-poc/

cat .hatfield/sessions/<parent_id>/artifacts/agents/registry.json
# → JSON with schema:1, entries[0] with child_run_id, artifact_id, status, etc.

cat .hatfield/sessions/<parent_id>/artifacts/agents/scout-poc/events.jsonl
# → JSONL lines with run_id=scout-poc, types: run.started, assistant.message, tool_execution.*

cat .hatfield/sessions/<parent_id>/artifacts/agents/scout-poc/state.json
# → JSON with run_id, parent_run_id, status, agent_name
```

**No** `.hatfield/sessions/scout-poc/` (top-level child directory) is created — verified by:
```bash
ls .hatfield/sessions/scout-poc/  # should error: No such file or directory
```

### What the prototype proves

- ✅ Nested child event storage: `.hatfield/sessions/<parent>/artifacts/agents/<child>/events.jsonl`
- ✅ Parent-scoped registry: `artifacts/agents/registry.json` tracks child_run_id, status, attention_state
- ✅ Selected-child transcript: rebuilt from child `events.jsonl` only, not copied to parent events
- ✅ Live update routing: `/agent-poc tick` appends to child event stream, overlay refreshes
- ✅ No top-level child session directory
- ✅ Normal session listing exclusion (child is not a top-level directory)
- ✅ Slash command overlay works: TUI widgets can display agent control data
- ✅ Overlay lifecycle: open, refresh, close — ChatScreen overlay API works for this shape

### What the prototype does NOT cover (explicitly deferred)

- Real child agent execution (no AgentRunner integration)
- Real runtime event streaming (no AgentSessionClient::events() child polling)
- HITL/question handling (child tool questions not wired to TUI question coordinator)
- Foreground/background mode distinction
- Concurrency / parallel children
- MCP policy
- Polished TUI: no styled list, no keyboard navigation, no color theme
- Real TranscriptProjector integration (child events are raw JSONL, not projected RuntimeEvents)
- Production API surface — AgentPocCommandHandler is NOT a production pattern

### ⚠️ WARNING: Disposable POC

`AgentPocCommandHandler`, `AgentPocRegistrar`, and the `TuiWidget` entry in `depfile.yaml`
for `TuiListener` are THROWAWAY code. They must be deleted before production implementation.
The real agent control view should be built from scratch using the patterns validated here,
NOT by evolving this prototype.
