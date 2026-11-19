---
builtin: true
description: Install the PHAR or native binary, configure a provider, upgrade, and troubleshoot installation.
---

# Installation and upgrades

Native binaries support Linux on x86_64/ARM64 and Apple Silicon macOS (ARM64).
Intel macOS is supported through the PHAR with system PHP, not a native binary.
Windows is unsupported.
No repository checkout is required. Choose either the native binary, which includes
PHP, or the PHAR, which uses your system PHP.

## Install the native binary

On Linux or Apple Silicon macOS, run the installer with `--static`.
For Intel macOS, use [the PHAR](#install-the-phar) instead:

```bash
curl --proto '=https' --tlsv1.2 -fsSL \
	https://raw.githubusercontent.com/ineersa/agent-core/main/installer/bash-installer \
	| bash -s -- --static
```

## Install the PHAR

Before installing, provide PHP 8.5 or later with these extensions:

- `pdo_sqlite`, `mbstring`, `xml`, `intl`, `curl`, `openssl`
- `pcntl`, `posix`, `tokenizer`, `ctype`, `filter`, `iconv`, `phar`, `zstd`

The PHAR still needs a POSIX-capable host. Downloading it does not make stock
Windows PHP a supported runtime.

The installer selects PHAR when `--static` is absent:

```bash
curl --proto '=https' --tlsv1.2 -fsSL \
	https://raw.githubusercontent.com/ineersa/agent-core/main/installer/bash-installer \
	| bash -s --
```

## Check the installation

Both formats install the `hatfield` command into `~/.local/bin` by default.
If that directory is absent from your shell's `PATH`, add it to your shell startup
configuration. For the current shell:

```bash
export PATH="$HOME/.local/bin:$PATH"
hatfield --version
```

The version command prints the installed release identity. The installer verifies
the asset against the release's `SHA256SUMS` and tests the candidate with `--version`
before replacing an existing installation. A failed verification must not replace
the previous executable.

## Configure a provider

Provider definitions ship disabled. Open the setup screen explicitly:

```bash
hatfield setup
```

Use the Providers tab to enable a provider and configure its credentials. Settings and Extensions are available in the same app. It edits user settings by default; run `hatfield setup --project` from a project to edit that project instead. Then start a session in your project:

```bash
cd /path/to/your/project
hatfield agent
```

Use `/model` to choose an enabled model. See [terminal usage](terminal-usage.md)
for the editor and session commands. For custom or local endpoints, see
[model settings](settings-models.md). Keep secrets in user settings or environment
variables, not committed project settings.

## Upgrade or pin a release

Run the same installer command again to upgrade to the latest release. Keep
`--static` if you use the native format.

To select a specific release, append `--version=vX.Y.Z` after `bash -s --`.
For example, replace `vX.Y.Z` below with a published release tag:

```bash
curl --proto '=https' --tlsv1.2 -fsSL \
	https://raw.githubusercontent.com/ineersa/agent-core/main/installer/bash-installer \
	| bash -s -- --version=vX.Y.Z
```

For a pinned native release, use `bash -s -- --static --version=vX.Y.Z` instead.
To change the destination, append `--install-dir="$HOME/bin"` and put that directory
on `PATH`. Quote `$HOME`; a bare `~` inside `--install-dir=...` does not expand.

Provider catalog updates are separate from executable upgrades. See
[the provider catalog](ai-catalog.md) for `hatfield providers:update`.

### Extension updates at interactive startup

Extensions are separate Composer installations. Before an interactive `hatfield`
or `hatfield agent` session loads extensions, Hatfield checks installed extension
packages in both `~/.hatfield/extensions` and the selected project's
`.hatfield/extensions`. Identical physical roots (including symlink aliases) are
checked once. Project-local packages continue to take precedence over user-wide
packages; updating a user-wide package does not override a project-local copy.

Composer must be on `PATH` when installed extensions need checking. Hatfield
resolves updates only for installed packages marked `hatfield-extension` or
declaring `extra.hatfield.extension`, allowing their dependencies to update within
the installation's existing Composer constraints. This includes optional
extensions not currently enabled. No application-level Composer installation or
settings are changed, and constraints are never broadened automatically.

When changes are available, the terminal shows each package's installed and
proposed versions/references, including dependency additions/removals, the scope
and physical path, the proposed lockfile hash, and the exact install commands and
environment. **Yes** applies those lockfiles and installs their resolved versions;
it does not resolve another, potentially different set after consent. **No** (the
default) leaves package files and lockfiles unchanged and starts with the installed
versions. Up-to-date installations do not ask for consent. Existing development
dependency installation mode is preserved.

Resolution uses disposable manifests/lockfiles outside the installations, with
the original working directories so relative repositories keep working. Composer
scripts and plugins are disabled during both checking and installation. Network
access and Composer cache writes may occur during checks, but installed packages,
installation manifests and lockfiles are not changed before consent.

Local Composer credentials in an installation's `auth.json` remain available
during resolution through a private temporary copy (`0700` directory, `0600`
file). The copy is removed on normal completion or failure, the original stays
unchanged, and credentials are not included in the preview.

The installation must have a lockfile and effective Composer `config.lock=true`;
otherwise an install could resolve unapproved versions. Escaping internal
manifest/lock/vendor symlinks are also refused, rather than updating another
application through them.

Any check/resolution or approved-update failure displays the error and **stops
startup**. If an installation changed since the preview, restart and review a new
plan. A failed install can be partial: inspect and repair the named Composer root
before retrying; Hatfield does not claim an automatic rollback or silently start
with mixed code. Another Hatfield startup already checking the same installation
also causes a reported failure instead of concurrent updates. This coordination
uses a shared per-user lock directory, including across different source checkouts
and PHAR installations.

The gate runs before extension classes, the TUI session, controllers and consumers
start. Headless/controller commands, workers, child runs and `--no-interaction`
invocations never check, prompt or update. Same-process `/reload` does not check
again because PHP cannot replace already-declared classes; exit and launch a new
Hatfield process to check again. `extensions:setup` retains its install/configure
purpose and does not implicitly upgrade installed packages.

## Troubleshoot installation

| Symptom | Action |
|---|---|
| `hatfield: command not found` | Check the install directory and your shell's `PATH`. |
| Unsupported OS or architecture | Native binaries require Linux x86_64/ARM64 or Apple Silicon macOS. Intel macOS requires the PHAR. |
| Intel macOS native binaries not supported | Omit `--static` and install PHP 8.5 or later with the required extensions. |
| PHP missing or too old | Install PHP 8.5 or later, or choose `--static` on a supported native platform. |
| Missing PHP extensions | Install the extensions listed above for the PHP executable on `PATH`, or choose `--static` on a supported native platform. |
| Checksum mismatch | Check the download and release checksum. Do not bypass verification. |
| Candidate smoke failed | Read the reported startup error. Correct PHP requirements or select a compatible release asset. |
| Missing release asset | Check that the requested release published your platform's artifact. |
| No usable model | Run `hatfield providers:setup`, then check `/model` and your credentials. |

See [settings](settings.md) for configuration precedence and [sessions](session-storage.md)
for stored conversations and recovery limitations.
