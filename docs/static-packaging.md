# Static / native packaging

Fused PHP-micro binaries for Linux and macOS. Built from the canonical PHAR plus a
pinned static-php-cli (SPC) micro SFX.

Related: [Distribution / release](distribution.md) · [PHAR packaging](phar-packaging.md) ·
toolchain detail: [`tools/static/README.md`](../tools/static/README.md)

## Artifacts and targets

| Artifact | Runner / host |
|---|---|
| `hatfield.linux-amd64` | `ubuntu-latest` |
| `hatfield.linux-arm64` | `ubuntu-24.04-arm` |
| `hatfield.darwin-amd64` | `macos-15-intel` |
| `hatfield.darwin-arm64` | `macos-15` |

No Windows native binary (use the PHAR). Local builds only support the **host**
target; CI owns the four-target matrix.

## Pinned toolchain

`tools/static/pin.json`:

- Immutable static-php-cli commit `59584de4aa9d8067e4ce30d2ff990e7b9e14db43`
- PHP 8.5, SAPIs `cli` + `micro`, `micro_fake_cli=true`
- Extension list synchronized with Composer / installer / PHAR guards

Do not float on SPC branches/tags from build tasks. Update only by changing the pin
and validating a full host static build.

## Build flow

```bash
castor distribution:build-static                   # host target only
castor distribution:build-static --target=linux-amd64
castor distribution:verify                         # requires host static + topology
```

1. Build/ensure canonical PHAR ([phar-packaging.md](phar-packaging.md)).
2. Clone/checkout pinned SPC under `var/tmp/static-php-cli/<commit>/`.
3. `spc download` + `spc build` with the pin’s extension set, `--build-cli --build-micro`,
   and `--with-micro-fake-cli` when pin says so.
4. **Deliberate SPC smoke skip:** `--no-smoke-test=micro`  
   Pinned PHP 8.5 bare micro smoke (including marker-payload path) segfaults on Linux
   (`micro_ext_test` exit 139). Skip **only** upstream bare micro + Zend micro smoke.
   Retain SPC CLI/ext smokes and **all** Hatfield fused hard proofs: version/list,
   Composer-platform, bundled resources, native process topology. Remove this
   workaround once upstream stabilizes.
5. `spc micro:combine <phar> --with-micro=buildroot/bin/micro.sfx --output=<artifact>`.
6. `chmod +x`, size/smoke, native topology after controller `runtime.ready`.

Host prerequisites (`re2c`, `flex`, `gperf`, compiler, `make`, `cmake`, …) and CI
install via `.github/actions/static-prerequisites` are documented in
[`tools/static/README.md`](../tools/static/README.md). Local
`castor distribution:build-static` hard-fails when those tools are missing.

### Linux / macOS caveats

- Linux: fully static musl-style binaries (no runtime `dl()` / external `.so`).
- macOS: links dynamically against system libraries (supported macOS 12+); release
  runners clear extended attributes where needed.
- Pass `GITHUB_TOKEN` into SPC download/build steps so GitHub API rate limits do not
  fail dependency fetches (CI does this).

## Native relaunch

`ConfigExecutableLocator` / `PharExecutableLocator` (and test mirror
`AgentTestExecutable`) return:

| Condition | `command()` |
|---|---|
| Ordinary PHAR / source | `[PHP_BINARY, path]` |
| Empty `PHP_BINARY` (fused micro on static/macOS targets) | `[path]` |
| Resolved artifact path equals `PHP_BINARY` (same path/inode) | `[path]` |

Empty `PHP_BINARY` is treated as native self because fused PHP-micro leaves it empty
while the artifact is self-executing. Same-path detection still applies when the host
points `PHP_BINARY` at the fused binary. Controller and Messenger children therefore
relaunch the same native binary, not system PHP or a source checkout.
`sourceConsoleCommand()` stays source-only for replay/test DI.

Writable state remains under runtime CWD `.hatfield/` (same as PHAR).

## Topology / no-leak validation

`distribution:verify` (and CI static jobs) hard-require the host native artifact.
Topology never soft-passes on inconclusive process listing:

1. Boot controller → wait for `runtime.ready`.
2. Drain stderr while polling; collect descendant cmdlines (portable `ps`:
   Linux `sid`/`args`, macOS `sess`/`command`).
3. Assert messenger consumers relaunch via the **one-element** native path.
4. Snapshot owned PIDs + cmdlines **while controller is alive**.
5. Stop controller only; assert every captured PID is gone or reused with a different
   cmdline.

Do not rely on `pgrep -P <dead-controller>` — reparented orphans would false-pass.

## CI ownership

`.github/workflows/distribution.yml` and `release.yml` static matrix jobs:

- Install host toolchain via `.github/actions/static-prerequisites`
- Cache SPC pin checkout + downloads keyed by pin + `composer.lock`
- Call Castor only for build/verify; `upload-artifact` with `if-no-files-found: error`
- Run hard native topology on each native runner

## Testing

```bash
castor test --filter=FusedNativeExecutableLocatorTest

# Real PHPUnit skip without artifact; CI / distribution:verify always supply it
HATFIELD_NATIVE_BINARY_PATH=var/tmp/dist/hatfield.linux-amd64 \
  castor test --filter=NativeProcessTopologyTest
```

No-leak proof uses pre-shutdown owned PID snapshots (not post-exit `pgrep -P`).
Empty-`PHP_BINARY` relaunch is proven by CI native topology on fused artifacts.

## Troubleshooting

### Host tools missing

```text
Host static build requires build tools that are missing: re2c, flex, gperf...
```

Install via host package manager; see [`tools/static/README.md`](../tools/static/README.md).
SPC `doctor --auto-fix` fails under `no-new-privileges`.

### Unsupported local target

Local static builds must match the host. Use CI for other arches.

### Topology zero descendants after `runtime.ready`

Check empty-`PHP_BINARY` relaunch (must be one-element argv), portable `ps` columns,
and that messenger consumers actually started. Controller exit with
`First element must contain a non-empty program name` means the locator still returned
`['', path]`.
