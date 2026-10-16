# Static / native packaging

Fused PHP-micro binaries for Linux and macOS, built from the **canonical PHAR handoff**
plus pinned static-php-cli (SPC) micro SFX.

Related: [distribution.md](distribution.md) · [phar-packaging.md](phar-packaging.md) ·
[`tools/static/README.md`](../tools/static/README.md)

## Artifacts

| Artifact | Typical runner |
|---|---|
| `hatfield.linux-amd64` | `ubuntu-latest` |
| `hatfield.linux-amd64.php` | Owned static PHP CLI companion |
| `hatfield.linux-arm64` | `ubuntu-24.04-arm` |
| `hatfield.linux-arm64.php` | Owned static PHP CLI companion |
| `hatfield.darwin-amd64` | `macos-15-intel` |
| `hatfield.darwin-amd64.php` | Owned static PHP CLI companion |
| `hatfield.darwin-arm64` | `macos-15` |
| `hatfield.darwin-arm64.php` | Owned static PHP CLI companion |

Local builds support the **host** target only. The four-target matrix runs on tag `v*` releases.

Each fused native ships with a matching `hatfield.<target>.php` companion. That file is the
owned static PHP CLI for Amp workers. It is not a Hatfield application binary. Release
checksums and uploads include all eight native/companion files plus the PHAR.

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
4. Combine micro SFX with the exact PHAR bytes using `micro.php_binary=hatfield-php`.
5. Publish the owned CLI beside the fused artifact as `hatfield.<target>.php`.
6. Smoke the fused artifact and native controller topology.

Native binaries embed the same packaged resources as the PHAR (selected docs included). They do not add project extension packages.

## Runtime layout

Fused natives set `PHP_BINARY` to the basename `hatfield-php`. Application bootstrap
prepends the directory that contains the adjacent helper of that name. Install directories
do not need to be on the caller `PATH`.

The static installer stores a matched pair under
`.hatfield-runtime-<id>/{hatfield,hatfield-php}` and publishes `install_dir/hatfield` as
an entry symlink to that runtime app. Bootstrap resolves `realpath(self)`, so the helper
remains adjacent behind the symlink. Failed activation never moves the previous entry.
Previous runtime directories remain so in-flight processes keep their matched pair; remove
stale `.hatfield-runtime-*` directories manually after those processes exit.

Host/build-tree PHP is never used as a fused-native fallback.
