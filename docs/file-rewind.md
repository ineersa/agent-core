# File rewind (SESSION-08)

Hatfield stores **exact filesystem checkpoints** in a Hatfield-owned hidden git repository under `.hatfield/rewind/snapshots/<project-hash>/git/`. The user project `.git` directory is never used for snapshot objects or refs.

## Conversation vs files

- **Conversation rewind** (SESSION-07): `LeafSet` + branch-filtered replay from `events.jsonl`.
- **File rewind** (SESSION-08): optional restore of project files at `/tree` navigation time.

Restoring files does not change which messages are in the active branch; prompt context still comes from replayed events.

## `/tree` choices

When a checkpoint exists for the selected turn:

- Keep current files — conversation navigation only
- Restore files to that point — restore first, then conversation navigation; restore failure cancels navigation
- Undo last file rewind — standalone file undo (no conversation navigation)
- Cancel navigation

## Settings (`rewind.file_snapshots`)

| Key | Default | Meaning |
|-----|---------|---------|
| `enabled` | `true` | Master switch |
| `max_retained_turns` | `100` | Newest N turns keep restorable checkpoints |
| `max_file_bytes` | `2097152` | Skip larger files in snapshots |

Pruned checkpoints remain visible in conversation `/tree` but restore is disabled with reason: "File checkpoint pruned by retention policy."

## Safety

- Paths outside project root are never restored or deleted.
- `.git/` and `.hatfield/` runtime internals are excluded from snapshots.
- Restore captures an undo checkpoint before mutating the worktree.


## Privacy

Hidden snapshots may contain **full contents** of project files captured at checkpoint time, including secrets (for example `.env` or credentials files) unless excluded via path rules or `max_file_bytes`. Configure exclusions and retention appropriately for your environment.

## Storage layout

- Hidden git objects live under `.hatfield/rewind/snapshots/<project-hash>/git/` (ignored by the project git via `.hatfield/.gitignore` → `rewind/`).
- Each checkpoint commit is pinned with its own ref: `refs/hatfield/snapshots/commits/<commit-sha>` so older retained checkpoints stay reachable (not a single moving ref).
- Retention pruning deletes hidden refs for commits no longer in the retained turn window (and drops latest undo ref only when undo metadata is gone). A best-effort `git gc` runs **only** inside the hidden repository, never in the project `.git`.
- Orphan objects may remain briefly after ref deletion until GC; pruned turn checkpoints are unavailable in `/tree` even if blobs still exist on disk.

