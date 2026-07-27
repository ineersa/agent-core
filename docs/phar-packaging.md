# PHAR packaging

Platform-neutral `hatfield.phar` for any host with PHP ≥ 8.5 and the required extensions.

Related: [Distribution / release](distribution.md) · [Static / native binaries](static-packaging.md)

## System PHP requirements

Keep synchronized across `composer.json` `ext-*`, `bin/console` PHAR guard,
`installer/bash-installer`, and this doc (system-PHAR path):

- `php` ≥ 8.5
- `ext-pdo_sqlite`, `ext-mbstring`, `ext-xml`, `ext-intl`, `ext-curl`, `ext-openssl`,
  `ext-pcntl`, `ext-posix`, `ext-tokenizer`, `ext-ctype`, `ext-filter`, `ext-iconv`, `ext-phar`

`tools/static/pin.json` extension list is a **deliberate SFX superset** for fused native
builds (extra transitive micro/build deps). It is **not** required to match this system-PHAR
guard list one-for-one — see [static-packaging.md](static-packaging.md).

## Castor tasks

```bash
castor phar:build    # Build worktree-local var/tmp/phar/hatfield.phar
castor phar:ensure   # Build if missing/stale (complete input set)
castor phar:clean    # Remove PHAR, staging, lock, fingerprint sidecar
castor phar:info
```

Environment:

| Variable | Meaning |
|---|---|
| `HATFIELD_PHAR_PATH` | Override PHAR output path |
| `HATFIELD_PHAR_STAGING_DIR` | Override staging dir |
| `HATFIELD_PHAR_BOX_BIN` | Override Box binary |
| `HATFIELD_BUILD_VERSION` | Release version embedded during staging |
| `HATFIELD_BUILD_COMMIT` | Exact commit embedded during staging |
| `HATFIELD_BINARY_PATH` | Runtime/test override for subprocess executable |

Release-level orchestration (`distribution:build`, checksums, installer) is in
[distribution.md](distribution.md).

## Build pipeline

Implemented in `.castor/helpers.php` (`CastorTasks`):

1. Delete destination PHAR (no stale success artifact).
2. Fresh staging (`bin/`, `src/`, `config/`, `migrations/`, `internal-docs/`,
   `composer.json`, `composer.lock`, `box.json`).
3. Embed build identity into `src/CodingAgent/Build/build-identity.generated.php`.
4. Staging-only Composer `autoloader-suffix=HatfieldPharBuild`.
5. `composer install --no-dev --optimize-autoloader` (checked exit status).
6. Box compile (checked exit status).
7. Fail-fast smoke (`list`, `about`, `agent --help`, `--version`, writable-dir isolation).

Freshness (`phar:ensure` + lock-holder re-check) uses the **same** packaged-input
set as staging (`phar_packaged_inputs`) and compares a deterministic content
fingerprint sidecar (`hatfield.phar.inputs.sha256`). The fingerprint hashes every
packaged file **and resolved symlink targets** (critical for `internal-docs/*` →
`docs/*`), plus build-identity env inputs. Failed builds remove both the PHAR and
the freshness marker. Distribution PHAR reuse always calls `phar_ensure()` — never
a separate mtime shortcut.

All shell/copy/write/remove steps fail-fast with command/output diagnostics.
Smoke failures throw and remove the artifact.

Release static jobs must consume the exact `distribution:build` PHAR via dist handoff
(no rebuild). Details: [distribution.md](distribution.md).

## Runtime model

Writable state lives under the **runtime CWD** (`.hatfield/`), not the archive path.

Executable resolution for PHAR/source:

1. `ConfigExecutableLocator` (`HATFIELD_BINARY_PATH`) → `[PHP_BINARY, path]`
2. `PharExecutableLocator` (PHAR self) → `[PHP_BINARY, physical-phar-path]`
3. `SourceTreeExecutableLocator` → `[PHP_BINARY, bin/console]`

Ordinary PHAR always uses the two-element form (system PHP + PHAR path).
Fused native one-element relaunch is documented in [static-packaging.md](static-packaging.md).

Box 4 may leave `Phar::running()` empty; `PharExecutableLocator` falls back to
constructing `Phar` from `phar://` `__FILE__`.

## Testing

```bash
castor phar:build
HATFIELD_BINARY_PATH=var/tmp/phar/hatfield.phar castor test --filter=PharSmokeTest

# TUI boots the real packaged artifact (Castor ensures PHAR for this filter)
castor test:tui --filter=TuiArtifactBootE2eTest
```

Notes:

- Controller **replay** / default TUI E2E use **source** `bin/console` with `APP_ENV=test`
  so test DI and replay fixtures load. They do **not** require PHAR.
- Live controller E2E also uses source console for the same reason.
- `TuiArtifactBootE2eTest` hard-fails without a packaged artifact (no soft pass).

## Troubleshooting

### Stale PHAR

```bash
castor phar:clean && castor phar:build
```

### Smoke / Box failure leaves no artifact

Expected: failed compile or smoke deletes the destination PHAR so it cannot be
mistaken for success.

### Missing extensions

```bash
php -m | grep -E 'pdo_sqlite|mbstring|xml|intl|curl|openssl|pcntl|posix'
```

### Box 4 `Phar::running()` empty

`PharExecutableLocator` falls back to constructing `Phar` from `phar://` `__FILE__`.

## Windows

Windows users install the **canonical PHAR** with PHP 8.5+ (same file as other OS).
There is no Windows native binary and no separate Windows PHAR build — the PHAR is
platform-neutral bytecode + stub. See [static-packaging.md](static-packaging.md)
for Linux/macOS natives.
