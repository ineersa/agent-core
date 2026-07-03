# File rewind (built-in extension)

File rewind is a **built-in Hatfield extension** (`FileRewindExtension`) registered by default. It captures Hatfield-owned hidden-git checkpoints after completed turns and exposes an extension-owned `/rewind` command for restore/undo with optional conversation rewind.

## Compared to SESSION-08 prototype (PR #249)

| Area | Prototype (#249) | This architecture |
| --- | --- | --- |
| UX | `/tree` offered file restore | `/tree` stays conversation-only; `/rewind` owns file UX |
| Events | AgentCore `file_rewind.*` events | Extension-local JSON ledger under `.hatfield/rewind/` |
| Hooks | AgentCore `HookSubscriberInterface` in CodingAgent/Rewind | Extension API `AfterTurnCommitHookInterface` + app bridge |
| Restore locks | `RunLockManager` during capture/restore | No explicit run lock in checkpoint hook; restore on explicit user action |
| Runtime protocol | `tree_navigate_to_turn` changes | No runtime protocol changes for file rewind |

### Reuse from prototype

- Hidden git snapshot model (`HiddenGitSnapshotBackend`, isolated `GIT_DIR`, temp `GIT_INDEX_FILE`)
- Path safety (`RewindPathScope`), per-project storage hash, per-commit hidden refs + pruning
- Undo-before-restore metadata pattern

### Discarded from prototype

- `/tree` file restore orchestration, `TreeNavigateToTurnOrchestrator`, runtime `tree_navigate_to_turn`
- AgentCore event types for file rewind ledger
- Run-lock coupled checkpoint service

## Enable / settings

```yaml
extensions:
  enabled:
    - Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindExtension
  settings:
    file_rewind:
      enabled: true
      max_retained_turns: 100
      max_file_bytes: 2097152
      git_timeout_seconds: 30
```

## Safety invariants

- User project `.git` is never used for snapshot objects/refs.
- Snapshots live under `.hatfield/rewind/` (ignored by project git).
- Restore captures undo metadata before mutating the worktree.
- Partial restore failures are recorded and surfaced to the user.

## Limitations

- Hidden snapshots may contain secrets from captured files.
- Restore is not fully transactional across arbitrary failure modes.
- Live file diff preview in the `/rewind` picker is **intentionally disabled in v1**. Hidden-git indexing (`git add --all` / worktree capture) must not run on picker open or arrow navigation; restore/undo still uses hidden git on explicit user action.
- The picker shows only whether a turn has a file checkpoint and generic action guidance.
- Checkpoint capture/restore diagnostics rely on best-effort logging only in v1 (no structured extension logger seam yet).
