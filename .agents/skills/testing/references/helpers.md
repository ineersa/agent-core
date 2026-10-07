# Test helpers

- Use `TestDirectoryIsolation` in `tests/CodingAgent/Support/` for temporary trees and cleanup.
- Use `createProjectTempDir`, `createOsTempDir`, `createHatfieldTree`, and `removeDirectory`; do not clone them or call `sys_get_temp_dir()` directly.
- Clean owned temporary directories in `finally` or `tearDown`. Preserve passing TUI snapshots for inspection.
- Use `TestMessageBus` and `TestLogger` from `Ineersa\AgentCore\Tests\Support`; keep specialized fakes local.
- Reuse a minimal `AppConfig` fixture builder when the same model shape recurs.
- For database tests, use `IsolatedKernelTestCase` and `static::getContainer()` for the entity manager and services.
- Use `PerMethodIsolatedKernelTestCase` only for container mutation or artifacts requiring a fresh kernel each method.
- Let DAMA roll back methods; do not build private ORM connections, schemas, or entity managers or clean the database manually.
- Restore `putenv`, `$_ENV`, and `$_SERVER` after environment changes.
- ParaTest isolates SQLite and cache by `TEST_TOKEN`; concurrent QA pools additionally require `HATFIELD_QA_LANE`. Do not bypass the bootstrap.
- Filtered Castor test runs are sequential and use the shared test database.
- Use `VirtualTuiHarness` in `tests/Tui/Support/` for widget, input, local command, and render assertions.
- Never add production APIs, settings, or paths solely for tests.
