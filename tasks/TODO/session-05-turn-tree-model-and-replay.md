# SESSION-05 Turn tree model and replay anchors

## Goal
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
Status: TODO
Branch:
Worktree:
Fork run:
PR URL:
PR Status:
Started:
Completed:

## Work log
- Created: 2026-06-07T20:46:01.617Z
