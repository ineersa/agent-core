# Distribution and release

Release-level packaging: five binaries + checksums, installer, CI, and tag publish.

Internals: [PHAR packaging](phar-packaging.md) · [Static / native packaging](static-packaging.md)

## Artifacts

| Artifact | Purpose |
|---|---|
| `hatfield.phar` | Portable PHAR (system PHP ≥ 8.5) |
| `hatfield.linux-amd64` | Fused PHP-micro native |
| `hatfield.linux-arm64` | Fused PHP-micro native |
| `hatfield.darwin-amd64` | Fused PHP-micro native |
| `hatfield.darwin-arm64` | Fused PHP-micro native |
| `SHA256SUMS` | SHA-256 for every release asset |

No Windows native binary; Windows uses the same canonical PHAR.

## Version / build identity

- Source checkouts: `Hatfield dev (commit <sha>)` via `ApplicationBuildIdentity`.
- Packaged builds embed `HATFIELD_BUILD_VERSION` + `HATFIELD_BUILD_COMMIT` into
  `src/CodingAgent/Build/build-identity.generated.php` during PHAR staging.
- `hatfield --version` / Symfony Console name+version expose that identity on PHAR
  and native artifacts.
- PR CI uses `pr-N` + pull-request head SHA; release tags use `github.ref_name` +
  tag commit.

## Local orchestration

```bash
castor distribution:build                # PHAR → var/tmp/dist/hatfield.phar + checksums
castor distribution:build-static         # Host-native static (see static-packaging.md)
castor distribution:build-static --target=linux-amd64
castor distribution:checksums
castor distribution:verify               # sizes, smokes, resources, native topology
castor distribution:verify --skip-topology --allow-missing-native  # PHAR-only local
castor distribution:info
castor distribution:clean [--all]
```

Convenience wrapper (trap-safe; invokes Castor only):

```bash
scripts/build-distribution.sh --version=1.2.3 --commit=$(git rev-parse HEAD)
scripts/build-distribution.sh --static --target=linux-amd64
# also accepts --version=1.2.3 equals form
```

| Variable | Meaning |
|---|---|
| `HATFIELD_DIST_DIR` | Dist directory (default `var/tmp/dist`) |
| `HATFIELD_BUILD_VERSION` | Release version embedded into artifacts |
| `HATFIELD_BUILD_COMMIT` | Exact commit embedded into artifacts |
| `HATFIELD_BINARY_PATH` | Runtime/test override for subprocess executable |
| `HATFIELD_NATIVE_BINARY_PATH` | Test input for native topology tests |

`distribution:verify` hard-requires `hatfield.phar`, host static artifact, and
`SHA256SUMS` by default. Use `--allow-missing-native` / `--skip-topology` only for
PHAR-only local checks. Topology rules: [static-packaging.md](static-packaging.md).

Local native builds hard-fail without SPC host tools (`re2c`, `flex`, `gperf`, …) —
see [`tools/static/README.md`](../tools/static/README.md).

## Installer

```bash
curl -fsSL https://raw.githubusercontent.com/ineersa/agent-core/main/installer/bash-installer | bash
bash installer/bash-installer --version=v1.2.3 --install-dir=~/.local/bin
bash installer/bash-installer --static --version=latest
```

| Flag / env | Behavior |
|---|---|
| `--version` (`latest` or tag) | Release selection |
| `--install-dir` | Default `~/.local/bin` |
| `--static` | Platform native binary instead of PHAR |
| `HATFIELD_GITHUB_REPO` / `HATFIELD_DOWNLOAD_BASE` | Source overrides |
| `HATFIELD_INSTALLER_BASE_URL` | Mirror/test seam (local fixture server) |

Behavior:

- Linux/macOS + amd64/arm64 detection; clear unsupported-target errors
- PHAR path: PHP ≥ 8.5 + extension checks (synced with Composer / `bin/console` /
  [phar-packaging.md](phar-packaging.md))
- Downloads asset + `SHA256SUMS`; verifies **exact filename** entry; fail-closed on
  mismatch or empty download
- Candidate `--version` smoke **before** replacing any existing install
- Atomic install (`install-dir/.hatfield-install.$$` + `mv`); traps remove download
  workspace **and** install-dir temps; failures leave previous `hatfield` unchanged
- Post-install `--version` smoke after successful replace

## CI and release

### PR / main — `.github/workflows/distribution.yml`

Triggers: `push` to `main`, **pull requests**, `workflow_dispatch`.

1. Build canonical PHAR via Castor (`distribution:build`).
2. Static matrix on native runners (`ubuntu-latest`, `ubuntu-24.04-arm`,
   `macos-15-intel`, `macos-15`) after `.github/actions/static-prerequisites`.
3. Castor-only build/verify; SPC cache; `upload-artifact` with `if-no-files-found: error`.
4. Each static job runs hard native process topology.

### Tag `v*` — `.github/workflows/release.yml`

1. Validate tag SHA == exact checked-out commit.
2. Build complete five-artifact set via the same Castor tasks (PHAR + four natives).
3. Aggregate into one `SHA256SUMS` listing **exactly** those five files.
4. Enforce non-empty/sane sizes and host-compatible `--version` smoke.
5. Publish GitHub Release with all six files. External action SHAs are pinned;
   missing files fail closed — no partial release.

Castor in CI: checksum-verified platform PHAR via `.github/actions/setup-castor`
(not Composer-global, not static Castor — both break `PHP_BINARY` / nested vendor).

## Release checklist

1. Green distribution matrix on the exact commit (PHAR + four static jobs).
2. Tag `vX.Y.Z` pointing at that commit only.
3. Confirm release assets: five binaries + `SHA256SUMS` (six files).
4. Smoke installer: PHAR path and `--static` path against the published tag.
5. Confirm `--version` shows release version + commit on both artifact kinds.

## Testing (orchestration surface)

```bash
castor test --filter=ApplicationBuildIdentityTest
castor test --filter=BashInstallerTest
```

Installer tests cover checksum mismatch and candidate smoke-failure rollback
(previous install unchanged, no install-dir temps left). PHAR/static unit/topology
commands live in the linked guides.
