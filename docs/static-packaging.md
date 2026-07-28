# Static / native packaging

Fused PHP-micro binaries for Linux and macOS. Built from the **canonical PHAR handoff**
plus a pinned static-php-cli (SPC) micro SFX.

Related: [Distribution / release](distribution.md) · [PHAR packaging](phar-packaging.md) ·
toolchain: [`tools/static/README.md`](../tools/static/README.md)

## Artifacts and targets

| Artifact | Runner / host |
|---|---|
| `hatfield.linux-amd64` | `ubuntu-latest` |
| `hatfield.linux-arm64` | `ubuntu-24.04-arm` |
| `hatfield.darwin-amd64` | `macos-15-intel` |
| `hatfield.darwin-arm64` | `macos-15` |

Local builds only support the **host** target. The four-target matrix runs only on
tag `v*` release ([distribution.md](distribution.md)).

## Pinned toolchain (core reproducibility)

`tools/static/pin.json` pins:

| Key | Meaning |
|---|---|
| `static_php_cli_commit` | Immutable SPC commit |
| `php_version` | Exact patch (`8.5.8`) — not `8.5` |
| `php_source_sha256` | Official `php-8.5.8.tar.xz` digest |
| `phpmicro_repository` / `phpmicro_commit` | Immutable phpmicro checkout |
| `phpmicro_patch` / `phpmicro_patch_sha256` | Tracked Linux `/proc/self/exe` self-path patch + digest |
| `extensions` | SPC SFX extension set (deliberate **superset** of system-PHAR guards) |
| `micro_fake_cli` | Symfony CLI-friendly micro |

**Scope:** PHP patch + official source hash + phpmicro commit + tracked Linux patch + SPC commit are pinned.
Other SPC libraries are **not** fully locked. Extension list is not identical to
Composer/`bin/console`/installer system-PHAR guards — see [phar-packaging.md](phar-packaging.md).

Build ensures phpmicro, verifies patch SHA-256, resets `php_micro_fileinfo.c`, applies the
patch, then passes `--with-php=8.5.8`, `--custom-local=php-micro:<abs path>`, and
fail-closed verifies the downloaded `php-8.5.8.tar.xz` hash. phpmicro lives under
`var/tmp/static-php-cli/phpmicro/<commit>/` (covered by the release cache path).

**Linux relative-invocation patch:** pinned phpmicro still does
`realpath(getauxval(AT_EXECFN))`; relative `./hatfield` can SIGSEGV when AT_EXECFN sits at a
page boundary (v0.0.2). Patch switches to `realpath("/proc/self/exe")`. Remove after upstream
stops realpathing AT_EXECFN on Linux.

## Build flow

```bash
castor distribution:build-static
castor distribution:verify
```

1. Resolve canonical PHAR: existing non-empty `dist/hatfield.phar` is smoked and used
   as-is (release handoff). Only if absent: `phar_ensure` + copy for local builds.
2. Clone/checkout pinned SPC + pinned/patched phpmicro under `var/tmp/static-php-cli/`.
3. `spc download` with exact PHP + `--custom-local=php-micro:…`; verify PHP archive hash.
4. `spc build` with pin extensions, `--build-cli --build-micro`, optional
   `--with-micro-fake-cli`.
5. **SPC smoke skip:** `--no-smoke-test=micro` (PHP 8.5 bare micro segfault on Linux).
   Hatfield fused version/list/topology proofs remain hard.
6. `spc micro:combine <handoff-phar> --with-micro=… --output=<artifact>`.
7. Native smoke via relative `./hatfield` symlink in isolated CWD (with expected
   version/commit when supplied) + native topology.

Host tools (`re2c`, `flex`, `gperf`, compiler, `make`, `cmake`) and CI install via
`.github/actions/static-prerequisites`: [`tools/static/README.md`](../tools/static/README.md).
Pass `GITHUB_TOKEN` into SPC download/build for GitHub API rate limits.

### Linux / macOS caveats

- Linux: fully static musl-style (no runtime `dl()` / external `.so`).
- macOS: links system libraries (macOS 12+); runners clear xattrs where needed.

## Native relaunch

| Condition | `command()` |
|---|---|
| Ordinary PHAR / source | `[PHP_BINARY, path]` |
| Empty `PHP_BINARY` (fused micro) | `[path]` |
| Artifact path equals `PHP_BINARY` | `[path]` |

Controller/Messenger children relaunch the same native binary. Writable state stays
under runtime CWD `.hatfield/`.

## Topology / no-leak validation

1. Boot controller → wait `runtime.ready` (consumers start after ready).
2. Drain stderr while polling; portable `ps` (Linux `sid`/`args`, macOS `sess`/`command`).
3. Assert messenger consumers use the one-element native path.
4. Snapshot owned PIDs+cmdlines while controller is alive; stop controller; assert gone
   or PID-reuse with different cmdline. Never rely on `pgrep -P <dead-controller>`.

## Release matrix ownership

Only `.github/workflows/release.yml` (tag `v*`) runs the four-target matrix. Static jobs
download the PHAR-job artifact, prove it is unchanged across `build-static`, and hard-fail
topology before publish.

## Testing

```bash
castor test --filter=FusedNativeExecutableLocatorTest
castor test --filter=CanonicalPharHandoffTest
HATFIELD_NATIVE_BINARY_PATH=var/tmp/dist/hatfield.linux-amd64 \
  castor test --filter=NativeProcessTopologyTest
```

## Troubleshooting

### Host tools missing / unsupported target / zero descendants

See host preflight errors, host-only targets, and empty-`PHP_BINARY` relaunch notes in
[`tools/static/README.md`](../tools/static/README.md). Controller error
`First element must contain a non-empty program name` means locator still returned
`['', path]`.
