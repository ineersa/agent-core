# Process runtime transport

`JsonlProcessAgentSessionClient` runs the agent in a subprocess and speaks JSONL on stdin/stdout, isolating the TUI from the agent runtime.

## Executable resolution

Subprocess argv comes from `RuntimeProcessConfig::executableCommand()` → chain of `AppExecutableLocator`:

1. `ConfigExecutableLocator` — `HATFIELD_BINARY_PATH` (tests, custom installs, static)
2. `PharExecutableLocator` — physical PHAR path (Box alias fallback)
3. `SourceTreeExecutableLocator` — `kernel.project_dir/bin/console`

| Mode | `command()` shape |
|---|---|
| Source checkout | `[PHP_BINARY, /path/bin/console]` |
| PHAR | `[PHP_BINARY, /path/hatfield.phar]` |
| Fused PHP-micro native | `[/path/hatfield.<target>]` alone |

Fused native detection (`ConfigExecutableLocator` / `PharExecutableLocator`): empty `PHP_BINARY`, or resolved artifact path equals `PHP_BINARY` (same path/inode). Do not rewrite controller/consumer construction — callers spread `executableCommand()`.

`RuntimeProcessConfig` requires non-empty `%app.cwd%` runtime CWD (Hatfield project dir for settings/sessions/DBs — not install dir).

## Distribution pointers

- Release/installer: `docs/distribution.md`
- PHAR: `docs/phar-packaging.md`
- Native build/relaunch: `docs/static-packaging.md`

Canonical artifacts: `hatfield.phar`, `hatfield.linux-amd64`, `hatfield.linux-arm64`, `hatfield.darwin-amd64`, `hatfield.darwin-arm64`, `SHA256SUMS`.
