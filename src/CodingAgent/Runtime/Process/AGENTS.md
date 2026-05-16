# Process Runtime Transport

JsonlProcessAgentSessionClient runs the agent in a subprocess and communicates over
JSONL (stdin/stdout).  This isolates the TUI from the agent runtime, allows the
two to be built/distributed separately, and is a stepping stone toward remote
agent sessions.

## Current limitation: source-checkout assumption

The client uses `dirname(__DIR__, 4)` to discover the project root and hard-codes
`$projectDir/bin/console` as the headless binary.  This only works in a source
checkout with the conventional Symfony layout:

```
<project>/bin/console
<project>/src/CodingAgent/Runtime/Process/JsonlProcessAgentSessionClient.php
```

It will **not** work inside:

| Build type | What breaks |
|---|---|
| PHAR (`app.phar`) | No filesystem tree — `bin/console` does not exist |
| Single-file binary | Same — no `bin/console` |
| Docker image | Conventional layout may be restructured |
| Installed `vendor/` usage | The binary may be a shim in `$PATH`, not part of the project |

## Future: `SelfExecutableLocator` / `BinaryLocator`

The resolution should move behind an interface so the client never hard-codes
the binary path:

```php
interface BinaryLocator
{
    /** Return the absolute path to the headless-agent executable. */
    public function locate(): string;
}
```

### Resolution strategy (ordered by priority)

1. **Explicit configuration** — a Hatfield setting such as
   `agent.runtime.binary_path`.  If set, use it directly.  This covers Docker,
   custom install paths, and non-PHAR redistributables.

2. **PHAR introspection** — inside a PHAR, `Phar::running()` returns the PHAR
   path.  The agent binary *is* the same PHAR entry point (e.g.,
   `php app.phar agent --headless`).  A `PharBinaryLocator` checks
   `Phar::running()`, and when non-empty returns `PHP_BINARY . ' ' . Phar::running()`.

3. **Filesystem heuristic** — walk up from `__DIR__` (or a known anchor) looking
   for a marker file (e.g., `.agent-core-root`, `composer.json`) and resolve
   `bin/console` relative to it.  This is the current behaviour, kept as a
   fallback.

4. **PATH lookup** — `which agent-core-headless` or similar, for globally
   installed tools.

### Integration points

| Class | Change |
|---|---|
| `JsonlProcessAgentSessionClient` | Accept `BinaryLocator $locator` in the constructor; call `$locator->locate()` instead of building `$projectDir.'/bin/console'` |
| `AgentProcessSupervisor` | Same — currently takes `$consolePath` directly; should accept `BinaryLocator` instead |
| `config/services.yaml` | Wire the appropriate `BinaryLocator` implementation (auto-detected or config-driven) |
| `AgentCommand` | No change (already receives the client via DI) |

### Related tasks

- [ ] Introduce `BinaryLocator` interface under `CodingAgent\Runtime\Process\`.
- [ ] Implement `SourceTreeLocator` (current behaviour), `PharBinaryLocator`,
      `ConfigBinaryLocator`, and a `ChainBinaryLocator`.
- [ ] Wire `BinaryLocator` into container services and set the default to
      a chain that prefers explicit config → PHAR → source-tree fallback.
- [ ] Update `AgentProcessSupervisor` to use `BinaryLocator`.
- [ ] Add PHAR build tooling for `agent-core` itself.
- [ ] Test both source-checkout and PHAR modes in CI.
