# Hatfield jbcontext extension

Project-level Hatfield extension that wires JetBrains Context (`jbcontext`) semantic search into Hatfield without creating a first index for an arbitrary directory.

| | |
|---|---|
| Package | `ineersa/hatfield-ext-jbcontext` |
| Extension class | `Ineersa\HatfieldExt\Jbcontext\JbcontextExtension` |
| Namespace | `Ineersa\HatfieldExt\Jbcontext\` |
| PHP | `>=8.3` |
| Requires | `ineersa/hatfield-extension-api`, `helgesverre/toon`, `psr/log` |

## Prerequisites

1. Install and authenticate the `jbcontext` CLI.
2. Open the project in a JetBrains IDE so `<project>/.idea` exists.
3. Create the first index yourself once for the Git repository:

```bash
jbcontext index --project-path /path/to/project
```

Hatfield never runs the first index. Indexes are repository-scoped by git remote id, so any checkout or worktree of that repository can become eligible when it has a local `.idea` directory and `jbcontext status --json-output` reports at least one snapshot.

## Enable

```yaml
# .hatfield/settings.yaml
extensions:
  enabled:
    - Ineersa\HatfieldExt\Jbcontext\JbcontextExtension
```

No extension-specific settings key is added. Presence on `extensions.enabled` is the only switch.

```bash
composer install -d .hatfield/extensions
```

Start a **new Hatfield session** after enabling. Extensions register at startup.

## Behavior

### Startup (non-blocking)

1. Registration writes a pending status file and dispatches one background eligibility job.
2. The worker requires `.idea` and a prior index snapshot from `jbcontext status --project-path <cwd> --json-output`.
3. If either check fails, search and refresh stay disabled for the rest of that session and the TUI shows a concise disabled status.
4. Transient status failures retry with delays 2s, 4s, 8s, 16s (~30s total). Exhaustion disables the session; later turns do not retry.
5. When eligible, the worker installs managed project assets and runs incremental `jbcontext index --silent`.

### Refresh cadence

After eligibility, each successfully completed assistant turn (`agent_end` reason `completed`) enqueues one incremental silent reindex. Cancelled and failed turns do not. CLI work stays in the extension-agent worker; the hot hook only updates status flags and dispatches.

### `code_search` tool

Permanent model-visible tool:

- required `text`
- optional project-relative `path_filter` (absolute/`..` rejected)
- fixed internal `--limit 8`
- cooperative cancellation and timeout through `ExecOptionsDTO` / tool ambient context
- top-level TOON result with ranked `path`, `start_line`, `similarity`, and `content`

When eligibility is pending or disabled, the tool returns a TOON unavailable payload and never indexes.

### Managed project assets

After eligibility succeeds, the extension may create or update:

- `.hatfield/skills/jbcontext-semantic-search/SKILL.md`
- `.hatfield/agents/scout.md`

Both contain `# managed-by: hatfield-ext-jbcontext`. Absent or previously managed destinations are updated. User-owned project files are left alone with a collision warning. User-level `~/.hatfield` files are never modified.

Because eligibility is asynchronous after startup discovery, newly installed project assets may take effect on the **next** Hatfield session.

## Privacy and security

- First indexing remains a manual operator action.
- Status and search use the authenticated jbcontext CLI; do not log prompts, tool output, credentials, or environment values.
- Disabled/unavailable states are sanitized for the TUI and tool results.

## Unavailable states

| State | TUI / tool |
|---|---|
| Pending startup check | `jbcontext: checking index…` / tool unavailable |
| No `.idea` or no prior snapshot | disabled status text / tool unavailable |
| Transient CLI failure exhausted | disabled after retries / tool unavailable |
| Eligible | `jbcontext: indexed` (or refreshing) / search allowed |

## Source of truth

**[ineersa/agent-core](https://github.com/ineersa/agent-core)** monorepo path `.hatfield/extensions/jbcontext/` is authoritative.
