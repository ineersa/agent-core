# Hatfield file-rewind extension

Project-level Hatfield extension that records **file-only** hidden-git checkpoints after stable completed turns and restores them through `/rewind`.

| | |
|---|---|
| Package | `ineersa/hatfield-ext-file-rewind` |
| Extension class | `Ineersa\HatfieldExt\FileRewind\FileRewindExtension` |
| Namespace | `Ineersa\HatfieldExt\FileRewind\` |
| PHP | `>=8.3` |
| Requires | `ineersa/hatfield-extension-api`, `psr/log`, `symfony/tui` |

## Behavior (v1)

- After stable turn boundaries (`AfterTurnCommitHookInterface`), captures worktree state into a **Hatfield-owned hidden git** store (isolated `GIT_DIR`; never the project `.git`).
- `/rewind` opens an extension-owned Symfony TUI picker of **file checkpoint targets on retained user-prompt turns for the active session only** (same user-only row contract as `/history`, filtered to turns with a persisted checkpoint and a meaningful label).
- **Enter** restores files to that checkpoint (file-only). **Esc** closes the picker. Undo metadata is captured before restore; no undo menu item in v1.
- `/history` remains conversation-only; file restore is not mixed into conversation history.
- Live file-diff preview is intentionally disabled in v1 (no hidden-git indexing on open/navigation).
- Ledger/snapshots live under `.hatfield/rewind/` (project-scoped by cwd hash). `max_retained_turns` prunes newest-N checkpoint rows across all sessions in that project ledger.

### Checkpoint boundaries

- Plain assistant turns and post-tool stable commits can create checkpoints.
- Mid-tool-only commits do not create restore targets.
- Picker surfaces only checkpoints whose turn number is a retained user-prompt row for the **active** session.

## Enable

```yaml
# .hatfield/settings.yaml
extensions:
  enabled:
    - Ineersa\HatfieldExt\FileRewind\FileRewindExtension
  settings:
    file_rewind:
      enabled: true
      max_retained_turns: 100
      git_timeout_seconds: 30
```

Defaults match `FileRewindConfig`. Listing the class under `extensions.enabled` is required; start a **new Hatfield session** after enabling.

```bash
composer install -d .hatfield/extensions
```

## Safety

- Project `.git` is never used for snapshot objects/refs.
- Hidden snapshots may contain secrets from captured files.
- Restore is not fully transactional across arbitrary failure modes; partial failures are recorded and surfaced.

## Source of truth

**[ineersa/agent-core](https://github.com/ineersa/agent-core)** monorepo path `.hatfield/extensions/file-rewind/` is authoritative. The GitHub package repository is a read-only release mirror.
