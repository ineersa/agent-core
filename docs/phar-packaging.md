# PHAR and static distribution packaging

Hatfield ships as:

| Artifact | Purpose |
|---|---|
| `hatfield.phar` | Portable PHAR (platform-neutral; needs system PHP ≥ 8.5) |
| `hatfield.linux-amd64` | Fused PHP-micro native binary |
| `hatfield.linux-arm64` | Fused PHP-micro native binary |
| `hatfield.darwin-amd64` | Fused PHP-micro native binary |
| `hatfield.darwin-arm64` | Fused PHP-micro native binary |
| `SHA256SUMS` | SHA-256 checksums for every release artifact |

There is **no** Windows native binary. The canonical PHAR runs on Windows with PHP 8.5+ and the required extensions (same artifact as Linux/macOS).

## Version / build identity

- Source checkouts report `Hatfield dev (commit <sha>)` via `ApplicationBuildIdentity`.
- Packaged builds embed `HATFIELD_BUILD_VERSION` + `HATFIELD_BUILD_COMMIT` into
  `src/CodingAgent/Build/build-identity.generated.php` during staging.
- `hatfield --version` / Symfony Console application name+version expose that identity
  for PHAR and native artifacts.

## Local Castor tasks

```bash
castor phar:build              # Build worktree-local var/tmp/phar/hatfield.phar
castor phar:ensure             # Build if missing/stale (complete input set)
castor phar:clean              # Remove PHAR, staging, lock
castor phar:info

castor distribution:build                # PHAR → var/tmp/dist/hatfield.phar + checksums
castor distribution:build-static         # Host-native static binary (fails on cross-target)
castor distribution:build-static --target=linux-amd64
castor distribution:checksums
castor distribution:verify               # sizes, smokes, bundled resources, native topology (required)
castor distribution:verify --skip-topology --allow-missing-native  # PHAR-only local verify
castor distribution:info
castor distribution:clean [--all]
```

Convenience wrapper (trap-safe; invokes Castor only):

```bash
scripts/build-distribution.sh --version=1.2.3 --commit=$(git rev-parse HEAD)
scripts/build-distribution.sh --static --target=linux-amd64
```

Environment:

| Variable | Meaning |
|---|---|
| `HATFIELD_PHAR_PATH` | Override PHAR output path |
| `HATFIELD_PHAR_STAGING_DIR` | Override staging dir |
| `HATFIELD_PHAR_BOX_BIN` | Override Box binary |
| `HATFIELD_DIST_DIR` | Override dist directory (default `var/tmp/dist`) |
| `HATFIELD_BUILD_VERSION` | Release version embedded into artifacts |
| `HATFIELD_BUILD_COMMIT` | Exact commit embedded into artifacts |
| `HATFIELD_BINARY_PATH` | Runtime/test override for subprocess executable |
| `HATFIELD_NATIVE_BINARY_PATH` | Test input for native topology tests |

## PHAR build pipeline

Implemented in `.castor/helpers.php` (`CastorTasks`):

1. Delete destination PHAR (no stale success artifact).
2. Fresh staging (`bin/`, `src/`, `config/`, `migrations/`, `internal-docs/`,
   `composer.json`, `composer.lock`, `box.json`).
3. Embed build identity file.
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

## Static / native binaries

Pinned toolchain: `tools/static/pin.json` (immutable static-php-cli commit
`59584de4aa9d8067e4ce30d2ff990e7b9e14db43`, PHP 8.5, `cli+micro`, micro fake CLI).

Flow:

1. Build/ensure canonical PHAR.
2. Clone/checkout pinned static-php-cli under `var/tmp/static-php-cli/<commit>/`.
3. `spc download` + `spc build` with the pin’s extension set.
4. `spc micro:combine <phar> --with-micro=buildroot/bin/micro.sfx --output=<artifact>`.
5. `chmod +x`, size/smoke, native process topology (controller `runtime.ready`).

Local builds only support the **host** target. CI owns the four-target matrix on:

- `ubuntu-latest` (linux-amd64)
- `ubuntu-24.04-arm` (linux-arm64)
- `macos-15-intel` (darwin-amd64)
- `macos-15` (darwin-arm64)

### Native relaunch

`ConfigExecutableLocator` / `PharExecutableLocator` return:

- `[PHP_BINARY, path]` for ordinary PHAR/source
- `[path]` when the resolved artifact **is** the current `PHP_BINARY` (fused micro)

Controller and Messenger children therefore relaunch the same native binary, not
system PHP or a source checkout. `AgentTestExecutable::command()` mirrors this;
`sourceConsoleCommand()` stays source-only for replay/test DI.

## Installer

```bash
curl -fsSL https://raw.githubusercontent.com/ineersa/agent-core/main/installer/bash-installer | bash
# or
bash installer/bash-installer --version=v1.2.3 --install-dir=~/.local/bin
bash installer/bash-installer --static --version=latest
```

Features:

- `--version` (`latest` or tag), `--install-dir`, `--static`
- Linux/macOS + amd64/arm64 detection; clear unsupported-target errors
- PHAR path: PHP ≥ 8.5 + extension checks synchronized with Composer/`bin/console`/docs
- Downloads asset + `SHA256SUMS`, verifies **exact filename** match, fail-closed on mismatch
- Candidate checksum + `--version` smoke **before** replacing any existing install
- Atomic install (`install-dir/.hatfield-install.$$` + `mv`), traps remove every temp
  including install-dir temporary destinations; failures leave previous `hatfield` unchanged
- Post-install `--version` smoke after successful replace
- Mirror/test seam: `HATFIELD_INSTALLER_BASE_URL`

## System PHP requirements (PHAR)

Keep these synchronized across `composer.json` `ext-*`, `bin/console` PHAR guard,
`installer/bash-installer`, `tools/static/pin.json`, and this doc:

- `php` ≥ 8.5
- `ext-pdo_sqlite`, `ext-mbstring`, `ext-xml`, `ext-intl`, `ext-curl`, `ext-openssl`,
  `ext-pcntl`, `ext-posix`, `ext-tokenizer`, `ext-ctype`, `ext-filter`, `ext-iconv`, `ext-phar`

## CI / release

- `.github/workflows/distribution.yml` — PHAR job + static matrix on native runners
  (`ubuntu-latest`, `ubuntu-24.04-arm`, `macos-15-intel`, `macos-15`); calls Castor
  only; caches static-php-cli pin checkout; `upload-artifact` with
  `if-no-files-found: error`. Each static job runs `distribution:verify` including
  hard native process topology.
- `.github/workflows/release.yml` — tag `v*`; validates tag SHA == exact commit;
  builds the **complete five-artifact set** via the same Castor tasks (PHAR + four
  native targets); aggregates into one `SHA256SUMS` containing every asset; enforces
  non-empty/sane sizes and host-compatible `--version` smoke; publishes GitHub Release
  with `hatfield.phar`, `hatfield.linux-amd64`, `hatfield.linux-arm64`,
  `hatfield.darwin-amd64`, `hatfield.darwin-arm64`, and `SHA256SUMS`. Action SHAs are
  pinned; missing files fail closed.

`distribution:verify` hard-requires `hatfield.phar`, host static artifact, and
`SHA256SUMS` by default (use `--allow-missing-native` / `--skip-topology` only for
PHAR-only local checks). Topology never soft-passes on inconclusive process listing.

## Runtime model (unchanged)

PHAR/native writable state lives under the **runtime CWD** (`.hatfield/`), not the
archive path. Executable resolution:

1. `ConfigExecutableLocator` (`HATFIELD_BINARY_PATH`)
2. `PharExecutableLocator` (PHAR / fused micro self)
3. `SourceTreeExecutableLocator` (`bin/console`)

## Testing

```bash
# Unit/contract
castor test --filter=ApplicationBuildIdentityTest
castor test --filter=FusedNativeExecutableLocatorTest
castor test --filter=BashInstallerTest

# PHAR smoke (group phar)
castor phar:build
HATFIELD_BINARY_PATH=var/tmp/phar/hatfield.phar castor test --filter=PharSmokeTest

# Native topology (PHPUnit skip without artifact; CI must supply it)
HATFIELD_NATIVE_BINARY_PATH=var/tmp/dist/hatfield.linux-amd64 castor test --filter=NativeProcessTopologyTest

# TUI artifact boot (tmux + real packaged artifact; Castor ensures PHAR for this filter)
castor test:tui --filter=TuiArtifactBootE2eTest
```

Notes:

- Controller **replay** / default TUI E2E use **source** `bin/console` with `APP_ENV=test`
  so test DI and replay fixtures load. They do **not** require PHAR.
- Live controller E2E also uses source console for the same reason.
- `TuiArtifactBootE2eTest` hard-fails without a packaged artifact (no soft pass).
- `NativeProcessTopologyTest` is a real PHPUnit skip when `HATFIELD_NATIVE_BINARY_PATH`
  is absent; `distribution:verify` / CI always require the native artifact.
- Installer tests cover checksum mismatch and candidate smoke failure rollback
  (previous install unchanged, no install-dir temps left).

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

### Static build unsupported target

Local static builds must match the host. Use CI for other arches.

### Checksum mismatch on install

Installer refuses to install. Re-download; confirm `SHA256SUMS` entry is an exact
filename match for the selected asset.

### Box 4 `Phar::running()` empty

`PharExecutableLocator` falls back to constructing `Phar` from `phar://` `__FILE__`.

## Windows

Windows users install the **canonical PHAR** with PHP 8.5+ (same file as other OS).
Native fused binaries are Linux/macOS only. No separate Windows PHAR build is required
because the PHAR is platform-neutral bytecode + stub.
