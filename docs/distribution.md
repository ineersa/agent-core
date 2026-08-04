# Distribution and release

Release-level packaging: five binaries + checksums, installer, and tag publish.

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
- `hatfield --version` exposes that identity on PHAR and native artifacts.
- Release tags set version to `github.ref_name` and commit to the tag SHA.
- When Castor receives `--release-version` / `--commit`, smokes fail closed unless
  `--version` output contains those exact values.

## Local orchestration

```bash
castor distribution:build
castor distribution:build-static                   # uses existing dist PHAR if present
castor distribution:checksums
castor distribution:verify
castor distribution:verify --skip-topology --allow-missing-native
```

Convenience wrapper (worktree lock; Castor only):

```bash
scripts/build-distribution.sh --version=1.2.3 --commit=$(git rev-parse HEAD)
scripts/build-distribution.sh --release-version 1.2.3 --commit abcdef1
scripts/build-distribution.sh --static --target=linux-amd64
```

Default/`--phar-only` runs `distribution:verify --skip-topology --allow-missing-native`.
`--static` keeps hard verify (native + topology). Wrapper acquires
`var/tmp/distribution-build.lock` for the whole sequence; concurrent runs fail closed.

| Variable | Meaning |
|---|---|
| `HATFIELD_DIST_DIR` | Dist directory (default `var/tmp/dist`) |
| `HATFIELD_BUILD_VERSION` | Release version embedded into artifacts |
| `HATFIELD_BUILD_COMMIT` | Exact commit embedded into artifacts |
| `HATFIELD_BINARY_PATH` | Runtime/test override for subprocess executable |
| `HATFIELD_NATIVE_BINARY_PATH` | Test input for native topology tests |

## Canonical PHAR handoff

1. PHAR job builds `var/tmp/dist/hatfield.phar` and uploads it.
2. Each static job downloads that file into `var/tmp/dist/hatfield.phar`.
3. `distribution:build-static` **smokes the existing non-empty dist PHAR and combines it**;
   it must not rebuild/overwrite a handoff PHAR. Local standalone static builds call
   `phar_ensure` + copy only when the dist PHAR is absent.
4. Release workflow re-hashes the dist PHAR before/after static build to prove identity.

## Installer

```bash
bash installer/bash-installer --version=v1.2.3 --install-dir=~/.local/bin
bash installer/bash-installer --static --version=latest
```

Behavior:

- Downloads asset + `SHA256SUMS`; exact-filename checksum; fail-closed
- Candidate `--version` smoke, then same-directory install-temp smoke, then atomic `mv`
- **No post-`mv` commands** — install exit status is `mv` status only; failures never replace previous install
- Empty `--version=` / `--install-dir=` rejected; traps clean download + install temps
- Both pre-replace `--version` smokes run with disposable absolute `HATFIELD_CACHE_DIR`,
  `HATFIELD_LOG_DIR`, `HOME`, and CWD under the installer's trap-cleaned temp tree so
  temporary artifact filenames never seed project or persistent XDG Symfony caches

### Installed Symfony container cache

Installed PHAR/native compiled containers use a global XDG/HOME cache scoped by
environment + artifact content hash + canonical install path (see
[phar-packaging.md](phar-packaging.md) and [static-packaging.md](static-packaging.md)).
Project settings/sessions remain under the project `.hatfield/`. Safe clear:

```bash
rm -rf "${XDG_CACHE_HOME:-$HOME/.cache}/hatfield"
```

## CI and release

**PRs and ordinary pushes do not build PHAR/native binaries.** Project gate only
(`castor check`). No `.github/workflows/distribution.yml`.

### Tag `v*` only — `.github/workflows/release.yml`

Sole Actions path that builds distribution artifacts:

1. Validate tag SHA == checkout commit.
2. Build canonical PHAR (`distribution:build`) with embedded tag/commit.
3. Four native runners download that exact PHAR, build static, hard topology verify.
4. Aggregate five artifacts + one `SHA256SUMS`; publish all six files fail-closed.
5. After publish succeeds, matrix-split five Composer package directories into one-way
   mirror repositories (same `vX.Y.Z` tag + default `main` branch update).

Core static pins (exact PHP 8.5.8 + source SHA + phpmicro commit) live in
`tools/static/pin.json` — see [static-packaging.md](static-packaging.md).

## Extension package mirrors (release split)

`agent-core` remains the **single source of truth** for public extension contracts
and project-level extensions. Split GitHub repositories are **read-only
distribution mirrors** — normal development and PRs stay in this monorepo. Do not
open feature PRs against the mirrors; they are overwritten on each Hatfield `v*`
release.

| Monorepo prefix | Composer package | Mirror repository |
|---|---|---|
| `.hatfield/extensions/extension-api` | `ineersa/hatfield-extension-api` | `ineersa/hatfield-extension-api` |
| `.hatfield/extensions/task-workflow` | `ineersa/hatfield-ext-task-workflow` | `ineersa/hatfield-ext-task-workflow` |
| `.hatfield/extensions/castor-llm-mode` | `ineersa/hatfield-ext-castor-llm-mode` | `ineersa/hatfield-ext-castor-llm-mode` |
| `.hatfield/extensions/file-rewind` | `ineersa/hatfield-ext-file-rewind` | `ineersa/hatfield-ext-file-rewind` |
| `.hatfield/extensions/observational-memory` | `ineersa/hatfield-ext-observational-memory` | `ineersa/hatfield-ext-observational-memory` |

Shared versioning: the Hatfield release tag `vX.Y.Z` is published as the same
`vX.Y.Z` tag on every mirror. Packages do not version independently.

### Provisioning (outside repository code)

Before the first release that runs package-split:

1. Create the five empty GitHub repositories under the `ineersa` org (default
   branch `main`).
2. Create a least-privilege personal access token / fine-grained token with
   **contents:write** (push branch + tags) on those five repositories only.
3. Store it as repository secret **`MONOREPO_SPLIT_TOKEN`** on `agent-core`.

Missing secret, missing destination repository, or push failure **fails** the
`package-split` job (no silent skip). The splitter is
`danharrin/monorepo-split-github-action` pinned to commit
`14e42e2437f674b8987c1f50ca3689116aea1893` (v2.4.5).

### Local monorepo development

This repository keeps path repositories:

- Root `composer.json` path-requires `ineersa/hatfield-extension-api` from
  `.hatfield/extensions/extension-api`.
- Project extensions install through `.hatfield/extensions/composer.json` path
  repositories (including `extension-api` + the four extensions).

### Installing packages in another Hatfield project

After a release tag exists on the mirrors (and optionally Packagist):

```json
{
  "require": {
    "ineersa/hatfield-extension-api": "^X.Y",
    "ineersa/hatfield-ext-task-workflow": "^X.Y"
  },
  "repositories": [
    { "type": "vcs", "url": "https://github.com/ineersa/hatfield-extension-api" },
    { "type": "vcs", "url": "https://github.com/ineersa/hatfield-ext-task-workflow" }
  ]
}
```

Install under the consuming project's `.hatfield/extensions/` Composer root (or
equivalent) and enable the extension class in Hatfield settings. Prefer released
tags; do not treat mirror `main` as a development branch for feature work.

## Release checklist

1. Green `castor check` on the exact commit to tag.
2. Tag `vX.Y.Z` at that commit.
3. Confirm release built PHAR + four static jobs and published six files.
4. Confirm `package-split` updated all five mirrors with the same `vX.Y.Z` tag.
5. Smoke installer PHAR and `--static` against the published tag.
6. Confirm `--version` shows release version + commit on both artifact kinds.

## Testing

```bash
castor test --filter=ApplicationBuildIdentityTest
castor test --filter=BashInstallerTest
castor test --filter=BuildDistributionScriptTest
castor test --filter=CanonicalPharHandoffTest
```
