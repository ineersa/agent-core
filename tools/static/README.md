# Static PHP toolchain pin

Hatfield native binaries are built with [static-php-cli](https://github.com/crazywhalecc/static-php-cli)
at the immutable commit in `pin.json`, fused with the **canonical PHAR handoff**.

User guide: [`docs/static-packaging.md`](../../docs/static-packaging.md) ·
Release: [`docs/distribution.md`](../../docs/distribution.md).

## Core pins (fail-closed)

| Field | Purpose |
|---|---|
| `static_php_cli_commit` / `static_php_cli_repository` | SPC checkout |
| `php_version` | Exact patch (`8.5.8`) |
| `php_source_sha256` | Official `php-{version}.tar.xz` digest after SPC download |
| `phpmicro_repository` / `phpmicro_commit` | Local checkout fed as `--custom-local=php-micro:<abs>` |
| `phpmicro_patch` / `phpmicro_patch_sha256` | Tracked Linux self-path patch + content digest |
| `extensions` | SPC SFX set — **superset** of system-PHAR guards, not identical |
| `micro_fake_cli` | Symfony CLI-friendly micro |

Libraries beyond PHP + phpmicro are **not** fully locked. Do not float SPC branches/tags
from build tasks; change the pin and validate a host static build.

### Linux phpmicro self-path patch

Pinned commit still uses `realpath(getauxval(AT_EXECFN))` in `micro_get_filename()`.
Relative invocation can place that string at a page boundary; musl `realpath()` then
SIGSEGVs (v0.0.2 publish regression). Before SPC build, Castor resets
`php_micro_fileinfo.c` and applies `phpmicro-linux-self-path.patch`
(`realpath("/proc/self/exe")`). Fail-closed on missing/hash/apply mismatch.
Remove once upstream no longer realpaths AT_EXECFN on Linux.

## Build flow

1. Canonical PHAR already in `var/tmp/dist/hatfield.phar` (release handoff) **or**
   built via `phar_ensure` for local standalone static.
2. Clone pinned SPC + pinned phpmicro under `var/tmp/static-php-cli/` (phpmicro at
   `var/tmp/static-php-cli/phpmicro/<commit>/`), then apply the tracked Linux self-path patch.
3. `spc download --with-php=8.5.8 --custom-local=php-micro:<path> …` then verify
   `php-8.5.8.tar.xz` SHA-256 against the pin.
4. `spc build` with `--no-smoke-test=micro` (upstream bare-micro segfault workaround).
5. `spc micro:combine <phar> --with-micro=buildroot/bin/micro.sfx --output=<artifact>`.
6. Native fused-artifact smoke runs `./hatfield` via a symlink in an isolated CWD so the
   relative-invocation crash cannot be masked by absolute paths.
7. `castor distribution:verify` (version/list + topology).

Release cache path includes `var/tmp/static-php-cli` (SPC + phpmicro) and
`var/tmp/static-build`, keyed by `pin.json` + `composer.lock`.

## Host prerequisites

Required (Castor preflight): `cc`/`gcc`/`clang`, `make`, `cmake`, `re2c`, `flex`,
`gperf`, plus `pkg-config`/autotools as needed by SPC libs.

SPC `doctor --auto-fix` is optional and fails under `no-new-privileges`. Install via
host packages. Tag-release jobs use `.github/actions/static-prerequisites` before any
static Castor build. Local native proof remains blocked without those packages.
