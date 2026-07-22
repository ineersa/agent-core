# Replace child backfill polling with replay-on-enter + live stream routing

## Linked task

Task board entry:

```text
TODO/replace-child-backfill-polling-with-replay-on-enter-live-stream-routing.md
```

This task is intentionally separate from `FORK-MVP-01: Implement fork tool over child-run backend`.
The fork MVP branch contains tactical fixes that made current manual testing less broken, but this plan describes the cleaner architecture that should replace the backfill/polling workaround.

---

## Executive summary

`/agents-live` child view should work like `/resume` for a child run:

1. When the user opens a child live view, rebuild that child view once from canonical child `RunEvent`s.
2. Set a cursor to the last replayed child sequence number.
3. While the child view is active, consume normal controller `RuntimeEvent`s for that child `run_id`.
4. Render transcript, status, tool output, and HITL from those runtime events.
5. Never poll or repeatedly read child `events.jsonl` on TUI ticks.

The current design uses `BackfillEventProviderInterface` / `ChildRunBackfillEventProvider` inside `SubagentLiveChildViewPoller::poll()`. That means the selected child view reads child durable event storage repeatedly during ticks, then merges those stored events with live client events. This is conceptually wrong and easy to break:

- canonical storage is for replay/resume/export/repair;
- controller stdout is the live runtime event bus;
- TUI tick loops must not turn `events.jsonl` into a polling transport.

The target design removes per-tick child backfill. The child live view becomes a normal replay + stream projection.

---

## Vocabulary

### Parent run

The main session/run shown in the normal Hatfield TUI.

Usually:

```text
run_id === session_id
.hatfield/sessions/<session_id>/events.jsonl
```

### Child run

A subagent, scout, fork, or nested child-agent run.

It has its own `run_id`, but its events are commonly stored under the parent session artifact tree:

```text
.hatfield/sessions/<parent_session_id>/artifacts/agents/<artifact_id>/events.jsonl
```

The important invariant is semantic, not filesystem layout:

> A child run has a canonical `RunEvent` stream keyed by child `run_id`.

### Canonical event

A durable `RunEvent` persisted to an `EventStoreInterface` implementation.

Canonical events are used for:

- resume;
- replay;
- export;
- repair;
- durable audit/debug state.

They are **not** the TUI live transport.

### Runtime event

A `RuntimeEvent` sent over the controller JSONL protocol to the TUI.

Runtime events are used for:

- live transcript updates;
- live status/activity updates;
- HITL question routing;
- token/model/cost usage updates;
- tool progress/output.

### Replay-on-enter

One-time canonical reconstruction of a child live view when the user selects a child in `/agents-live`.

Replay-on-enter is equivalent to `/resume`, but scoped to a child `run_id` and projected into the child live view instead of the main transcript.

### Backfill

Current workaround name for reading child stored events from disk during child live-view ticks.

This plan replaces that concept. The desired terms are:

- `snapshot` / `replay` for one-time canonical reconstruction;
- `runtime stream` for live events after the replay cursor.

---

## Current architecture: what actually happens today

### Backend event path

The backend already has most of the correct machinery.

```text
AgentCore / consumer writes RunEvent
        │
        ▼
StreamingCommittedRuntimeEventStore
        │
        ├─ persist through ChildAwareEventStore
        │     │
        │     ├─ parent run_id
        │     │    → SessionRunEventStore
        │     │    → .hatfield/sessions/<session>/events.jsonl
        │     │
        │     └─ child run_id
        │          → AgentChildRunEventStore
        │          → .hatfield/sessions/<session>/artifacts/agents/<artifact>/events.jsonl
        │
        └─ RuntimeEventMapper::toRuntimeEvent()
             preserves run_id
             │
             ▼
           RuntimeEventSinkInterface
             │
             ▼
           consumer stdout JSONL
             │
             ▼
           ConsumerStdoutPoller
             │
             ▼
           RuntimeEventEmitter
             │
             ▼
           controller stdout JSONL
             │
             ▼
           JsonlProcessAgentSessionClient
```

Relevant files:

```text
src/CodingAgent/Runtime/Stream/StreamingCommittedRuntimeEventStore.php
src/CodingAgent/Agent/Artifact/ChildAwareEventStore.php
src/CodingAgent/Agent/Artifact/AgentChildRunEventStore.php
src/CodingAgent/Runtime/Protocol/RuntimeEventMapper.php
src/CodingAgent/Runtime/Controller/ConsumerStdoutPoller.php
src/CodingAgent/Runtime/Controller/RuntimeEventEmitter.php
src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php
```

Important point:

> When a child `RunEvent` is appended through `StreamingCommittedRuntimeEventStore`, it is persisted to child artifact storage and also emitted as a `RuntimeEvent` with the same child `run_id`.

This means the controller stream can carry parent and child events using the same protocol.

### TUI client partitioning

`JsonlProcessAgentSessionClient::events(string $runId)` currently partitions events by `run_id`.

Conceptually:

```php
foreach ($this->readEvents() as $event) {
    if ($event->runId === $runId) {
        yield $event;
    } else {
        $this->bufferEvent($event, 'read_events');
    }
}
```

So if the main poller calls:

```php
$client->events($parentRunId)
```

and the controller stream contains:

```text
RuntimeEvent(run_id = parent)
RuntimeEvent(run_id = child_a)
RuntimeEvent(run_id = child_b)
```

then:

```text
parent event  → yielded now
child_a event → buffered under child_a
child_b event → buffered under child_b
```

Later, if the selected child view calls:

```php
$client->events($childRunId)
```

then buffered child events are drained.

This partitioning is not inherently wrong. It can support the target architecture if the child live view uses it as the live event source after replay-on-enter.

### Current wrong child live view flow

The current selected child view does this on ticks:

```text
SubagentLiveChildViewPoller::poll()
        │
        ├─ ChildRunBackfillEventProvider::getStoredEvents(childRunId)
        │     reads child canonical event store
        │     maps stored RunEvents to RuntimeEvents
        │
        ├─ AgentSessionClient::events(childRunId)
        │     drains live/buffered RuntimeEvents from controller stream
        │
        ├─ merge backfill + live events
        ├─ dedupe using childLastSeq
        └─ project/render child transcript
```

This is the problematic part:

```text
every TUI tick while selected
  → read child events.jsonl again
```

That turns canonical event storage into a live polling transport.

### Current background poller flow

`SubagentLiveBackgroundChildPoller` exists to keep catalog/attention updated for child runs when the child live view is not active.

It must **never** read child canonical event files.

The recent tactical fix removed its backfill usage. That direction should be preserved.

Background poller may drain live buffered/runtime events for known child run IDs, but it must not poll durable storage.

---

## Why the current backfill design is wrong

### 1. It duplicates `/resume`

Parent resume already has a conceptual pipeline:

```text
canonical RunEvents
  → map/replay/project
  → transcript/activity state
  → cursor
  → live runtime stream after cursor
```

Child live view should use the same idea.

The current backfill code reimplements parts of this pipeline inside a tick poller.

### 2. It makes disk files a live event bus

This is explicitly against the runtime architecture direction:

```text
events.jsonl = durable persistence/recovery/export/replay
controller stdout = live runtime stream
```

Per-tick `EventStoreInterface::allFor($childRunId)` is exactly the pattern previously identified as a source of memory/CPU problems when used broadly.

Even if selected-child scope is smaller than global polling, it is still the wrong abstraction.

### 3. It creates ownership bugs

The previous regressions came from unclear ownership:

- background poller consumed child stored HITL before live view opened;
- main view guard dropped child question correctly, but stored event was already consumed;
- selected live view later lacked the question;
- re-readable backfill then fixed post-answer events tactically, but by making disk reads happen repeatedly.

These are symptoms of mixing two responsibilities:

- canonical replay ownership;
- live runtime event ownership.

### 4. It creates misleading tests

Tests named around `BackfillEventProvider` now encode implementation quirks instead of the user-visible contract.

The desired tests should talk about:

- replay-on-enter;
- live events after replay;
- run_id routing;
- background poller not touching canonical storage.

---

## Target architecture

### High-level diagram

```text
                              ┌─────────────────────────────┐
                              │ Controller stdout JSONL      │
                              │ RuntimeEvent stream          │
                              │                             │
                              │ run_id=parent               │
                              │ run_id=child_a              │
                              │ run_id=child_b              │
                              └──────────────┬──────────────┘
                                             │
                                             ▼
                          ┌──────────────────────────────────┐
                          │ JsonlProcessAgentSessionClient   │
                          │ buffers/routes by run_id         │
                          └──────────────┬───────────────────┘
                                         │
             ┌───────────────────────────┴────────────────────────────┐
             │                                                        │
             ▼                                                        ▼
┌──────────────────────────────┐                         ┌──────────────────────────────┐
│ Main RuntimeEventPoller       │                         │ Selected child live view      │
│ events(parentRunId)           │                         │ events(childRunId)            │
│ projects main transcript      │                         │ projects child transcript     │
└──────────────────────────────┘                         └──────────────────────────────┘
```

### Entering child live view

```text
User selects scout in /agents-live
        │
        ▼
ChildRunTranscriptSnapshotProvider::snapshot(childRunId)
        │
        ├─ EventStoreInterface::allFor(childRunId)
        │     resolved through ChildAwareEventStore
        │
        ├─ RuntimeEventMapper::toRuntimeEvent()
        │
        ├─ isolated TranscriptProjector
        │
        ├─ TuiRuntimeEventApplier in replay mode / child scratch state
        │
        └─ returns:
             - transcript blocks
             - replay runtime events if needed
             - maxSeq
             - derived child activity/status
             - active child HITL question if present
        │
        ▼
SubagentLiveViewState
        │
        ├─ selected = child DTO
        ├─ childTranscript = snapshot.blocks
        ├─ childLastSeq = snapshot.maxSeq
        ├─ childActivity = snapshot.activity
        └─ cache keyed by childRunId
```

### Tick while child live view is active

```text
SubagentLiveChildViewPoller::poll()
        │
        ├─ client.events(selectedChildRunId)
        │     drains live/buffered RuntimeEvents only
        │
        ├─ skip seq <= childLastSeq
        ├─ apply through child-view event applier/projector
        ├─ update childLastSeq
        └─ return changed transcript blocks
```

No event store access on tick.

### Background/catalog tick

```text
SubagentLiveBackgroundChildPoller
        │
        ├─ may drain live events(childRunId) for known active child runs
        ├─ may update catalog/attention from runtime events
        └─ must not call EventStoreInterface::allFor()
```

If catalog can be fully maintained from parent `subagent_progress` and child lifecycle RuntimeEvents observed on the pipe, prefer that. Do not add canonical file polling.

---

## Desired invariants

### Runtime/event invariants

1. Every committed `RunEvent` that maps to a `RuntimeEvent` is emitted to the controller stream with the same `run_id`.
2. Parent and child events use the same runtime protocol.
3. The TUI does not need a special child file polling channel to observe live child events.
4. `JsonlProcessAgentSessionClient` may partition/buffer by run ID, but events are not lost when a different run ID is polled first.

### Replay invariants

1. Canonical child events are read when entering/resuming a child live view.
2. Canonical child events are not read on normal TUI ticks.
3. Replay sets the selected child cursor to the max replayed `seq`.
4. Runtime events with `seq <= cursor` are ignored after replay.
5. Transient runtime events with `seq = 0` remain allowed and do not advance canonical cursor.

### TUI ownership invariants

1. Parent/main view only mounts parent-owned HITL overlays.
2. Child-owned HITL overlays mount only when the selected child live view is active for that run ID.
3. Main view may show attention that a child needs input, but must not consume the question.
4. ESC/submit behavior is scoped to the visible active question/view.
5. Background polling must never enqueue hidden child questions into the global `QuestionCoordinator` while main view is active.

### Performance invariants

1. No `events.jsonl` polling in tick loops.
2. No broad RuntimeEventEmitter file drain loop over all completed runs.
3. No O(number of children × full event file size) work per TUI tick.
4. Selected child live view may process live buffered runtime events, not repeatedly full-read durable storage.

---

## Proposed implementation phases

This should be implemented as a separate task. Do not fold it into the fork MVP branch unless explicitly requested.

### Phase 0 — verify current branch state and choose base

Start from `main` unless the user explicitly asks to base on another branch.

Before implementation:

```bash
git status --short --branch
```

Expected:

```text
## main...origin/main
```

If there are local modifications, stop and ask.

Load required docs:

```text
.agents/skills/testing/SKILL.md
tests/AGENTS.md
```

Required because this touches TUI/runtime tests.

---

### Phase 1 — lock down live runtime stream routing

Goal: prove the controller/client stream can carry child run IDs without file polling.

#### Files to inspect

```text
src/CodingAgent/Runtime/Stream/StreamingCommittedRuntimeEventStore.php
src/CodingAgent/Agent/Artifact/ChildAwareEventStore.php
src/CodingAgent/Agent/Artifact/AgentChildRunEventStore.php
src/CodingAgent/Runtime/Controller/ConsumerStdoutPoller.php
src/CodingAgent/Runtime/Controller/RuntimeEventEmitter.php
src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php
tests/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClientEventBufferTest.php
```

#### Expected implementation work

Likely no production change is needed if the following already holds:

```text
parent poll reads child event first → child event is buffered by run_id
child poll later asks events(childRunId) → child event is yielded
```

If existing tests do not prove this strongly enough, add/extend tests.

#### Test thesis

> Runtime events for non-requested run IDs are buffered and later delivered when that run ID is requested, so the TUI can route one controller stream into parent and child views without polling child event files.

#### Suggested tests

Add or extend:

```text
tests/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClientEventBufferTest.php
```

Cases:

1. `events(parentRunId)` buffers child event.
2. `events(childRunId)` later drains buffered child event.
3. Multiple child run IDs remain isolated.
4. Buffer cap behavior remains safe and logged/rate-limited if applicable.

#### Validation

```bash
castor test --filter=JsonlProcessAgentSessionClientEventBufferTest
```

---

### Phase 2 — introduce child run snapshot/replay service

Goal: replace `BackfillEventProvider` terminology with a proper replay/snapshot abstraction.

#### New service name options

Preferred:

```text
ChildRunTranscriptSnapshotProvider
```

Alternative:

```text
ChildRunReplayService
ChildRunViewSnapshotProvider
RunViewSnapshotProvider
```

Use explicit suffixes per project convention. Avoid ambiguous names like `BackfillProvider` or `ChildLoader`.

#### Suggested interface

Location should respect boundaries. TUI must depend only on `CodingAgent/Runtime/Contract` and `Protocol`, not app internals.

Suggested contract:

```php
namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

final readonly class RunTranscriptSnapshotDTO
{
    /** @param list<TranscriptBlock> $transcriptBlocks */
    /** @param list<RuntimeEvent> $replayEvents */
    public function __construct(
        public array $transcriptBlocks,
        public array $replayEvents,
        public int $maxSeq,
    ) {}
}

interface ChildRunTranscriptSnapshotProviderInterface
{
    public function snapshot(string $runId): RunTranscriptSnapshotDTO;
}
```

If `SessionTranscriptSnapshotDTO` can be reused safely, prefer reuse instead of adding duplicate DTOs. But ensure it exposes or can derive `maxSeq` cleanly.

#### Implementation responsibilities

Implementation should:

1. call `EventStoreInterface::allFor($childRunId)`;
2. rely on `ChildAwareEventStore` to resolve parent/child storage;
3. map `RunEvent` to `RuntimeEvent` via `RuntimeEventMapper`;
4. feed events into an isolated `TranscriptProjector`;
5. return transcript blocks and max canonical seq.

Pseudo-code:

```php
public function snapshot(string $runId): RunTranscriptSnapshotDTO
{
    $runEvents = $this->eventStore->allFor($runId);

    $runtimeEvents = [];
    $maxSeq = 0;

    foreach ($runEvents as $runEvent) {
        $runtimeEvent = $this->mapper->toRuntimeEvent($runEvent);
        if (null === $runtimeEvent) {
            continue;
        }

        $runtimeEvents[] = $runtimeEvent;
        if ($runtimeEvent->seq > $maxSeq) {
            $maxSeq = $runtimeEvent->seq;
        }
    }

    $projector = $this->projectorFactory->create(); // preferred isolated state
    foreach ($runtimeEvents as $runtimeEvent) {
        $projector->accept($runtimeEvent->toArray());
    }

    return new RunTranscriptSnapshotDTO(
        transcriptBlocks: $projector->blocks(),
        replayEvents: $runtimeEvents,
        maxSeq: $maxSeq,
    );
}
```

#### Important detail: projector isolation

Do **not** reuse the main TUI `TranscriptProjector` singleton for child replay if resetting it can corrupt parent projection state.

Preferred approaches:

1. Add a factory for isolated projector instances.
2. Reuse the same isolated pattern already used by `SessionTranscriptProvider` if available.
3. If the current projector service is stateful and singleton-scoped, avoid injecting that same instance into both main and child view appliers.

Files to inspect:

```text
src/CodingAgent/Runtime/ProjectionPipeline/TranscriptProjector.php
src/CodingAgent/Session/SessionTranscriptProvider.php
src/CodingAgent/Runtime/Contract/SessionTranscriptProviderInterface.php
src/Tui/Application/SessionInitializer.php
src/Tui/Runtime/TuiRuntimeEventApplier.php
```

#### Test thesis

> Opening a child live view can reconstruct the child transcript from canonical child events exactly once, without using the live controller stream.

Suggested tests:

```text
tests/CodingAgent/Runtime/ChildRunTranscriptSnapshotProviderTest.php
```

or if TUI-facing:

```text
tests/Tui/Runtime/SubagentLiveChildReplayOnEnterTest.php
```

Use existing test infrastructure for temporary `.hatfield` trees and event stores. Do not hand-roll temp directory cleanup.

---

### Phase 3 — move replay to live-view entry

Goal: child canonical replay happens when the user selects/enters a child live view, not during ticks.

#### Files to inspect

```text
src/Tui/Picker/SubagentLivePickerController.php
src/Tui/Runtime/SubagentLiveViewState.php
src/Tui/Runtime/SubagentLiveChildViewPoller.php
src/Tui/Listener/TickPollListener.php
```

#### Desired flow

When picker enters a child:

```text
SubagentLivePickerController::enterLiveView(child)
        │
        ├─ snapshotProvider->snapshot(child.agentRunId)
        ├─ subagentLiveView->enter(child, snapshot)
        ├─ childLastSeq = snapshot.maxSeq
        ├─ childTranscript = snapshot.transcriptBlocks
        └─ screen->setTranscriptBlocks(snapshot.transcriptBlocks)
```

The exact method boundaries can differ, but the responsibilities must be clear:

- view entry owns replay;
- tick poller owns live runtime events only;
- state owns cursor/cache.

#### Suggested state change

`SubagentLiveViewState::enter()` may need a snapshot parameter.

Example:

```php
public function enter(SubagentLiveChildDTO $child, ?RunTranscriptSnapshotDTO $snapshot = null): void
{
    $this->active = true;
    $this->selected = $child;

    if (null !== $snapshot) {
        $this->childTranscript = $snapshot->transcriptBlocks;
        $this->childLastSeq = $snapshot->maxSeq;
        $this->childActivity = $this->deriveActivityFromReplay($snapshot->replayEvents);
        $this->cacheFor($child->agentRunId, ...);
    }
}
```

Alternatively, keep `enter()` simple and add explicit setters. Prefer whichever keeps tests readable and avoids broad state mutation.

#### HITL during replay

Stored child HITL must be surfaced when entering the selected child view.

Two options:

1. During replay-on-enter, feed replay events through `TuiRuntimeEventApplier` and `RuntimeQuestionEventHandler` callbacks while `subagentLiveView->active === true`.
2. Derive active HITL from replay events and explicitly enqueue it after snapshot.

Prefer option 1 if it reuses existing event handling and preserves behavior.

Important:

- child HITL must not mount on main view;
- replay happens only after the child view is active;
- answer callback must target the child `run_id`.

#### Test thesis

> Entering a child live view with stored `human_input.requested` canonical event mounts the child question in that live view, not on the main view, and sets cursor to the replay max seq.

---

### Phase 4 — remove per-tick backfill from `SubagentLiveChildViewPoller`

Goal: `SubagentLiveChildViewPoller::poll()` becomes live-stream-only.

#### Current shape to remove

Current/tactical shape:

```php
$backfillEvents = $this->backfillProvider?->getStoredEvents($live->selected->agentRunId) ?? [];
$events = $this->runtimeEvents($client, $live->selected->agentRunId);
$events = array_merge($backfillEvents, $events);
```

Target shape:

```php
$events = $this->runtimeEvents($client, $live->selected->agentRunId);
if ([] === $events) {
    return null;
}

foreach ($events as $runtimeEvent) {
    if (0 !== $runtimeEvent->seq && $runtimeEvent->seq <= $live->childLastSeq) {
        continue;
    }

    if (0 !== $runtimeEvent->seq) {
        $live->childLastSeq = $runtimeEvent->seq;
    }

    $this->childEventApplier->apply($childScratchState, $runtimeEvent);
}
```

#### Constructor cleanup

Remove from `SubagentLiveChildViewPoller`:

```text
BackfillEventProviderInterface $backfillProvider
```

And remove wiring from:

```text
config/services.yaml
```

Do not delete the old provider until no callers remain.

#### Test thesis

> After replay-on-enter, child live-view ticks consume only runtime events from `AgentSessionClient::events(childRunId)` and never touch canonical storage.

Tests should fail if `EventStoreInterface::allFor()` or old backfill provider is called from `poll()`.

---

### Phase 5 — remove or rename backfill types

Once per-tick backfill is removed and replay-on-enter is implemented, remove obsolete names.

Likely deletions:

```text
src/CodingAgent/Runtime/Contract/BackfillEventProviderInterface.php
src/CodingAgent/Runtime/Process/ChildRunBackfillEventProvider.php
```

Only delete them if their replacement service fully covers the one-time snapshot/replay use case.

If keeping temporarily, rename to match its real purpose:

```text
ChildRunTranscriptSnapshotProviderInterface
ChildRunTranscriptSnapshotProvider
```

Avoid keeping a `Backfill*` name for a replay service. It perpetuates the wrong mental model.

Update service wiring:

```text
config/services.yaml
```

Update tests and references.

---

### Phase 6 — background/catalog behavior

Goal: ensure background behavior does not reintroduce file polling.

#### Files

```text
src/Tui/Runtime/SubagentLiveBackgroundChildPoller.php
src/Tui/Runtime/SubagentLiveCatalog.php
src/Tui/Runtime/SubagentLiveAttention.php
src/Tui/Listener/RuntimeQuestionEventHandler.php
src/Tui/Listener/TickPollListener.php
```

#### Desired behavior

Background poller may:

- drain live buffered runtime events for known active child runs;
- update catalog/attention from child runtime events;
- detect waiting-human state from live events;
- keep main view free of child HITL overlays.

Background poller must not:

- call EventStore directly;
- call snapshot/replay service;
- enqueue hidden child questions into the global `QuestionCoordinator` while main view is active;
- consume canonical events needed for child live-view replay.

#### Test thesis

> Background child polling updates attention/catalog from runtime events without reading canonical child event storage and without mounting a child question overlay on the main view.

Existing tests to preserve/adapt:

```text
tests/Tui/Runtime/SubagentLiveBackgroundChildPollerTest.php
```

---

### Phase 7 — projector isolation

Goal: prevent parent and child transcript projection state from corrupting each other.

#### Problem

Scouts noted that child live-view applier and parent runtime applier may share a stateful `TranscriptProjector` instance depending on wiring.

If child replay resets the projector, it must not reset the parent transcript projector.

#### Desired model

```text
parent/main view projector state
child selected live view projector state
```

They may use the same class/factory, but not the same mutable instance.

#### Implementation options

1. Add a `TranscriptProjectorFactory` if one does not exist.
2. Reuse isolated projector creation from `SessionTranscriptProvider` if it already has a safe pattern.
3. Make `TuiRuntimeEventApplier` receive a projector instance that is explicitly scoped to the state/view it mutates.

Avoid production APIs added solely for tests.

#### Test thesis

> Replaying a child live view does not modify the parent transcript blocks/projector state.

---

## Tests to add or rewrite

### 1. Replace backfill-specific tests

Current likely file:

```text
tests/Tui/Runtime/SubagentLiveChildViewPollerBackfillTest.php
```

This file should be renamed/reworked into something like:

```text
tests/Tui/Runtime/SubagentLiveChildViewReplayOnEnterTest.php
```

Old test concepts to remove:

```text
- poll() calls getStoredEvents()
- second poll reads newly appended stored events
- backfill/live merge ordering
- backfill/live duplicate dedupe
```

New test concepts:

```text
- entering selected child live view snapshots canonical events once
- replay sets childLastSeq to max seq
- replay mounts stored child HITL only when child live view is active
- subsequent poll uses live client events only
- no EventStore/backfill call happens on subsequent ticks
- live event seq <= childLastSeq is skipped
- live event seq > childLastSeq updates transcript/activity
```

### 2. Client routing test

File:

```text
tests/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClientEventBufferTest.php
```

Add/confirm:

```text
parent poll buffers child event;
child poll drains child event;
multiple child run IDs are isolated;
buffer cap behavior remains bounded.
```

### 3. Controller stream test

If not already covered, add a controller-level test proving child `RuntimeEvent`s are emitted to the controller JSONL stream.

Candidate files:

```text
tests/CodingAgent/Runtime/Controller/RuntimeEventEmitterTest.php
tests/CodingAgent/Runtime/Stream/StreamingCommittedRuntimeEventStoreTest.php
```

Test thesis:

> A child `RunEvent` appended through the streaming committed event store is emitted as a `RuntimeEvent` with the child `run_id`.

This should not require live LLM.

### 4. TUI scenario test

File:

```text
tests/Tui/Scenario/SubagentLiveHitlScenarioTest.php
```

Add or adapt scenario:

```text
main view has child waiting attention;
main view has no child question overlay;
user enters child live view;
canonical child replay mounts stored HITL;
answer sends answer_human to child run_id;
post-entry live RuntimeEvent updates transcript/completion;
main parent transcript is not polluted.
```

### 5. TUI replay smoke

Existing:

```text
tests/Tui/E2E/TuiSubagentChildHitlCancellationE2eTest.php
```

Keep passing. This verifies terminal/tmux integration and replay fixture flow.

### 6. Live LLM/controller tests

Keep or add focused live tests because replay has lied before.

Existing important tests:

```text
tests/CodingAgent/Runtime/Controller/E2E/SubagentScoutHitlLiveE2eTest.php
tests/CodingAgent/Runtime/Controller/E2E/ForkHitlLiveE2eTest.php
tests/CodingAgent/Runtime/Controller/E2E/SubagentParallelLiveE2eTest.php
```

These should assert runtime protocol, not prose.

Desired live contracts:

1. direct scout emits child progress and child HITL on controller stream;
2. direct fork child HITL emits on controller stream;
3. fork → scout nested child HITL emits on controller stream;
4. no reliance on child file polling for live updates.

---

## Castor validation plan

All validation must use Castor.

Focused validation during implementation:

```bash
castor test --filter=JsonlProcessAgentSessionClientEventBufferTest
castor test --filter=StreamingCommittedRuntimeEventStoreTest
castor test --filter=RuntimeEventEmitterTest
castor test --filter=SubagentLiveChildView
castor test --filter=SubagentLiveBackgroundChildPoller
castor test --filter=SubagentLiveHitlScenarioTest
castor test --filter=RuntimeQuestionEventHandlerTest
castor test:tui --filter=TuiSubagentChildHitlCancellationE2eTest
castor phpstan
castor deptrac
castor cs-check
```

Live smoke when the implementation is ready and proxy is warm:

```bash
castor test:llm-real --filter=SubagentScoutHitlLiveE2eTest
castor test:llm-real --filter=ForkHitlLiveE2eTest
```

Before CODE-REVIEW:

```bash
castor check
```

If `castor check` cannot run because prerequisites are unavailable, do not move the task to CODE-REVIEW. Record the blocker.

---

## Manual smoke checklist

After automated focused validation, manually test:

### Direct scout HITL

1. Start a normal session.
2. Ask a scout/subagent to list docs and ask which file to summarize.
3. Confirm main view shows child attention, not a child question overlay.
4. Open `/agents-live`.
5. Select scout.
6. Confirm stored transcript and question render immediately from replay-on-enter.
7. Answer the question.
8. Confirm subsequent scout tool/output/completion events stream live in the scout view.
9. Return to main and reopen scout.
10. Confirm completed scout transcript renders from canonical replay.

### Fork HITL

1. Launch a fork that asks a question.
2. Confirm fork appears in `/agents-live`.
3. Enter fork live view.
4. Confirm question ownership and answer routing.
5. Confirm post-answer streaming/completion.

### Fork → scout HITL

1. Launch a fork that launches scout.
2. Confirm nested scout appears in `/agents-live` global catalog.
3. Enter nested scout view.
4. Confirm scout question and post-answer events render.
5. Confirm main transcript is not polluted by nested child question.

---

## Files likely touched

### Production

```text
src/CodingAgent/Runtime/Contract/BackfillEventProviderInterface.php
src/CodingAgent/Runtime/Process/ChildRunBackfillEventProvider.php
src/CodingAgent/Runtime/Contract/SessionTranscriptProviderInterface.php
src/CodingAgent/Session/SessionTranscriptProvider.php
src/Tui/Picker/SubagentLivePickerController.php
src/Tui/Runtime/SubagentLiveChildViewPoller.php
src/Tui/Runtime/SubagentLiveViewState.php
src/Tui/Runtime/SubagentLiveBackgroundChildPoller.php
src/Tui/Runtime/TuiRuntimeEventApplier.php
src/Tui/Listener/RuntimeQuestionEventHandler.php
src/Tui/Listener/TickPollListener.php
config/services.yaml
```

Not all of these must change. The implementation should minimize changes and avoid broad refactors.

### Tests

```text
tests/Tui/Runtime/SubagentLiveChildViewPollerBackfillTest.php
tests/Tui/Runtime/SubagentLiveChildViewPollerTest.php
tests/Tui/Runtime/SubagentLiveBackgroundChildPollerTest.php
tests/Tui/Scenario/SubagentLiveHitlScenarioTest.php
tests/Tui/E2E/TuiSubagentChildHitlCancellationE2eTest.php
tests/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClientEventBufferTest.php
tests/CodingAgent/Runtime/Stream/StreamingCommittedRuntimeEventStoreTest.php
tests/CodingAgent/Runtime/Controller/RuntimeEventEmitterTest.php
tests/CodingAgent/Runtime/Controller/E2E/SubagentScoutHitlLiveE2eTest.php
tests/CodingAgent/Runtime/Controller/E2E/ForkHitlLiveE2eTest.php
```

---

## Implementation boundaries

### Do not do this

- Do not re-enable a broad `RuntimeEventEmitter` drain loop that polls all registered/completed run event files.
- Do not read child `events.jsonl` from any TUI tick loop.
- Do not make background poller read canonical event storage.
- Do not enqueue hidden child questions into the global `QuestionCoordinator` while main view is active.
- Do not introduce test-only production flags or `APP_ENV=test` conditionals.
- Do not add compatibility shims for old backfill behavior unless explicitly requested.
- Do not broaden into unrelated fork MVP behavior.
- Do not rewrite all TUI projection architecture unless required by tests.

### Do this

- Use existing runtime protocol and `run_id` routing.
- Use canonical event replay once on child live-view entry.
- Use normal controller stream for post-entry child updates.
- Keep child view projection isolated from parent projection.
- Keep tests user-contract focused.
- Keep the task small enough to review.

---

## Suggested task split if this is still too large

If implementation feels too broad, split into three tasks:

### Task A — runtime stream contract proof

Scope:

- prove child runtime events are emitted and buffered/routed by `run_id`;
- add missing tests only;
- no TUI behavior changes.

Acceptance:

- client routing tests prove parent poll buffers child events and child poll drains them;
- streaming store test proves child `RunEvent` emits child `RuntimeEvent`.

### Task B — replay-on-enter child snapshot

Scope:

- add child snapshot/replay service;
- wire child live-view entry to replay once;
- keep existing per-tick backfill temporarily disabled or behind tests if necessary.

Acceptance:

- entering child live view renders canonical child transcript/HITL;
- no parent projector corruption.

### Task C — remove per-tick backfill

Scope:

- remove `BackfillEventProvider` from `SubagentLiveChildViewPoller::poll()`;
- delete/rename backfill provider;
- update tests;
- live LLM smoke.

Acceptance:

- selected child live ticks use `AgentSessionClient::events(childRunId)` only;
- no TUI tick file reads;
- direct scout/fork/fork→scout HITL smoke passes.

Preferred if the implementor is confident: one task with phased commits. Preferred if risk is high: split as above.

---

## Reviewer checklist

Reviewer should reject the PR if any of these are true:

- `SubagentLiveChildViewPoller::poll()` still calls an EventStore/backfill/snapshot provider on every tick.
- `SubagentLiveBackgroundChildPoller` reads canonical child events.
- Child HITL can still appear as a main-view overlay when the child live view is inactive.
- Tests only assert provider calls and do not prove user-visible child live-view behavior.
- Live runtime events are not routed by `run_id`.
- Parent and child transcript projectors share mutable state unsafely.
- Validation omits focused TUI/runtime tests.
- `castor check` is skipped without a recorded blocker.

---

## Final target state

After this task, the architecture should be easy to explain:

```text
Main view:
  resume parent once if needed;
  stream RuntimeEvents where run_id = parent.

Child live view:
  replay child once when selected;
  stream RuntimeEvents where run_id = child.

Canonical event files:
  replay/resume/export/repair only.

Controller stdout:
  live RuntimeEvent bus for all run IDs.
```

That is the wheel Hatfield already has. This task removes the accidental second wheel.
