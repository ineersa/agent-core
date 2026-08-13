# Static / native packaging

Fused PHP-micro binaries for Linux and macOS, built from the **canonical PHAR handoff**
plus pinned static-php-cli (SPC) micro SFX.

Related: [distribution.md](distribution.md) · [phar-packaging.md](phar-packaging.md) ·
[`tools/static/README.md`](../tools/static/README.md)

## Artifacts

| Artifact | Typical runner |
|---|---|
| `hatfield.linux-amd64` | `ubuntu-latest` |
| `hatfield.linux-arm64` | `ubuntu-24.04-arm` |
| `hatfield.darwin-amd64` | `macos-15-intel` |
| `hatfield.darwin-arm64` | `macos-15` |

Local builds support the **host** target only. The four-target matrix runs on tag `v*` releases.

## Pin file

`tools/static/pin.json` pins SPC commit, PHP patch + source hash, phpmicro commit/patch, and the SFX extension set (deliberate superset of system-PHAR guards).

## Build flow

```bash
castor distribution:build-static
castor distribution:verify
```

1. Resolve canonical PHAR (existing dist handoff preferred; else `phar_ensure` + copy).
2. Clone/checkout pinned SPC + phpmicro; apply tracked Linux self-path patch when required.
3. `spc download` / `spc build` with pin extensions and micro/cli targets.
4. Combine micro SFX with the exact PHAR bytes; smoke the native artifact.

Native binaries embed the same packaged resources as the PHAR (selected docs included). They do not add project extension packages.

## Runtime

Fused binaries may present empty `PHP_BINARY` or `PHP_BINARY` equal to the artifact path. `AppExecutableLocator` treats that as native and does not prefix `php`.
