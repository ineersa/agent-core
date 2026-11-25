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
| `hatfield.darwin-arm64` | `macos-15` |

Local builds support the **host** target only. The three-target matrix runs on tag `v*` releases.
Intel macOS has no native target; use the [PHAR with system PHP](installation.md#install-the-phar).

## Pin file

`tools/static/pin.json` pins SPC commit, PHP patch + source hash, phpmicro commit/patch, and the SFX extension set (deliberate superset of system-PHAR guards).

Native builds include `fileinfo` for MIME detection, `gd` for image resizing, and
`exif` for JPEG orientation. GD and EXIF remain optional for system-PHP installs.

Native builds also include `zstd` for lossless ChatGPT request compression.
The pinned SPC revision provides `config/pkg/ext/ext-zstd.yml`, which depends on
the `zstd` library recipe. Use `zstd` in the pin's extension list, without `ext-`.
System-PHP and PHAR installations require `ext-zstd` too; Composer checks it
when installing dependencies for the PHAR build.

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

## Compiled runtime cache

`castor distribution:runtime-fingerprint` prints the key for the current host.
The key covers the native target, pin and patch, SPC arguments and library
recipes, static prerequisites, toolchain and SDK identities, and relevant build
environment (including the CI runner image version). The app's PHAR, Composer
lockfile and packaging task source do not contribute to it. SPC still resolves
some dependency downloads without immutable source or binary hashes: this is
reuse of a verified recipe, not a lock of all transitive native inputs.

`distribution:build-static` reuses a checksum-verified `php` and `micro.sfx`
from `var/tmp/static-runtime/<fingerprint>` after checking the CLI's cURL HTTP/2
support. It always fuses the canonical PHAR and runs the same fused artifact
and native topology smoke checks. Only the release workflow caches these
compiled outputs as `static-runtime-<fingerprint>` prereleases, shared across
app tags but never marked latest. Run the Release workflow manually on the
default branch to prepare runtimes without publishing an app release. An
incomplete runtime release needs operator inspection; it is not a cache miss
and the workflow never overwrites its assets. Increment `runtime_revision` in
`tools/static/pin.json` deliberately to rebuild with freshly resolved downloads.
`castor distribution:clean` removes the local runtime cache.

Each runtime and app release includes an SPC download inventory: source and
binary archive filenames, versions, SHA-1s, or Git commit IDs when available.
This is not a linkage SBOM. From the original SPC build working directory,
run `bin/spc check-update --json` using the pinned SPC executable to compare
its cached downloads with upstream versions where supported.

Release jobs provision their separate system PHP using setup-php for bundled
extensions and checksum-pinned PIE 1.6.0 for `kjdev/zstd:0.19.0`. They verify the
loaded version before Composer. This does not change the native runtime inputs.

## Runtime

Fused binaries may present empty `PHP_BINARY` or `PHP_BINARY` equal to the artifact path. `AppExecutableLocator` treats that as native and does not prefix `php`.

The session-owned sqlite-queue broker launches Amp SQLite workers through another
invocation of the same fused binary with `--internal-sqlite-worker`. Releases ship
one native artifact per target. There is no companion PHP CLI.
