# Distribution and release

Release-level packaging: canonical PHAR, fused static binaries, checksums, installer, package mirrors.

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

**Windows:** the Bash installer and native matrix are **Linux/macOS only**. Windows may run a
manually downloaded platform-neutral `hatfield.phar` with a suitable system PHP (≥ 8.5);
there is no Windows native binary and no Windows installer path.

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

```bash
# Latest PHAR into ~/.local/bin
bash installer/bash-installer --version=latest

# Pinned release tag, custom install dir
bash installer/bash-installer --version=v1.2.3 --install-dir=~/.local/bin

# Fused native binary for the current Linux/macOS platform
bash installer/bash-installer --static --version=latest
```

Behavior (source-backed):

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
| `OS '…' not supported` / Windows | Use manual PHAR + system PHP; installer is Linux/macOS only |
| Architecture not supported | Only `amd64` and `arm64` are in the matrix |
| PHP required (PHAR path) | Install PHP ≥ 8.5 with required extensions, or pass `--static` on supported OS/arch |
| Checksum mismatch | Corrupt download or wrong `SHA256SUMS`; retry; do not force-install |
| Candidate smoke failed | Binary/PHAR will not be installed; inspect smoke error; fix PHP/extensions or pick another asset |
| Missing release asset | Confirm the tag published that platform artifact |

## Extension package mirrors

Release splitting mirrors the public Extension API and extension packages into read-only GitHub/Packagist-facing repositories. Package READMEs travel with those mirrors. Core `hatfield_docs` does **not** auto-discover installed extension docs.

## Related

- Process executable resolution: `src/CodingAgent/Runtime/Process/AGENTS.md`
- Docs catalog validation: `castor docs:validate`
