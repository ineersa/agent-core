# Process Runtime Transport

JsonlProcessAgentSessionClient runs the agent in a subprocess and communicates over
JSONL (stdin/stdout). This isolates the TUI from the agent runtime.

## Executable resolution

Subprocess command lines come from `RuntimeProcessConfig` → `AppExecutableLocator`:

1. **ConfigExecutableLocator** — `HATFIELD_BINARY_PATH` (tests, custom installs, static).
2. **PharExecutableLocator** — physical PHAR path (Box alias fallback included).
3. **SourceTreeExecutableLocator** — `kernel.project_dir/bin/console`.

### Command shape

| Mode | `command()` |
|---|---|
| Source checkout | `[PHP_BINARY, /path/bin/console]` |
| PHAR | `[PHP_BINARY, /path/hatfield.phar]` |
| Fused PHP-micro native | `[/path/hatfield.<target>]` |

Fused native detection (`ConfigExecutableLocator` / `PharExecutableLocator`):

1. Empty `PHP_BINARY` → native self (fused micro on static/macOS targets).
2. Else resolved artifact path equals `PHP_BINARY` (same path/inode).

Do not rewrite controller/consumer construction — they spread `executableCommand()`.

## Distribution

- Release/installer/CI: `docs/distribution.md`
- PHAR pipeline: `docs/phar-packaging.md`
- Native build/relaunch/topology: `docs/static-packaging.md`

Canonical artifacts: `hatfield.phar`, `hatfield.linux-amd64`, `hatfield.linux-arm64`,
`hatfield.darwin-amd64`, `hatfield.darwin-arm64`, `SHA256SUMS`.
