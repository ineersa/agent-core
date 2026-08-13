# Distribution and release

Release-level packaging: canonical PHAR, fused static binaries, checksums, installer, package mirrors.

Internals: [phar-packaging.md](phar-packaging.md) · [static-packaging.md](static-packaging.md)

## Artifacts

| Artifact | Purpose |
|---|---|
| `hatfield.phar` | Portable PHAR (system PHP ≥ 8.5) |
| `hatfield.linux-amd64` / `linux-arm64` | Fused PHP-micro native |
| `hatfield.darwin-amd64` / `darwin-arm64` | Fused PHP-micro native |
| `SHA256SUMS` | SHA-256 for every release asset |

Windows uses the PHAR (no Windows native binary).

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
4. Bundled resources include defaults, themes, migrations, Extension API package, and selected `builtin: true` docs at canonical paths.

## Installer

```bash
bash installer/bash-installer --version=v1.2.3 --install-dir=~/.local/bin
bash installer/bash-installer --static --version=latest
```

Downloads asset + `SHA256SUMS`, verifies checksums, smokes a candidate, then atomic install. Failures must not replace a previous good install.

## Extension package mirrors

Release splitting mirrors the public Extension API and extension packages into read-only GitHub/Packagist-facing repositories. Package READMEs travel with those mirrors. Core `hatfield_docs` does **not** auto-discover installed extension docs.

## Related

- Process executable resolution: `src/CodingAgent/Runtime/Process/AGENTS.md`
- Docs catalog validation: `castor docs:validate`
