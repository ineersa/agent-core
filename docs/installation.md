---
builtin: true
description: Install the PHAR or native binary, configure a provider, upgrade, and troubleshoot installation.
---

# Installation and upgrades

Hatfield supports Linux and macOS on x86_64 and ARM64. Windows is unsupported.
No repository checkout is required. Choose either the native binary, which includes
PHP, or the PHAR, which uses your system PHP.

## Install the native binary

Run the installer with `--static`:

```bash
curl --proto '=https' --tlsv1.2 -fsSL \
	https://raw.githubusercontent.com/ineersa/agent-core/main/installer/bash-installer \
	| bash -s -- --static
```

## Install the PHAR

Before installing, provide PHP 8.5 or later with these extensions:

- `pdo_sqlite`, `mbstring`, `xml`, `intl`, `curl`, `openssl`
- `pcntl`, `posix`, `tokenizer`, `ctype`, `filter`, `iconv`, `phar`

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
hatfield providers:setup
```

Enable a provider and configure its credentials. Then start a session in your project:

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

## Troubleshoot installation

| Symptom | Action |
|---|---|
| `hatfield: command not found` | Check the install directory and your shell's `PATH`. |
| Unsupported OS or architecture | Use Linux or macOS on x86_64 or ARM64. |
| PHP missing or too old | Install PHP 8.5 or later, or choose `--static`. |
| Missing PHP extensions | Install the extensions listed above for the PHP executable on `PATH`, or choose `--static`. |
| Checksum mismatch | Check the download and release checksum. Do not bypass verification. |
| Candidate smoke failed | Read the reported startup error. Correct PHP requirements or select a compatible release asset. |
| Missing release asset | Check that the requested release published your platform's artifact. |
| No usable model | Run `hatfield providers:setup`, then check `/model` and your credentials. |

See [settings](settings.md) for configuration precedence and [sessions](session-storage.md)
for stored conversations and recovery limitations.
