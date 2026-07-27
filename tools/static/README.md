# Static PHP toolchain pin

Hatfield native binaries are built with [static-php-cli](https://github.com/crazywhalecc/static-php-cli)
at the immutable commit declared in `pin.json`.

Do not float on branches or tags from build tasks. Update the pin only by
changing `static_php_cli_commit` and validating a full host static build.

## Build flow

1. `castor phar:build` produces the canonical `hatfield.phar`.
2. `castor distribution:build-static --target=linux-amd64` clones the pinned
   static-php-cli commit (cached under `var/tmp/static-php-cli/`), builds
   `cli,micro` with the listed extensions, then runs:

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

SPC `doctor --auto-fix` may try to install these via sudo; that path is optional
and fails closed in restricted containers (`no new privileges`). Install the tools
with the host package manager instead. Without them, static builds fail during
library configure (often opaque `zlib` / "Missing or broken C compiler" errors).
