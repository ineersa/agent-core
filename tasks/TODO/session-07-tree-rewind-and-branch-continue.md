# SESSION-07 /tree rewind to turn and continue on a branch

## Goal
Make `/tree` actionable: allow the user to select a prior turn in the current session, move the active leaf there, and continue from that point as a new branch.

## Desired UX
- `/tree` opens the tree picker.
- Selecting a turn moves the active session context back to that turn/branch.
- If the selected item is a user turn, the user prompt may be restored into the editor for editing/re-submission where appropriate; otherwise continuation starts from the selected branch state.
- New messages after selection append to a branch, preserving abandoned future history as sibling branches rather than deleting it.

## Current code facts

### Core architectural challenge
This task combines two hard pieces:
1. **SESSION-06** read-only tree picker
2. **SESSION-02** session-switch lifecycle (rewinding within the same session is analogous to switching)

Plus a new piece: **recording the branch/leaf change in canonical events**.

### What happens when user selects a prior turn

```
Turn 1 (root)
├─ Turn 2 ← leaf was here
│    └─ Turn 3
│         └─ Turn 4 ← user selects Turn 3
│
New: Turn 5 (child of Turn 3) ── user sends next message
```

State transitions:
1. Cancel current run if active
2. Emit canonical leaf-change event (`run.leaf_set` or `run.turn_branched`) to `events.jsonl`
3. Rebuild `RunState` by replaying only events on the path root→Turn 1→Turn 2→Turn 3 (not Turn 4)
4. Reset TUI transcript projector, replay only active-path events
5. Update `TuiSessionState`: reset transcript, set `lastSeq` to max active-path event seq, update footer/activity
6. If selected turn is a user message, optionally restore prompt text to editor
7. Set `TuiSessionState::$request` to a new `StartRunRequest` (if re-submitting) or mark as ready for next user input
8. Next user `Submit` sends a steer/follow-up that appends as child of the selected turn

## Dependencies on other tasks

| Task | What it provides |
|------|-----------------|
| SESSION-02 | Cancellation, state reset, transport isolation — the switch itself |
| SESSION-05 | Turn tree read model, leaf-change event types, replay filter |
| SESSION-06 | Read-only picker UI — extend with actionable selection |
| RTVS-08B | RunState replay from events — must support filtering to active branch |
| RTVS-08 | Final resume integration — this task extends resume-like behavior for in-session nav |

## Implementation seam: recording the leaf change

### New canonical event type
```php
// src/AgentCore/Domain/Run/RunEventTypeEnum.php extends with:
case TurnBranched = 'run.turn_branched';

// Payload structure:
[
    'runId' => string,
    'seq' => int,
    'turnNo' => int,           // the turn navigation landed on
    'parentTurnNo' => ?int,    // null if root
    'previousTurnNo' => int,   // what the leaf was before
    'reason' => 'rewind'|'continue'|'fork'|'user',
    'userMessageRestored' => ?string,  // if editor was populated
]
```

### Extending TurnTreeView
```php
class TurnTreeView {
    // New method:
    public function getAncestorPath(int $turnNo): array;  // root→...→turn list
    public function setLeaf(string $runId, int $turnNo): void;
    public function getActiveBranchPath(string $runId): array;  // after leaf change
}
```

### Extending the RunState replay
- `ReplayService` or a new `BranchAwareReplayService` must accept an optional branch path filter:
  ```php
  function rebuildState(string $runId, ?array $includeTurns = null): RunState
  ```
  - If `$includeTurns` is null, replay all events (legacy/single-branch mode)
  - If set, only include events whose turnNo is in the path

## Implementation seam: command extension

### Extend SESSION-06 TreeCommand to support `onSelect` action
```php
// In TreeCommand::handle():
$isReadonly = '' !== $cmd->args && '--view' === $cmd->args;  // or add /tree --view

$this->overlay->mount(
    new HeaderWidget('Session Turn Tree'),
    new SelectListWidget(
        items: $items,
        onSelect: function (SelectEvent $e) use ($switcher, $treeView): void {
            $turnNo = (int)$e->item->value;
            $switcher->rewindToTurn($turnNo, function () use ($e) {
                // Optional: restore user message to editor
                // $editor->setText($this->getUserPromptForTurn($turnNo));
            });
            $this->overlay->close();
        },
        onCancel: fn() => $this->overlay->close(),
    ),
);
```

### SessionSwitchService extension (SESSION-02)
```php
class SessionSwitchService {
    public function switchToNew(?StartRunRequest $request): TuiSessionState;
    public function switchToResume(string $sessionId): TuiSessionState;
    // New method:
    public function rewindToTurn(int $turnNo, ?callable $beforeSwitch = null): TuiSessionState;
}
```
`rewindToTurn()` shares most of the reset logic from `switchToResume()` but:
- Does NOT change session ID
- Appends leaf-change event to events.jsonl instead of resuming existing run
- Replays RunState from the new leaf anchor
- Optionally restores user prompt to editor

## Known pitfalls
- `ReplayService::rebuildHotPromptState()` (src/AgentCore/Application/ReplayService.php) currently replays ALL events linearly. It must be modified or extended to support branch filtering before this task is feasible.
- Leaf-change events must be appended to `events.jsonl` under lock (FlockStore via `SessionRunEventStore`). The current event appending pipeline uses `RunCommit`; a leaf change initiated from TUI may need a separate event-persist path.
- If the selected turn is a model response (not a user message), the user expects to continue from that point. The next user message should be a follow-up/steer, not a new initial prompt.
- `RunState` after rewind must reflect only the active branch. Messages from abandoned turns must NOT be included in prompt context.
- After rewind, `lastSeq` in `TuiSessionState` must correspond to the max event seq in the active branch, not the old leaf's max seq, so the poller doesn't re-ingest events from the abandoned branch.
- The abandoned branch's events still exist in `events.jsonl`. They have higher seq than the branch point but are not ancestors. The poller feeds events to `TranscriptProjector` by seq — unless the projector is reset and replay is scoped to the active branch, abandoned events will be reprojected.
- No backward-compatibility: old sessions without tree events are treated as single-branch linear history; `rewindToTurn()` is a no-op or returns an error for unsupported sessions.
- Runtime/TUI/Messenger changes require full `castor check` before CODE-REVIEW.
- TUI must talk to runtime via `AgentSessionClient`, not AgentCore internals per deptrac boundaries.
- No compatibility fallback to old `transcript.jsonl`/`runtime-events.jsonl` unless explicitly requested.

## Dependencies
- SESSION-05 turn tree model and replay anchors.
- SESSION-06 read-only tree picker.
- RTVS-08B-style RunState replay must support rebuilding state for the selected branch/leaf.

## Out of scope
- LLM-generated branch summaries unless explicitly added in a later task.
- Exporting/forking a branch into a separate session.
- Destructive truncation of session history.

## Acceptance criteria
- Selecting a prior turn records an append-only canonical tree navigation/leaf-change event or equivalent metadata; `events.jsonl` history is not truncated.
- The current TUI transcript and AgentCore prompt/run state are rebuilt to match the selected branch/leaf.
- Subsequent user messages continue from the selected turn as a new branch with correct parent/leaf metadata.
- Abandoned future turns remain visible in `/tree` as sibling/old branch history.
- The dedup cursor, activity state, pending HITL/tool/cancellation/error state, and footer/session display remain coherent after branch selection.
- If selecting a user turn restores editable text, the behavior is deterministic and documented; if not supported, selection behavior is clearly defined and tested.
- Tests cover selecting an earlier turn, continuing with a new message, preserving old branch history, resume after tree navigation, and replaying the active branch only.
- Docs describe `/tree` branch/rewind semantics and limitations.
- Validation uses Castor per project rules; runtime/TUI/Messenger changes require full `castor check` before CODE-REVIEW.

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
- Created: 2026-06-07T20:46:28.593Z
