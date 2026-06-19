# SESSION-08 Hidden-git file rewind implementation plan

## 1. Decision summary

SESSION-08 should implement exact filesystem rewind for `/tree`, but **must not use the user's project git repository as the snapshot store**.

Rejected backend: Pi rewind extension style.

- Reference: `/home/ineersa/claw/my-pi/packages/extensions/extensions/rewind`
- It uses a temp git index, but stores snapshot commits in the project repo's `.git/objects` and keeps them reachable via `refs/pi-rewind/store`.
- That preserves the user's real index, but still mutates project git internals: objects, refs, and gc reachability.
- Hatfield should avoid hidden writes to user project git.

Chosen backend: opencode-style hidden git repository.

- References:
  - `/home/ineersa/claw/opencode/packages/opencode/src/snapshot/index.ts`
  - `/home/ineersa/claw/opencode/packages/opencode/src/session/revert.ts`
  - `/home/ineersa/claw/opencode/packages/opencode/src/session/processor.ts`
- Use git as a content/tree engine, but with a Hatfield-owned `GIT_DIR` outside the project `.git`.
- Set `GIT_WORK_TREE` to the project root and use a temporary `GIT_INDEX_FILE` for each capture/restore calculation.

Hard invariant:

> SESSION-08 must never mutate the user's project `.git/index`, `.git/objects`, refs, branches, config, gc state, or staging area.

The implementation should include an explicit regression test proving this invariant.

## 2. Product behavior

SESSION-05 provides branch-aware turn tree metadata and replay anchors.
SESSION-06 provides read-only `/tree` picker.
SESSION-07 makes `/tree` actionable for conversation rewind/branch continuation.
SESSION-08 adds optional file restoration as a companion to SESSION-07.

When the user selects a prior `/tree` node, Hatfield should offer restore choices when checkpoint data exists:

| Choice | Files | Conversation |
|---|---|---|
| Keep current files | unchanged | navigate to selected branch/turn |
| Restore files to that point | restore project files to selected checkpoint | navigate to selected branch/turn |
| Undo last file rewind | restore pre-restore checkpoint | navigation behavior is deterministic and documented |
| Cancel navigation | unchanged | unchanged |

File rewind is separate from conversation rewind:

- selecting a turn changes active branch only through SESSION-07 tree/leaf events;
- restoring files does not decide prompt context;
- active prompt context must still be rebuilt from branch-filtered `events.jsonl`, not from current files.

## 3. Storage layout

Use a Hatfield-controlled hidden snapshot store. Recommended local layout:

```text
.hatfield/rewind/
  snapshots/
    <project-hash>/
      git/                 # hidden GIT_DIR, initialized by Hatfield
        HEAD
        objects/
        refs/
        info/exclude
      locks/
      tmp/
```

`<project-hash>` should be stable for the canonical project root path, e.g. SHA-256 of normalized realpath/project root. Do not use user prompt or raw session content in this path.

Alternative global app-data storage is acceptable if it better fits existing settings, but the same invariants apply:

- Hatfield owns the hidden git directory.
- Project `.git` is not used for object storage or refs.
- Session metadata records enough project/backend identity to resolve checkpoints after resume.

## 4. Hidden git backend commands

Every backend command must use explicit environment variables:

```text
GIT_DIR=<hatfield-hidden-git-dir>
GIT_WORK_TREE=<project-root>
GIT_INDEX_FILE=<temporary-index-path>   # for capture/calculation commands
```

For commands that do not need an index, still pass `GIT_DIR` and `GIT_WORK_TREE` explicitly.

Initialization:

```bash
git init --bare <hidden-git-dir>
```

Then configure hidden repo only, not project repo:

```bash
git --git-dir=<hidden-git-dir> config core.autocrlf false
git --git-dir=<hidden-git-dir> config core.longpaths true
```

Do not run `git config`, `git gc`, `git update-ref`, `git add`, `git restore`, or any other git command against the project `.git`.

## 5. Snapshot scope

Default scope:

- include regular files under project root;
- include files that are tracked or untracked from the hidden backend's perspective;
- include untracked non-ignored project files;
- exclude `.git/` always;
- exclude `.hatfield/` runtime internals, especially sessions/tmp/cache/logs/rewind store itself;
- exclude files outside project root;
- exclude overlarge files according to a configured safe snapshot limit;
- exclude ignored files according to the chosen ignore strategy.

Ignore strategy must be explicit. Recommended v1:

1. Always apply Hatfield safety excludes (`.git/`, `.hatfield/sessions/`, `.hatfield/tmp/`, `.hatfield/cache/`, `.hatfield/logs/`, hidden rewind store, vendor-like large runtime dirs only if already project policy says so).
2. If the project has `.gitignore`, consult it read-only using either:
   - hidden git exclude/index behavior if compatible; or
   - a small ignore matcher service.
3. If the directory is not a git repo, still snapshot non-ignored files using Hatfield safety excludes. Do not disable file rewind solely because the project lacks `.git`.

If implementing full `.gitignore` parity is too large, document the exact v1 ignore behavior and ensure no ignored/runtime internals are restored.

## 6. Snapshot capture flow

Service: `RewindCheckpointService::captureBoundaryCheckpoint(...)` delegates to `HiddenGitSnapshotBackend::captureTree(...)`.

Flow:

1. Resolve and canonicalize project root.
2. Compute project hash and hidden git dir.
3. Acquire per-project/session Symfony Lock.
4. Initialize hidden git repo if missing.
5. Create temp index path under Hatfield tmp storage.
6. Populate temp index with in-scope files.
7. Run `git write-tree` to produce a tree id.
8. Deduplicate if the tree id matches the latest checkpoint for the same project/session boundary.
9. Optionally create a lightweight commit object from the tree for easier restore/diff/reference management.
10. Keep the tree/commit reachable in hidden git with a Hatfield ref, e.g. `refs/hatfield-rewind/store` or per-session refs.
11. Append canonical checkpoint metadata.
12. Cleanup temp index.
13. Release lock.

Important: temp indexes must be deleted best-effort, with failures logged at debug/warning level but not hiding snapshot success.

## 7. Tree-only vs commit-based snapshots

Both are viable.

### Option A — tree ids only

- Capture: `git write-tree` returns tree id.
- Restore: use `git read-tree`/`checkout-index` or equivalent against the tree.
- Reachability: hidden git tree/blob objects may need refs or gc policy to avoid pruning.

### Option B — commit object per unique tree, recommended

- Capture tree id.
- If not duplicate, run `git commit-tree <tree> -m "hatfield rewind snapshot"` in hidden git.
- Keep snapshot commits reachable through a hidden ref chain or per-session refs.
- Restore from commit/tree id.

Commit-based snapshots are easier to inspect and keep reachable. They still do not mutate project git because `GIT_DIR` points to Hatfield storage.

Recommended v1: commit per unique tree plus a hidden keepalive ref, similar to Pi rewind but in Hatfield hidden git, not project `.git`.

## 8. Restore flow

Service: `RewindCheckpointService::restoreCheckpoint(...)`.

Flow:

1. Validate target checkpoint exists and belongs to the same project root/hash.
2. Acquire per-project/session restore lock.
3. Capture current worktree as an undo checkpoint before destructive changes.
4. Resolve target tree id.
5. Capture/resolve current tree id.
6. If current tree equals target tree:
   - append a no-op restore event if useful for UX, or return no-op;
   - do not rewrite files.
7. Compute files present currently but absent in target.
8. Delete those files from disk only after strict safety checks:
   - path normalized;
   - path is inside project root;
   - path is not `.git/`;
   - path is not Hatfield runtime/snapshot internals;
   - path is in backend-managed snapshot scope.
9. Restore target tree contents into worktree using hidden `GIT_DIR` and project `GIT_WORK_TREE`.
10. Preserve project git index/staging area by never invoking project git.
11. Append `file_rewind.restored` metadata including target checkpoint and undo checkpoint.
12. Release lock.

Restore implementation choices:

- `git checkout-index` with hidden index populated from target tree; or
- `git restore --source=<target> --worktree -- .` with explicit hidden `GIT_DIR`; or
- `git read-tree <target>` into temp index then checkout from temp index.

The implementor must test that the chosen command does not read/write the project `.git/index` and does not update project refs.

## 9. Canonical metadata/events

Metadata must be append-only and resume-safe. Prefer canonical `RunEvent` entries in `events.jsonl` if this fits current event architecture. A well-defined session ledger file is acceptable only if it is explicitly integrated with resume/projection and documented.

Suggested event types:

```text
file_rewind.checkpoint_recorded
file_rewind.restored
```

Suggested checkpoint payload:

```php
[
    'run_id' => string,
    'turn_no' => int,
    'anchor_seq' => int,
    'anchor_event_id' => ?string,
    'message_id' => ?string,
    'part_id' => ?string,
    'kind' => 'user_boundary'|'assistant_boundary'|'compaction_alias'|'restore_undo',
    'project_root' => string,
    'project_hash' => string,
    'backend' => 'hidden_git_v1',
    'snapshot_id' => string,     // commit or tree id
    'tree_id' => string,
    'created_at' => string,
]
```

Suggested restore payload:

```php
[
    'run_id' => string,
    'turn_no' => int,
    'selected_turn_no' => int,
    'project_root' => string,
    'project_hash' => string,
    'target_snapshot_id' => string,
    'undo_snapshot_id' => string,
    'changed' => bool,
    'files_deleted_count' => int,
    'files_restored_count' => int,
    'diff_summary' => ?array,
    'created_at' => string,
]
```

Do not store raw prompts, tool output, or large diffs in structured logs. If UI needs a diff, store a bounded summary or put full artifacts under session attachments with explicit size caps.

## 10. Boundary timing

Checkpoint at deterministic visible boundaries, not per tool call in v1.

Recommended boundaries:

1. User/prompt boundary before the agent starts mutating files.
2. Assistant completion boundary after the agent finishes its response/tool activity.
3. Compaction/summary boundary aliases current checkpoint if the selected tree node may outlive original message nodes.

Do not implement per-tool snapshots yet. Per-tool snapshots require a stable `MutatedPaths()`/declared touched paths contract and special handling for `bash`, which can mutate unknown files. That belongs to a separate article-style snapshot task.

## 11. Integration with SESSION-05/06/07

SESSION-05:

- turn tree metadata is already append-only in `events.jsonl`;
- `TurnTreeProjector` builds active paths and tree nodes;
- branch-aware replay excludes abandoned sibling turns.

SESSION-06:

- `/tree` displays turn tree read model;
- selection was read-only in that phase.

SESSION-07:

- selecting a turn changes current leaf and rebuilds conversation state;
- SESSION-08 should hook into that flow with a preflight file-restore choice.

SESSION-08 target flow:

```text
/tree opened
  -> user selects turn
  -> runtime resolves checkpoint availability for selected turn
  -> TUI presents restore options
  -> if cancel: no file restore, no conversation navigation
  -> if keep files: SESSION-07 navigation proceeds; record current checkpoint if needed
  -> if restore: restore target files, append restore event, then SESSION-07 navigation proceeds
  -> if undo: restore undo checkpoint; deterministic navigation behavior per task docs
```

If file restore fails, conversation navigation should not silently proceed unless the user explicitly chose to continue without file restore. Failures should be visible and leave state consistent.

## 12. Symfony/PHP service seams

Suggested classes/namespaces may be adjusted to match existing architecture, but keep semantic suffixes.

```text
src/CodingAgent/Rewind/
  RewindCheckpointService.php
  HiddenGitSnapshotBackend.php
  HiddenGitSnapshotRepository.php
  RewindCheckpointDTO.php
  RewindRestoreResultDTO.php
  RewindRestoreChoiceEnum.php
  RewindLedgerProjector.php
  RewindPathScopeService.php
  RewindProjectIdentityService.php
```

Potential split:

- AgentCore domain/event DTOs if events are canonical RunEvents.
- CodingAgent runtime/service classes for filesystem/git process work.
- TUI only handles presentation and user choice through runtime protocol.

Respect boundaries:

- TUI must not call AgentCore stores or filesystem backends directly.
- TUI talks through `AgentSessionClient` and runtime protocol DTOs.
- AgentCore must not depend on CodingAgent or TUI.
- If event types live in AgentCore, payloads should be domain/runtime neutral.

## 13. Runtime protocol seams

Add runtime protocol commands/events as needed, for example:

```text
PrepareTreeFileRestoreCommand(runId, selectedTurnNo)
PrepareTreeFileRestoreResult(options, checkpointSummary, unavailableReason?)
ApplyTreeRewindCommand(runId, selectedTurnNo, restoreChoice)
ApplyTreeRewindResult(conversationNavigationApplied, fileRestoreResult, message)
```

The exact DTO names should follow existing `Runtime/Contract` and `Runtime/Protocol` conventions.

The `/tree` TUI should not decide checkpoint lookup by reading files directly. It requests options from runtime, displays them, and sends the selected choice back.

## 14. Non-git and no-backend behavior

Unlike Pi rewind, hidden git storage can technically work for non-git directories. The implementation should prefer supporting non-git project directories if safe.

If support is incomplete in v1, degradation must be explicit:

- missing `git` binary: file rewind unavailable, conversation `/tree` still works;
- hidden backend init failure: file rewind unavailable with user-visible reason;
- checkpoint missing for selected node: offer keep/cancel only, or explain restore unavailable;
- ignored/out-of-scope selected files: do not restore them;
- partial restore failure: surface error, log structured diagnostic context, and do not claim success.

## 15. Safety and locking

Required safety controls:

- Symfony Lock around snapshot capture and restore per project/session.
- Temporary index files under Hatfield tmp with cleanup in `finally`.
- Root containment check before deleting/restoring paths.
- Hard exclusion of project `.git/` and Hatfield runtime internals.
- Configurable large-file limit; excluded files should be omitted consistently from capture and restore.
- Structured logs with fields like `run_id`, `session_id`, `component`, `event_type`, `project_hash`; avoid raw prompts/tool output.
- All caught exceptions must be surfaced to user or logged with diagnostic context.

## 16. Testing strategy

Before writing/running tests, load `.agents/skills/testing/SKILL.md` and read `tests/AGENTS.md`.

Use Castor only.

Test thesis: file rewind is a user-visible safety feature. Tests should prove exact restore semantics and the safety boundary that project git is untouched.

Focused tests:

1. Hidden backend unit/integration tests:
   - capture snapshot of normal file;
   - restore modified file;
   - restore deleted file;
   - delete file absent from target;
   - include untracked non-ignored file;
   - exclude `.git/`, `.hatfield/`, ignored/overlarge files;
   - dedupe identical trees.
2. Project git untouched regression:
   - create a project git repo with staged changes and a custom ref;
   - run checkpoint capture and restore;
   - assert project `git status --porcelain`, staged diff, refs, and object/ref mtimes or expected invariants remain unchanged.
3. Metadata/projector tests:
   - append checkpoint/restore events;
   - rebuild lookup after resume;
   - resolve checkpoint for selected turn;
   - undo checkpoint lookup works.
4. Runtime protocol tests:
   - prepare options for selected turn;
   - apply keep/restore/undo/cancel paths;
   - failure returns visible message and no partial conversation state transition.
5. Real TUI E2E proof:
   - use `TmuxHarness` and replay-backed model fixture;
   - create a session where files are changed across turns;
   - open `/tree`, select prior turn, choose restore;
   - assert visible UX and filesystem result;
   - prove active conversation branch remains correct.

Validation commands:

```bash
castor test --filter Rewind
castor test:tui
castor deptrac
castor phpstan
castor cs-check
castor check
```

Run `castor test:llm-real` only if implementation changes LLM-visible prompts, provider behavior, or tool schemas. It is not inherently required for file rewind plumbing.

## 17. Acceptance criteria checklist

- [ ] Exact checkpoints recorded at deterministic turn boundaries.
- [ ] Checkpoints bind to canonical turn/message/event identifiers.
- [ ] Hidden git backend stores all objects/refs/index state in Hatfield-owned storage.
- [ ] Project `.git` index, objects, refs, branches, config, gc state, and staging area are not mutated by capture or restore.
- [ ] `/tree` restore preflight offers keep/restore/undo/cancel when appropriate.
- [ ] Restore recreates modified/deleted files and untracked non-ignored files exactly.
- [ ] Restore deletes files currently present but absent in the target checkpoint.
- [ ] Restore never writes/deletes outside project root and never restores `.git/` or `.hatfield/` runtime internals.
- [ ] Undo checkpoint is captured before restore and can be restored later.
- [ ] Metadata is append-only and survives session resume.
- [ ] Conversation branch rewind remains branch-filtered and does not include abandoned turns after file restore.
- [ ] Missing git/backend/checkpoint conditions degrade clearly without partial restore.
- [ ] Tests cover backend, project-git-untouched invariant, restore semantics, metadata resume lookup, runtime protocol, and real TmuxHarness `/tree` restore UX.
- [ ] Docs describe hidden git storage, restore choices, limitations, and safety guarantees.
- [ ] `castor test:tui` and deterministic `castor check` pass before CODE-REVIEW.

## 18. Implementation order

Recommended implementation slices:

1. Hidden git backend proof with tests and project `.git` untouched invariant.
2. Checkpoint metadata events/ledger and projector/resume lookup.
3. Boundary capture integration at prompt/assistant completion points.
4. Restore/undo service with safety checks.
5. Runtime protocol and `/tree` preflight choice flow.
6. TmuxHarness E2E proof, docs, full validation.

Do not start with TUI. The backend safety invariant should be green first.
