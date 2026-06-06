# PHAR Packaging

Single-file executable distribution of Hatfield using Box. The PHAR is the
canonical deployment artifact — TUI, runtime, and agent logic are packaged into
one executable that can be distributed and run without a Composer dependency tree.

## Build commands

```bash
castor phar:build           # Build hatfield.phar (worktree-local by default)
castor phar:ensure           # Ensure PHAR exists (build if missing or stale)
castor phar:clean            # Remove worktree-local hatfield.phar
```

- **`castor phar:build`** — Full build from a worktree-local staging
  directory. Always starts fresh (deletes staging dir). Runs post-build
  smoke tests. By default the PHAR is placed at
  `<project>/var/tmp/phar/hatfield.phar`.
- **`castor phar:ensure`** — Idempotent: returns the existing worktree-local
  PHAR path if it is up-to-date (newer than source, config, and toolchain
  files). Rebuilds only if stale or missing. Used by Castor test tasks as a
  prerequisite.
- **`castor phar:clean`** — Remove the worktree-local PHAR (and staging)
  so the next build starts clean.

The default PHAR output path is `<project>/var/tmp/phar/hatfield.phar` —
scoped to the current checkout/worktree so concurrent builds in sibling
worktrees don't clobber each other. Override with the `HATFIELD_PHAR_PATH`
environment variable (absolute or project-root-relative).

## Build architecture

The build pipeline is implemented in `.castor/helpers.php` under the
`CastorTasks` namespace:

1. **Staging directory** (`<project>/var/tmp/phar-build/source` by default,
   override with `HATFIELD_PHAR_STAGING_DIR`) — a clean copy of production
   directories only (`bin/`, `src/`, `config/`, `migrations/`) plus
   `composer.json`, `composer.lock`, and `box.json`. Worktree-local to
   prevent concurrent build collisions.

2. **Deterministic autoloader suffix** — `composer.json` in staging gets
   `config.autoloader-suffix` set to `HatfieldPharBuild`. Without this,
   Composer derives the autoloader class name from a hash of `composer.json`,
   which can collide with the host project's autoloader when the PHAR is
   consumed inside a Composer-managed process. The root `composer.json` is
   never modified.

3. **Production-only Composer install** — `composer install --no-dev` runs
   in staging with `APP_ENV=prod`. Dev dependencies are excluded.

4. **Box compilation** — Box (from the isolated toolchain at `tools/phar/`)
   compiles the PHAR with GZ compression, SHA256 algorithm, `dump-autoload:
   false` (preserves the Composer-optimized autoloader), and no requirement
   check (`check-requirements: false`). The Box toolchain is isolated under
   `tools/phar/` to avoid polluting the main project's dev dependencies.

5. **Smoke tests** — After compilation, the PHAR is smoke-tested from an
   isolated temporary working directory (outside the repo) to verify:
   - `list` command boots and shows the `agent` command
   - `about` command reports the environment
   - `agent --help` renders usage text
   - `.hatfield/cache` is created in the isolated cwd (writable-dir isolation)

Override the Box binary path with `HATFIELD_PHAR_BOX_BIN` if needed.

### box.json

```json
{
    "directories": ["bin", "src", "config", "vendor", "migrations"],
    "files": ["bin/console", "composer.json", "composer.lock"],
    "output": "var/tmp/phar/hatfield.phar",
    "compression": "GZ",
    "algorithm": "SHA256",
    "main": "bin/console",
    "dump-autoload": false,
    "check-requirements": false,
    "exclude-composer-files": false,
    "exclude-dev-files": true,
    "annotations": false
}
```

The PHAR requires `composer.json` at its root because Symfony's
`getProjectDir()` relies on it to resolve the application root. Without it,
`kernel.project_dir` falls back to a `phar://` stream URI that is not a
valid directory.

## Runtime model

The PHAR is invoked as:

```bash
php var/tmp/phar/hatfield.phar [command] [options]
```

### Working directory and writable dirs

The PHAR's `kernel.project_dir` points to the PHAR archive itself (a
read-only `phar://` path). All runtime-writable state lives under the **active
working directory** (runtime CWD), not the PHAR path.

**Runtime CWD resolution** (in order):
1. `--cwd` CLI option — `bin/console` resolves and chdirs before Kernel
   construction, sets `HATFIELD_CWD` env var.
2. `HATFIELD_CWD` environment variable.
3. Process working directory (`getcwd()`).

**Writable directories** under `<runtime_cwd>/.hatfield/`:

| Directory | Purpose | Setting | Env override |
|---|---|---|---|
| `.hatfield/cache/<env>/` | Symfony container cache | `N/A` (Kernel) | `HATFIELD_CACHE_DIR` |
| `.hatfield/logs/` | Application log files | `logging.path` | `HATFIELD_LOG_DIR` |
| `.hatfield/tmp/` | Temporary state (output cap, background processes) | Various `tools.*.path` | Per-tool |
| `.hatfield/sessions/` | Session data (events, state, transcript) | `sessions.path` | N/A |

Environment variable overrides (`HATFIELD_CACHE_DIR`, `HATFIELD_LOG_DIR`)
accept absolute or relative paths. Relative paths resolve against the runtime
CWD.

### Self-referencing subprocess spawning

When the PHAR spawns subprocesses (controller mode, messenger consumers),
it must reference itself. The resolution chain is:

1. **`ConfigExecutableLocator`** — `HATFIELD_BINARY_PATH` env override
   (used by tests and custom installations).
2. **`PharExecutableLocator`** — `Phar::running()` or `__FILE__` phar://
   URL parsing (Box 4.x auto-generated alias workaround).
3. **`SourceTreeExecutableLocator`** — `kernel.project_dir/bin/console`
   (only works in source checkout, fails inside PHAR).

The chain is wired via `ChainExecutableLocator` in `config/services.yaml`.

## Testing with PHAR

### Castor test tasks

All Castor test tasks that exercise subprocess flows (`test:tui`,
`test:llm-real`, `test:controller`) call `phar_ensure()` first and set
`HATFIELD_BINARY_PATH` before running PHPUnit. This ensures tests exercise
the actual PHAR artifact, not the source tree.

```bash
# These ensure PHAR first, then run tests with HATFIELD_BINARY_PATH set
castor test:tui             # TUI e2e snapshots (uses PHAR)
castor test:controller       # Controller E2E (uses PHAR)
castor test:llm-real        # Real LLM smoke (uses PHAR)
```

Pure unit/integration tests (`castor test`) do not require PHAR and
run against the source tree directly.

### PHAR smoke tests

`PharSmokeTest` (`tests/CodingAgent/Phar/`) is in the `#[Group('phar')]`
test group. It validates the built PHAR boots and responds to basic commands.
Run it explicitly:

```bash
castor phar:build
HATFIELD_BINARY_PATH=var/tmp/phar/hatfield.phar vendor/bin/phpunit --group phar
```

### AgentTestExecutable

Test harness class `AgentTestExecutable` resolves the agent executable from
`HATFIELD_BINARY_PATH` (PHAR) or falls back to `bin/console` (source). All
controller and TUI e2e tests use it, making them automatically switch between
source and PHAR modes.

## Migrations

Database schema migrations run at startup via `StartupDatabaseMigrator`,
which delegates to `ApplicationMigrationExecutor`. This applies known
migration classes directly through DBAL — no filesystem scanning, no console
command invocation, no PHAR file extraction.

- In **source/dev**, the Doctrine Migrations console commands
  (`doctrine:migrations:migrate`, etc.) remain available.
- In **PHAR/runtime**, `StartupDatabaseMigrator` runs once per process
  lifetime. The `doctrine_migration_versions` table tracks what has been
  applied, making it safe for concurrent controller + consumer processes.

## Known non-goals

- **Static binary / repack support** — The PHAR requires PHP at runtime
  and a standard Box-compatible PHP installation. There are no plans to
  produce a statically-linked binary or support PHAR repacking.
- **Migration extraction** — Migration classes are loaded directly from
  the PHAR classloader, not extracted to disk.
- **Dev dependencies** — The PHAR contains only production dependencies.
  Dev tools (phpstan, PHPUnit, etc.) are not bundled.

## Troubleshooting

### PHAR stale — rebuild needed

If source, config, or `box.json` changed after the last build:

```bash
castor phar:clean && castor phar:build
```

`castor phar:ensure` also handles this automatically.

### Missing PHP extensions

The PHAR requires the same extensions as source-mode: `pdo_sqlite`,
`mbstring`, `xml`, `intl`. Check with:

```bash
php -m | grep -E 'pdo_sqlite|mbstring|xml|intl'
```

### Wrong CWD / writable dirs not created

If `.hatfield/cache` or `.hatfield/logs` are not being created in the
expected location, verify the working directory:

```bash
php var/tmp/phar/hatfield.phar about
```

This shows the resolved `app.cwd` and cache/log paths. If the current
directory is not writable, use `--cwd`:

```bash
php var/tmp/phar/hatfield.phar agent --cwd=/path/to/project
```

Or set `HATFIELD_CACHE_DIR` / `HATFIELD_LOG_DIR` to absolute paths.

### `composer.json` required in PHAR

If you get `kernel.project_dir` errors with `phar://` URIs, the PHAR
is missing the root `composer.json`. The `box.json` `"files"` section
must include `composer.json`. Rebuild with `castor phar:build`.

### Box 4 `Phar::running()` returning empty

Box 4.x uses auto-generated PHAR aliases. `PharExecutableLocator`
handles this with a fallback: when `Phar::running(false)` returns empty
and `__FILE__` starts with `phar://`, it constructs a `Phar` object from
`__FILE__` to resolve the physical path.

### Symfony DI `phar://` wildcard resource imports

Symfony's DI container uses `GlobResource` / `Finder` for wildcard config
imports (`config/packages/*.yaml`). These rely on `realpath()` which does
not work inside `phar://` stream wrappers. The PHAR build works around
this because `box.json` uses `exclude-composer-files: false`, and the
container is pre-compiled during `composer install --optimize-autoloader`
in staging — the cached container is bundled in the PHAR and does not
re-import wildcards at runtime.
