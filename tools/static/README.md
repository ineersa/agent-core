# Static PHP toolchain pin

Hatfield native binaries are built with [static-php-cli](https://github.com/crazywhalecc/static-php-cli)
at the immutable commit declared in `pin.json`.

User guide (artifacts, relaunch, topology, CI matrix, troubleshooting):
[`docs/static-packaging.md`](../../docs/static-packaging.md).
Release overview: [`docs/distribution.md`](../../docs/distribution.md).

Do not float on branches or tags from build tasks. Update the pin only by
changing `static_php_cli_commit` and validating a full host static build.

## Build flow

1. `castor phar:build` produces the canonical `hatfield.phar`.
2. `castor distribution:build-static --target=linux-amd64` clones the pinned
   static-php-cli commit (cached under `var/tmp/static-php-cli/`), builds
   `cli,micro` with the listed extensions (and `--no-smoke-test=micro` for the
   pinned PHP 8.5 bare-micro segfault workaround — see the user guide), then runs:

   ```bash
   bin/spc micro:combine <phar> --with-micro=buildroot/bin/micro.sfx --output=<artifact>
   ```

3. `castor distribution:verify` smokes the artifact and process topology.

CI owns the four-target matrix; local builds only support host-compatible targets.

## Host prerequisites for `castor distribution:build-static`

Required on the build host (checked by Castor preflight):

- C compiler (`cc`/`gcc`/`clang`)
- `make`, `cmake`
- `re2c`, `flex`, `gperf`
- `pkg-config`, autotools (`autoconf`/`automake`/`libtool`) as needed by SPC libs

SPC `doctor --auto-fix` may try to install these via sudo; that path is optional
and fails closed in restricted containers (`no new privileges`). Install the tools
with the host package manager instead. Without them, static builds fail during
library configure (often opaque `zlib` / "Missing or broken C compiler" errors).

### CI

GitHub Actions install the same host toolchain through the checked-in composite
action `.github/actions/static-prerequisites` (used by both
`.github/workflows/distribution.yml` and `release.yml` **before** any Castor
static build). Linux uses apt (`build-essential`, `cmake`, `pkg-config`,
autotools, `re2c`, `flex`, `gperf`, `bison`); macOS uses Homebrew and appends
keg-only `flex` to `GITHUB_PATH`. Unsupported runner OS fails closed.

Local native proof remains blocked until the packages above are installed on the
developer machine — CI does not replace a local package install.
