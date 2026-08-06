# Hatfield file-rewind extension

Project-level Hatfield extension that records **file-only** hidden-git checkpoints after stable completed turns and restores them through `/rewind`.

| | |
|---|---|
| Package | `ineersa/hatfield-ext-file-rewind` |
| Extension class | `Ineersa\HatfieldExt\FileRewind\FileRewindExtension` |
| Namespace | `Ineersa\HatfieldExt\FileRewind\` |
| PHP | `>=8.3` |
| Requires | `ineersa/hatfield-extension-api`, `psr/log`, `symfony/tui` |

Depends on `ineersa/hatfield-extension-api` for public contracts. In this monorepo that dependency is a path repository; released consumers install the published API package.

## Behavior (v1)

- After stable turn boundaries (`AfterTurnCommitHookInterface`), captures worktree state into a **Hatfield-owned hidden git** store (isolated `GIT_DIR`; never the project `.git`).
- `/rewind` opens an extension-owned Symfony TUI picker of **file checkpoint targets on retained user-prompt turns for the active session only** (same user-only row contract as `/history`, filtered to turns with a persisted checkpoint and a meaningful label). Assistant/tool-cycle turns are not picker rows. Other sessions in the same project do not appear.
- **Enter** restores files to that checkpoint (file-only). **Esc** closes the picker. Undo metadata is captured before restore for safety; there is no undo menu item in v1.
- `/history` is conversation-only (user prompts); file restore stays on `/rewind` and is not mixed into conversation history.
- Live file-diff preview in the picker is intentionally disabled in v1 (no hidden-git indexing on open/navigation).
- Ledger and snapshots live under `.hatfield/rewind/` (project-scoped by cwd hash). Retention (`max_retained_turns`) prunes the newest-N checkpoint rows across all sessions in that project ledger.

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
      max_file_bytes: 2097152
      git_timeout_seconds: 30
```

Defaults match `FileRewindConfig` (`enabled: true`, `max_retained_turns: 100`, `max_file_bytes: 2097152`, `git_timeout_seconds: 30`). Listing the class under `extensions.enabled` is required; start a **new Hatfield session** after enabling — extensions register at startup.

Monorepo install:

```bash
composer install
composer install -d .hatfield/extensions
```

External install (after a release tag; under the consuming project’s `.hatfield/extensions` Composer root or equivalent):

```json
{
  "require": {
    "ineersa/hatfield-ext-file-rewind": "^X.Y"
  },
  "repositories": [
    { "type": "vcs", "url": "https://github.com/ineersa/hatfield-ext-file-rewind" },
    { "type": "vcs", "url": "https://github.com/ineersa/hatfield-extension-api" }
  ]
}
```

## Safety notes

- Project `.git` is never used for snapshot objects/refs.
- Hidden snapshots may contain secrets from captured files.
- Restore is not fully transactional across arbitrary failure modes; partial failures are recorded and surfaced.

## Source of truth

**[ineersa/agent-core](https://github.com/ineersa/agent-core)** is the authoritative monorepo. This GitHub repository is a **read-only release mirror**: each Hatfield `vX.Y.Z` tag updates `main` and publishes the same tag here. Do not open feature PRs against the mirror.

Full product detail:

- [docs/file-rewind.md](https://github.com/ineersa/agent-core/blob/main/docs/file-rewind.md)
- [docs/settings.md](https://github.com/ineersa/agent-core/blob/main/docs/settings.md) (`extensions.settings.file_rewind`)
- [docs/distribution.md](https://github.com/ineersa/agent-core/blob/main/docs/distribution.md)
