# File rewind (built-in extension)

File rewind is a **project-level Hatfield extension** (`Ineersa\HatfieldExt\FileRewind\FileRewindExtension` under `.hatfield/extensions/file-rewind/`). It captures Hatfield-owned hidden-git checkpoints after completed turns and exposes `/rewind` for **file-only** restore via an extension-owned Symfony TUI picker (generic `TuiExtensionContextInterface` overlay APIs — no file-rewind-specific runtime ports).

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



## UX (v1)

- `/tree` stays conversation-only.
- `/rewind` lists **file checkpoint targets only** (turns with a persisted hidden-git checkpoint and a meaningful label). Internal/tool turns without checkpoints are hidden.
- Checkpoints and restore targets are scoped to the **active session** (`session_id` / `run_id`); older sessions in the same project cwd do not appear in `/rewind` and are not used for restore.
- Checkpoints are recorded on stable completed turn boundaries:
  - Plain assistant turns (`turn_end` / `agent_end` / post-tool `llm_step_completed` without in-flight tool events in the same commit).
  - **Post-tool file state** on `tool_batch_committed` when that commit is the stable boundary (tool effects applied on disk, `effectsCount === 0`, no `tool_execution_start` in the same commit). The same commit may include `tool_call_result_received` / `message_end`; that is expected.
  - Mid-tool-only commits (`tool_execution_start`, `effectsCount > 0`, or batches without a stable boundary) do not create restore targets.
- **Enter** on a checkpoint row restores files to that checkpoint (file-only v1).
- **Esc** closes the picker. Undo metadata remains internal for safety; there is no undo menu item in v1.
- No live diff preview in the picker.


## Enable / settings

```yaml
extensions:
  enabled:
    - Ineersa\HatfieldExt\FileRewind\FileRewindExtension
  settings:
    file_rewind:
      enabled: true
      max_retained_turns: 100
      max_file_bytes: 2097152
      git_timeout_seconds: 30
```

Install extension deps after pull: `composer install -d .hatfield/extensions`.

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
- **Retention / pruning** is **project-scoped** (per cwd / project hash under `.hatfield/rewind/`): `max_retained_turns` keeps the newest N checkpoint rows across all sessions in that project ledger; hidden-git refs are pruned accordingly. There is no per-session retention cap in v1.
