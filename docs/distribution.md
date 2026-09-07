# Distribution and release

Release-level packaging: canonical PHAR, fused static binaries, checksums, installer, package mirrors.
Architecture overview: [build and packaging](../architecture/build-and-observability.md).

For end-user setup, upgrades, and troubleshooting, see [installation and upgrades](installation.md).

Internals: [phar-packaging.md](phar-packaging.md) · [static-packaging.md](static-packaging.md)

## Artifacts

| Artifact | Purpose |
|---|---|
| `hatfield.phar` | Portable PHAR (system PHP ≥ 8.5) |
| `hatfield.linux-amd64` | Fused PHP-micro native (Linux x86_64) |
| `hatfield.linux-arm64` | Fused PHP-micro native (Linux aarch64) |
| `hatfield.darwin-amd64` | Fused PHP-micro native (macOS x86_64) |
| `hatfield.darwin-arm64` | Fused PHP-micro native (macOS arm64) |
| `SHA256SUMS` | SHA-256 for every release asset |

**Windows / non-POSIX:** the Bash installer and native matrix are **Linux/macOS only**. There is no
Windows native binary and no Windows installer path. The PHAR is platform-neutral in packaging, but
runtime requires **POSIX-capable PHP with `pcntl` and `posix`** (plus the other PHAR extensions in
[phar-packaging.md](phar-packaging.md)). Stock Windows PHP builds lack those extensions, so Windows
is unsupported for normal product runs even with a downloaded PHAR.

## Local orchestration

```bash
castor distribution:build
castor distribution:build-static
castor distribution:checksums
castor distribution:verify
castor distribution:verify --skip-topology --allow-missing-native
scripts/build-distribution.sh --version=1.2.3 --commit=$(git rev-parse HEAD)
```

| Variable | Meaning |
|---|---|
| `HATFIELD_DIST_DIR` | Dist directory (default `var/tmp/dist`) |
| `HATFIELD_BUILD_VERSION` / `HATFIELD_BUILD_COMMIT` | Embedded identity |
| `HATFIELD_BINARY_PATH` / `HATFIELD_NATIVE_BINARY_PATH` | Test overrides |

## Canonical PHAR handoff

1. Build/smoke `var/tmp/dist/hatfield.phar`.
2. Static jobs reuse that exact PHAR (no rebuild/overwrite of a handoff PHAR).
3. Local standalone static builds may `phar_ensure` + copy only when dist PHAR is absent.
4. Bundled resources include defaults, themes, migrations, Extension API package source,
   and selected `builtin: true` docs at canonical paths (no `internal-docs/`, no unmarked docs).

## Installer

No repository checkout is required. The installer defaults to PHAR and installs
the `hatfield` command into `~/.local/bin`. Before installing the PHAR, provide
PHP 8.5 or later with the [required extensions](phar-packaging.md#system-php-requirements).

### Install the PHAR

```bash
curl --proto '=https' --tlsv1.2 -fsSL \
	https://raw.githubusercontent.com/ineersa/agent-core/main/installer/bash-installer \
	| bash -s --
```

### Install the native binary

The native binary includes PHP. Use `--static` on a supported Linux or macOS host:

```bash
curl --proto '=https' --tlsv1.2 -fsSL \
	https://raw.githubusercontent.com/ineersa/agent-core/main/installer/bash-installer \
	| bash -s -- --static
```

For either format, add the install directory to your shell's `PATH` if needed:

```bash
export PATH="$HOME/.local/bin:$PATH"
hatfield --version
```

### Select a release or install directory

Append `--version=vX.Y.Z` to either remote installer command to pin a release.
Use `--install-dir="$HOME/bin"` to change the destination. From a repository checkout,
the equivalent commands are:

```bash
# Latest PHAR into ~/.local/bin
bash installer/bash-installer --version=latest

# Pinned release tag, custom install dir (quote $HOME — bare ~ in --install-dir is not expanded by the flag parser)
bash installer/bash-installer --version=v1.2.3 --install-dir="$HOME/.local/bin"

# Fused native binary for the current Linux/macOS platform
bash installer/bash-installer --static --version=latest
```

### Upgrade and verification

Run the installer again to upgrade or reinstall. It performs these steps:

- Resolves GitHub release assets for the requested version (`latest` or explicit tag).
- Downloads the asset plus `SHA256SUMS`, verifies the checksum, then **smokes** the
  candidate (`--version`) in an isolated temp HOME/cache/log tree.
- Installs with atomic replace into `--install-dir` (default `~/.local/bin`).
- Failures must not replace a previous good install.
- Re-running for the same or newer version is the supported upgrade/reinstall path
  (download → verify → smoke → atomic install).

### Installer troubleshooting

| Symptom | Likely cause / action |
|---|---|
| `OS '…' not supported` / Windows | Installer is Linux/macOS only; product runtime needs POSIX PHP (`pcntl`/`posix`) — Windows is unsupported |
| Architecture not supported | Only `amd64` and `arm64` are in the matrix |
| PHP required (PHAR path) | Install PHP ≥ 8.5 with required extensions, or pass `--static` on supported OS/arch |
| Checksum mismatch | Corrupt download or wrong `SHA256SUMS`; retry; do not force-install |
| Candidate smoke failed | Binary/PHAR will not be installed; inspect smoke error; fix PHP/extensions or pick another asset |
| Missing release asset | Confirm the tag published that platform artifact |

## Extension package mirrors

Release splitting mirrors the public Extension API and five project extensions into read-only GitHub/Packagist-facing repositories (`hatfield-extension-api`, `hatfield-ext-task-workflow`, `hatfield-ext-castor-llm-mode`, `hatfield-ext-file-rewind`, `hatfield-ext-observational-memory`, `hatfield-ext-jbcontext`). Package READMEs travel with those mirrors. Core `hatfield_docs` does **not** auto-discover installed extension docs.

## Related

- Process executable resolution: `src/CodingAgent/Runtime/Process/AGENTS.md`
- Docs catalog validation: `castor docs:validate`
