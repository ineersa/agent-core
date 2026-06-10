# SESSION-08 Exact file rewind checkpoints and restore for tree navigation

## Goal
## Context
`tasks/TODO/session-07-tree-rewind-and-branch-continue.md` covers conversation/turn-tree rewind: moving the active leaf and continuing a new branch without truncating `events.jsonl`.

This follow-up covers the missing **actual rewind functionality**: exact filesystem checkpoints and restore choices during tree navigation, inspired by `/home/ineersa/claw/my-pi/packages/extensions/extensions/rewind`.

That extension provides a git-based file time machine:
- captures exact worktree snapshots at prompt/assistant boundaries using a temporary git index + `git write-tree` + `git commit-tree`;
- keeps snapshot commits reachable through `refs/pi-rewind/store`;
- records append-only `rewind-turn` / `rewind-op` ledger entries in the session file;
- lets `/tree` choose between keeping current files, restoring files to the selected node, or undoing the last file rewind;
- restores tracked and untracked non-ignored files exactly without touching the real git index.

## Goal
Add Hatfield-native exact file rewind support so `/tree` navigation can optionally restore the project files to the selected turn while SESSION-07 rewinds the conversation branch.

## Desired UX
When a user selects a prior turn in `/tree`, after or during the SESSION-07 branch navigation flow, Hatfield offers restore options when a checkpoint exists:

| Option | Files | Conversation |
|---|---|---|
| Keep current files | unchanged | navigated to selected branch/turn |
| Restore files to that point | restored to selected turn checkpoint | navigated to selected branch/turn |
| Undo last file rewind | restored to pre-restore checkpoint | navigated to selected branch/turn or cancels if selected as standalone |
| Cancel navigation | unchanged | unchanged |

Non-git directories should degrade clearly: no file rewind options, conversation navigation can still proceed if SESSION-07 supports it.

## Implementation notes from pi rewind extension
Source to study: `/home/ineersa/claw/my-pi/packages/extensions/extensions/rewind`.

Important behaviors to port/adapt rather than blindly copy:
- `captureWorktreeTree()` uses a temp git index so the user's real index is untouched.
- Snapshot creation deduplicates by exact tree SHA.
- `restoreCommitExactly()` snapshots current state first to create an undo point, deletes files absent from the target tree, then runs `git restore --source=<target> --worktree .`.
- A single store ref keeps snapshot commits reachable; updates must be race-safe.
- The session ledger is authoritative and append-only; git objects are storage.
- Checkpoints bind to canonical visible turn/message/event IDs, not transient UI-only rows.

## Architectural constraints
- Do not truncate or rewrite session history. Store rewind metadata as canonical append-only events or well-defined session ledger records.
- Keep conversation branch rewind (SESSION-07) separate from filesystem rewind. File restore is an optional companion action, not a replacement for turn-tree state replay.
- Preserve the user's real git index/staging area.
- Do not restore ignored files, `.git/`, runtime session internals, or files outside the repo root.
- Every caught exception must either surface to the user or be logged with structured diagnostic context.
- Runtime/TUI changes must respect boundaries: TUI talks to runtime through `AgentSessionClient` / runtime protocol, not AgentCore internals.
- If implemented as an extension surface, add explicit lifecycle hooks instead of ad-hoc coupling; keep `ExtensionApi` public-surface constraints in mind.

## Suggested design seams
- Add a `RewindCheckpointService` / repository responsible for git snapshot capture, store-ref management, restore, undo, and retention-safe metadata.
- Add canonical event or ledger DTOs for checkpoint binding and restore operations, e.g. `file_rewind.checkpoint_recorded` and `file_rewind.restored` or equivalent.
- Extend SESSION-05/07 turn metadata so tree items can resolve an exact checkpoint for selected user/assistant/compaction/summary nodes.
- Extend SESSION-07 tree-selection flow with a restore preflight step and deterministic cancellation behavior.
- Add a visible status/footer indicator only if it fits existing extension/TUI slot APIs; otherwise keep status out of scope and document that choice.

## Out of scope
- Per-tool-call snapshots.
- Restoring ignored files or empty directories.
- Destructive history truncation.
- Exporting a branch into a separate session.
- Retention policy UI. A minimal safe keepalive store is sufficient unless the implementation needs retention to avoid unbounded growth.

## Dependencies
- SESSION-05 Turn tree model and replay anchors.
- SESSION-06 `/tree` read-only picker.
- SESSION-07 `/tree` rewind to turn and continue on a branch.

## Validation expectations
This touches runtime/TUI/session behavior and must follow project QA rules:
- use Castor for all QA commands;
- add automated TUI E2E proof using the real test LLM and `TmuxHarness` showing `/tree` navigation with file restore works end-to-end;
- run `castor test:tui` and `LLM_MODE=true castor check` before CODE-REVIEW, or leave the task IN-PROGRESS with blockers recorded.

## Acceptance criteria
- Hatfield records exact file checkpoints at deterministic turn boundaries and binds them to canonical turn/message/event identifiers.
- `/tree` navigation offers file restore choices when a checkpoint exists and proceeds/cancels deterministically based on the user choice.
- Restoring files recreates tracked and untracked non-ignored files exactly for the selected checkpoint, removes files absent from the checkpoint, and does not touch the user's real git index.
- Before any restore, Hatfield records an undo checkpoint; `Undo last file rewind` restores that state when available.
- File rewind metadata is append-only and survives session resume; after resume, `/tree` can still resolve prior checkpoints.
- Conversation branch rewind remains correct: restored files do not cause abandoned future turns to be included in active prompt context.
- Non-git repos and missing checkpoints degrade with clear user-visible messages and no partial restores.
- Tests cover checkpoint recording, exact restore including deleted/untracked files, undo restore, resume lookup, and `/tree` restore UX through real TmuxHarness E2E.
- Docs describe the relationship between conversation rewind and file rewind, restore options, limitations, and safety guarantees.
- `castor test:tui` and `LLM_MODE=true castor check` pass before moving to CODE-REVIEW.

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
- Created: 2026-06-10T21:02:26.195Z
