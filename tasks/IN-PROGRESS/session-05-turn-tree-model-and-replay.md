# SESSION-05 Turn tree model and replay anchors

## Goal
Introduce the canonical data model needed for `/tree`: a branchable per-session turn tree with stable anchors that can be replayed into prompt/run state.

## Context
Pi's session model is append-only JSONL entries with `id`/`parentId`, a movable leaf pointer, and context rebuilt by walking from leaf to root. Agent-core currently has canonical `events.jsonl` and linear `RunState`; tree navigation needs explicit turn/branch/leaf semantics that remain compatible with `events.jsonl` being the source of truth.

This task should design and implement the data/replay foundation before UI.

## Dependencies
- RTVS-08B canonical events and RunState replay should exist or this task should extend that reducer cleanly.
- RTVS-08 final resume integration should not be broken by tree metadata.

## Out of scope
- Rendering the `/tree` picker UI.
- Executing rewind/branch from the TUI.
- LLM branch summarization/compaction; can be a later enhancement.

## Current code facts

### Where turn boundaries exist today
- `RunEventTypeEnum` (src/AgentCore/Domain/Run/RunEventTypeEnum.php) — event types for AgentCore state machine
- `turn_no` in `RunState` — monotonically incrementing turn counter, persisted to `state.json`
- `lastSeq` in `TuiSessionState` — cursor position into runtime event stream, derived from `RuntimeEventPoller`
- `RuntimeEventMapper` — maps AgentCore `RunEvent` → `RuntimeEvent`; this could be extended to emit turn-boundary events
- No current concept of "parent turn" or "branch" exists in either `events.jsonl` or `RunState`

### Pi's approach (for reference, not direct translation)
- Append-only JSONL with `SessionEntry { id, parentId, timestamp, type, ... }`
- `SessionManager::branch(id)` moves leaf to `id`, so next append creates a child of that entry
- `SessionManager::getTree()` walks all entries, builds nested `SessionTreeNode[]` based on parentId relationships
- `SessionManager::buildSessionContext()` walks from leaf to root, collecting messages for prompt context
- `resetLeaf()` sets leaf to null (empty conversation)
- `appendSessionInfo(name)` sets a display name on the session file

### Implications for agent-core
- `events.jsonl` must remain the canonical source of truth. Adding tree metadata must not require a separate tree file.
- Two viable approaches:
  1. **Inline tree events in `events.jsonl`**: Add new `RunEvent` types like `turn_branched`, `leaf_moved`. Extend `RuntimeEventMapper` to produce corresponding `RuntimeEvent` types. Replay service reads these to determine active branch.
  2. **Separate tree metadata store**: Use `hatfield_session` DB columns (parent_id, root_id already exist but refer to forked sessions, not turn-level branching) or a new tree-index file.
  - **Recommendation**: Approach 1 (inline) keeps canonical source unified. Pi's model proves this works.

## Suggested event types to add
```php
// In AgentCore event stream (events.jsonl)
'run.turn_branched' => [
    'runId' => string,
    'seq' => int,
    'turnNo' => int,
    'parentTurnNo' => int|null,   // null = root
    'reason' => 'rewind'|'continue'|'fork',
]

// Or simpler: add parent_turn_no to existing turn-boundary events
```

## Canonical leaf tracking options

A) **Last-turn pointer** — `state.json` stores current leaf turn number. On replay, replay only events belonging to the active branch path. Simple but requires `state.json` to be correct.

B) **Leaf event** — Append a `run.leaf_set` event to `events.jsonl` each time the user navigates. Replay walks events, tracks leaf changes, only includes events in the active ancestry. More robust (canonical). **Recommended.**

## Implementation seams

### New file or extended in `src/AgentCore/...`
- Turn tree read model service: `src/AgentCore/Domain/Run/TurnTreeView.php` or similar
- Method: `buildFromEvents(string $runId): TurnTree` — walks `events.jsonl`, builds parent-child tree
- Method: `getActiveBranchPath(TurnTree, int $leafTurnNo): TurnNo[]` — returns ordered list of turn numbers from root to leaf

### Extended in `src/Tui/Runtime/...`
- TUI-side service to query the tree read model and format for display

## Tests to create
- Build linear tree from events, assert tree structure and active path
- Branch from earlier turn, assert both branches present and active path switches
- Replay state up to a non-leaf turn, assert only ancestor events included
- Multiple sequential branches preserve history

## Known pitfalls
- `events.jsonl` currently has no concept of "tree branching" — every `RunEvent` with `seq` occupies a linear sequence. Branching means events from an abandoned path have higher seq than the branch point but are not ancestors of the new leaf.
- RunState replay from `events.jsonl` must explicitly filter to active branch events. If replay is naive-sequential, abandoned branch events will corrupt state.
- The existing `ReplayService` in `src/AgentCore/Application/ReplayService.php` rebuilds prompt state by replaying all events in seq order — this is incompatible with tree branching without modification.
- DB `parent_id` and `root_id` currently refer to session-forking, not within-session turn branching. Do not overload these columns; keep turn-level branching in events.jsonl.
- No backward-compatibility for old sessions without tree events: they should be treated as linear single-branch history.
- Runtime/TUI/Messenger changes require full `castor check` before CODE-REVIEW.
- TUI must talk to runtime via `AgentSessionClient`, not AgentCore internals per deptrac boundaries.

## Acceptance criteria
- Canonical events include enough structure to identify user-visible turns and their replay boundaries/anchors.
- A session has an explicit current leaf/head concept or equivalent canonical event representation so future turns can branch from an earlier turn without rewriting history.
- A read model can build a turn tree for a session from canonical events, including turn id/anchor, parent relationship, labels/title text or prompt preview, timestamps, and current leaf marker.
- RunState/prompt replay can rebuild state up to a selected turn/leaf without including abandoned sibling branch events.
- Tree metadata remains append-only/canonical; no destructive truncation of `events.jsonl` is used to go back in history.
- Tests cover building a linear tree, creating a branch from an earlier turn, marking current leaf, and replaying only the selected branch path.
- Docs describe turn tree semantics and how they relate to canonical `events.jsonl`, `state.json`, and future `/tree`.
- Validation uses Castor per project rules; runtime/Messenger changes require full `castor check` before CODE-REVIEW.

## Workflow metadata
Status: IN-PROGRESS
Branch: task/session-05-turn-tree-model-and-replay
Worktree: /home/ineersa/projects/agent-core-worktrees/session-05-turn-tree-model-and-replay
Fork run:
PR URL:
PR Status:
Started: 2026-06-12T01:41:43.505Z
Completed:

## Work log
- Created: 2026-06-07T20:46:01.617Z

## Task workflow update - 2026-06-12T01:41:43.505Z
- Moved TODO → IN-PROGRESS.
- Created branch task/session-05-turn-tree-model-and-replay.
- Created worktree /home/ineersa/projects/agent-core-worktrees/session-05-turn-tree-model-and-replay.
- Copied vendor directory into /home/ineersa/projects/agent-core-worktrees/session-05-turn-tree-model-and-replay.
- Copied .vera index into /home/ineersa/projects/agent-core-worktrees/session-05-turn-tree-model-and-replay.
