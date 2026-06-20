<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Handler;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolNameMapper;
use Ineersa\CodingAgent\Mcp\Client\McpConnectionManagerInterface;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Config\McpConfigValidator;
use Ineersa\CodingAgent\Mcp\Config\McpEnvInterpolator;
use Ineersa\CodingAgent\Mcp\Handler\McpInitializeSessionHandler;
use Ineersa\CodingAgent\Mcp\Message\McpInitializeSessionCommand;
use Ineersa\CodingAgent\Mcp\Message\McpRefreshCatalogCommand;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis 1: Initialize with empty config emits structured info log and
 * writes an empty catalog (invalidates stale tools from previous discovery).
 *
 * Test thesis 2: Invalid MCP config (broken JSON) is non-fatal — the
 * handler logs a warning with error_class + error_message, writes an empty
 * catalog, and does not throw.  The log must never leak raw config,
 * env values, headers, or tokens.
 *
 * Test thesis 3: Refresh catalog on failure invalidates stale tools by
 * writing an empty catalog and logging a warning — does NOT silently
 * preserve the previous catalog.
 *
 * Test thesis 4: Disconnect handler delegates to connection manager.
 *
 * Test thesis 5: Cross-server duplicate Hatfield tool names are detected
 * and skipped with a warning log.
 *
 * Test thesis 6: Secret-like substrings in thrown discovery errors
 * (authorization, api_key, bearer, token) are not present in handler
 * log messages.
 *
 * Constructs the handler manually (no kernel boot) so TestLogger can
 * capture exact log records for assertion.
 */
class McpInitializeSessionHandlerTest extends TestCase
{
    private string $projectDir;
    private TestLogger $logger;
    private TestMcpCatalogStore $catalogStore;
    private McpInitializeSessionHandler $handler;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('mcp-handler-v2');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir);

        $pathResolver = new SettingsPathResolver(
            appRoot: $this->projectDir,
            homeDir: $this->projectDir,
        );

        $configLoader = new McpConfigLoader(
            $pathResolver,
            new McpConfigValidator(),
            new McpEnvInterpolator(),
            $this->projectDir,
        );

        $this->catalogStore = new TestMcpCatalogStore();

        // Use a no-op connection manager stub implementing the interface.
        // Connection/discovery is tested separately with real SDK fixtures.
        $connectionManager = self::createStub(McpConnectionManagerInterface::class);

        $this->logger = new TestLogger();
        $this->handler = new McpInitializeSessionHandler(
            $configLoader,
            $connectionManager,
            new McpToolNameMapper(),
            $this->catalogStore,
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testInitializeEmptyConfigLogsStructuredInfoAndWritesEmptyCatalog(): void
    {
        $command = new McpInitializeSessionCommand(
            runId: 'test-run-abc',
            reason: 'start_run',
            correlationId: 'corr-001',
        );

        ($this->handler)($command);

        // Verify info log
        $infoRecords = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'info' === $r['level'],
        ));
        self::assertGreaterThanOrEqual(1, $infoRecords, 'Expected at least one info log');

        // Find the session.initialize log
        $initLogs = array_values(array_filter(
            $infoRecords,
            static fn (array $r): bool => ($r['context']['mcp_event'] ?? '') === 'session.initialize',
        ));
        self::assertCount(1, $initLogs);
        self::assertSame(0, $initLogs[0]['context']['enabled_server_count']);
        self::assertSame('start_run', $initLogs[0]['context']['reason']);

        // Verify catalog was written (empty)
        self::assertTrue($this->catalogStore->wasWritten, 'Catalog should be written even for empty config');
        self::assertSame('test-run-abc', $this->catalogStore->lastRunId);
        self::assertNotNull($this->catalogStore->lastCatalog);
        self::assertCount(0, $this->catalogStore->lastCatalog->servers);
    }

    public function testInvalidConfigLogsWarningWithoutThrowing(): void
    {
        // Write broken JSON that McpConfigLoader will reject.
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', '{broken');

        $command = new McpInitializeSessionCommand(
            runId: 'test-run-bad',
            reason: 'start_run',
            correlationId: 'corr-bad',
        );

        // Must not throw — MCP is optional infrastructure.
        ($this->handler)($command);

        $warnings = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'warning' === $r['level'],
        ));
        self::assertCount(1, $warnings, 'Expected one warning for config failure');

        $warn = $warnings[0];
        self::assertStringContainsString('MCP initialize failed', $warn['message']);

        $ctx = $warn['context'];
        self::assertSame('mcp', $ctx['component']);
        self::assertSame('session.initialize', $ctx['mcp_event']);
        self::assertArrayHasKey('error_class', $ctx);
        self::assertArrayHasKey('error_message', $ctx);

        // Verify no raw config, env, or secret values leak into the log.
        $contextJson = json_encode($ctx, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('{broken', $contextJson, 'Broken JSON must not appear in log context');
        self::assertStringNotContainsString('token', strtolower($contextJson));

        // On config failure, an empty catalog must be written to invalidate
        // any previously-discovered tools.
        self::assertTrue($this->catalogStore->wasWritten);
        self::assertCount(0, $this->catalogStore->lastCatalog->servers);
    }

    public function testInitializeHandlesResumeReasonProperly(): void
    {
        $command = new McpInitializeSessionCommand(
            runId: 'test-run-resume',
            reason: 'resume',
            correlationId: 'corr-resume',
        );

        ($this->handler)($command);

        $infoRecords = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'info' === $r['level'],
        ));
        $initLogs = array_values(array_filter(
            $infoRecords,
            static fn (array $r): bool => ($r['context']['mcp_event'] ?? '') === 'session.initialize',
        ));
        self::assertCount(1, $initLogs);
        self::assertSame('resume', $initLogs[0]['context']['reason']);
    }

    public function testRefreshCatalogFailureInvalidatesCatalog(): void
    {
        // Write broken JSON to trigger refresh failure
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', '{broken');

        $message = new McpRefreshCatalogCommand(
            runId: 'test-run-cat-fail',
            correlationId: 'corr-fail',
        );

        $this->handler->onRefreshCatalog($message);

        // Should log a warning
        $warnings = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'warning' === $r['level'],
        ));
        self::assertCount(1, $warnings);
        self::assertStringContainsString('catalog invalidated', $warnings[0]['message']);

        // On refresh failure, an empty catalog MUST be written to invalidate
        // any previously-discovered tools.  Stale tools must never silently
        // survive a refresh failure.
        self::assertTrue($this->catalogStore->wasWritten, 'Catalog should be written (invalidated) on refresh failure');
        self::assertNotNull($this->catalogStore->lastCatalog);
        self::assertCount(0, $this->catalogStore->lastCatalog->servers, 'Empty catalog on refresh failure');
    }

    public function testRefreshCatalogLogsStructuredInfo(): void
    {
        $message = new McpRefreshCatalogCommand(
            runId: 'test-run-cat',
            correlationId: 'corr-cat',
        );

        $this->handler->onRefreshCatalog($message);

        $infoRecords = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'info' === $r['level'],
        ));
        $refreshLogs = array_values(array_filter(
            $infoRecords,
            static fn (array $r): bool => ($r['context']['mcp_event'] ?? '') === 'catalog.refresh',
        ));
        self::assertCount(1, $refreshLogs);
        self::assertSame('test-run-cat', $refreshLogs[0]['context']['run_id']);
        self::assertSame('corr-cat', $refreshLogs[0]['context']['correlation_id']);
    }

    /**
     * Test thesis 5: Cross-server duplicate Hatfield tool names are detected
     * and skipped with a warning.
     */
    public function testCrossServerDuplicateToolNamesAreDetectedAndSkipped(): void
    {
        // Configure two servers with sanitized names that collide.
        // Server "a.b" and "a_b" both sanitize to "a_b", so their tool
        // "tool" maps to "a_b_tool" on both servers.
        $mcpConfig = [
            'mcpServers' => [
                'a.b' => [
                    'command' => \PHP_BINARY,
                    'args' => [__DIR__.'/../Fixtures/stdio-echo-server.php'],
                    'timeoutMs' => 10000,
                    'startupTimeoutMs' => 10000,
                ],
            ],
        ];
        file_put_contents(
            $this->projectDir.'/.hatfield/mcp.json',
            json_encode($mcpConfig, \JSON_PRETTY_PRINT),
        );

        // Create a handler with a stub connection manager that returns
        // two servers with colliding tool names.
        $stubManager = self::createStub(McpConnectionManagerInterface::class);
        $stubManager->method('discover')->willReturn([
            'a.b' => [
                'status' => 'connected',
                'transport' => 'stdio',
                'tools' => [
                    ['name' => 'tool', 'description' => 'Tool from a.b', 'inputSchema' => []],
                ],
            ],
            'a_b' => [
                'status' => 'connected',
                'transport' => 'stdio',
                'tools' => [
                    // Same MCP tool name "tool" on a different server whose
                    // sanitized name also becomes "a_b" — both map to "a_b_tool".
                    ['name' => 'tool', 'description' => 'Tool from a_b', 'inputSchema' => []],
                ],
            ],
        ]);

        $handler = new McpInitializeSessionHandler(
            new McpConfigLoader(
                new SettingsPathResolver($this->projectDir, $this->projectDir),
                new McpConfigValidator(),
                new McpEnvInterpolator(),
                $this->projectDir,
            ),
            $stubManager,
            new McpToolNameMapper(),
            $this->catalogStore,
            $this->logger,
        );

        $command = new McpInitializeSessionCommand(
            runId: 'test-run-dup',
            reason: 'start_run',
            correlationId: 'corr-dup',
        );

        ($handler)($command);

        // Both servers should appear in the catalog
        self::assertTrue($this->catalogStore->wasWritten);
        self::assertNotNull($this->catalogStore->lastCatalog);
        self::assertCount(2, $this->catalogStore->lastCatalog->servers);

        // But only ONE of the two servers should have tools — the second
        // one's tool was skipped due to name collision.
        $totalTools = 0;
        foreach ($this->catalogStore->lastCatalog->servers as $entry) {
            $totalTools += \count($entry->tools);
        }
        self::assertSame(1, $totalTools, 'Exactly one tool should survive cross-server duplicate detection');

        // A warning for the duplicate should have been logged
        $warnings = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'warning' === $r['level']
                && ($r['context']['mcp_event'] ?? '') === 'tool.duplicate',
        ));
        self::assertCount(1, $warnings, 'Expected one duplicate-tool warning');
    }

    public function testHandlerErrorLogRedactsSecretsInExceptionMessage(): void
    {
        // Use a stub manager that throws an exception with a bearer token
        $stubManager = self::createStub(McpConnectionManagerInterface::class);
        $stubManager->method('discover')->willThrowException(
            new \RuntimeException('HTTP 401: Authorization: Bearer top-secret-token-abc123 not valid'),
        );

        // Configure a valid server so discovery is attempted
        $mcpConfig = [
            'mcpServers' => [
                'secret-server' => [
                    'command' => \PHP_BINARY,
                    'args' => [__DIR__.'/../Fixtures/stdio-echo-server.php'],
                    'timeoutMs' => 10000,
                    'startupTimeoutMs' => 10000,
                ],
            ],
        ];
        file_put_contents(
            $this->projectDir.'/.hatfield/mcp.json',
            json_encode($mcpConfig, \JSON_PRETTY_PRINT),
        );

        $handler = new McpInitializeSessionHandler(
            new McpConfigLoader(
                new SettingsPathResolver($this->projectDir, $this->projectDir),
                new McpConfigValidator(),
                new McpEnvInterpolator(),
                $this->projectDir,
            ),
            $stubManager,
            new McpToolNameMapper(),
            $this->catalogStore,
            $this->logger,
        );

        $command = new McpInitializeSessionCommand(
            runId: 'test-run-secret',
            reason: 'start_run',
            correlationId: 'corr-secret',
        );

        ($handler)($command);

        // Catalog should be empty (config failure after exception)
        self::assertTrue($this->catalogStore->wasWritten);
        self::assertCount(0, $this->catalogStore->lastCatalog->servers);

        // The warning log must NOT contain the raw bearer token
        $warnings = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'warning' === $r['level'],
        ));

        // Find the initialize-failed warning
        $initWarnings = array_values(array_filter(
            $warnings,
            static fn (array $r): bool => str_contains($r['message'], 'MCP initialize failed'),
        ));

        if ([] !== $initWarnings) {
            $logJson = json_encode($initWarnings, \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(
                'top-secret-token-abc123',
                $logJson,
                'Raw bearer token must not appear in log context',
            );
            self::assertStringNotContainsString(
                'Authorization: Bearer top-secret',
                $logJson,
                'Raw authorization header must not appear in log',
            );
        }
    }

    /**
     * Test thesis 7: Partial catalog is written after each server's
     * discovery result is known, so successful servers become visible
     * before slow/failing servers finish.  This prevents a single slow
     * STDIO server from blocking tool visibility for fast HTTP servers.
     */
    public function testPartialCatalogWrittenDuringDiscovery(): void
    {
        $mcpConfig = [
            'mcpServers' => [
                'fast-http' => [
                    'url' => 'http://127.0.0.1:19123/mcp',
                    'timeoutMs' => 5000,
                ],
                'slow-stdio' => [
                    'command' => \PHP_BINARY,
                    'args' => [__DIR__.'/../Fixtures/stdio-echo-server.php'],
                    'timeoutMs' => 10000,
                    'startupTimeoutMs' => 10000,
                ],
            ],
        ];
        file_put_contents(
            $this->projectDir.'/.hatfield/mcp.json',
            json_encode($mcpConfig, \JSON_PRETTY_PRINT),
        );

        // Create a mock connection manager that simulates sequential
        // discovery with the callback invoked after each server.
        $stubManager = self::createStub(McpConnectionManagerInterface::class);
        $stubManager->method('discover')
            ->willReturnCallback(static function (string $runId, ?callable $onServerDiscovered = null) {
                // First server succeeds — callback fires with 1 server
                $results = [
                    'fast-http' => [
                        'status' => 'connected',
                        'transport' => 'http',
                        'tools' => [
                            ['name' => 'search', 'description' => 'Search the web', 'inputSchema' => []],
                        ],
                    ],
                ];
                if (null !== $onServerDiscovered) {
                    $onServerDiscovered($results);
                }

                // Second server fails AFTER the first callback already
                // published the partial catalog.
                $results['slow-stdio'] = [
                    'status' => 'failed',
                    'transport' => 'stdio',
                    'tools' => [],
                    'errorMessage' => 'Request timed out',
                ];
                if (null !== $onServerDiscovered) {
                    $onServerDiscovered($results);
                }

                return $results;
            });

        $handler = new McpInitializeSessionHandler(
            new McpConfigLoader(
                new SettingsPathResolver($this->projectDir, $this->projectDir),
                new McpConfigValidator(),
                new McpEnvInterpolator(),
                $this->projectDir,
            ),
            $stubManager,
            new McpToolNameMapper(),
            $this->catalogStore,
            $this->logger,
        );

        $command = new McpInitializeSessionCommand(
            runId: 'test-run-partial',
            reason: 'start_run',
            correlationId: 'corr-partial',
        );
        ($handler)($command);

        // The catalog was written in order: partial(1 server) → partial(2 servers) → final(2 servers).
        self::assertCount(3, $this->catalogStore->writeLog, 'Expected 3 writes: 2 partial + 1 final');

        // First write: only fast-http is present, slow-stdio hasn't been
        // discovered yet. This is the partial catalog that would make
        // HTTP tools visible before STDIO discovery completes.
        $firstWrite = $this->catalogStore->writeLog[0];
        self::assertSame('test-run-partial', $firstWrite['runId']);
        $firstServers = $firstWrite['catalog']->servers;
        self::assertCount(1, $firstServers, 'First partial write: only fast-http before slow-stdio result');
        self::assertArrayHasKey('fast-http', $firstServers);
        self::assertSame('connected', $firstServers['fast-http']->status->value);
        self::assertCount(1, $firstServers['fast-http']->tools);
        self::assertArrayNotHasKey('slow-stdio', $firstServers,
            'Slow server must NOT appear in first partial catalog');

        // Second write: both servers present (second server failed)
        $secondWrite = $this->catalogStore->writeLog[1];
        $secondServers = $secondWrite['catalog']->servers;
        self::assertCount(2, $secondServers, 'Second write: both servers present');
        self::assertArrayHasKey('fast-http', $secondServers);
        self::assertArrayHasKey('slow-stdio', $secondServers);
        self::assertSame('failed', $secondServers['slow-stdio']->status->value);

        // Final write matches the final discovery results
        $finalWrite = $this->catalogStore->writeLog[2];
        self::assertSame('test-run-partial', $finalWrite['runId']);
        self::assertCount(2, $finalWrite['catalog']->servers);

        // Partial-write debug logs should be present
        $partialLogs = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'debug' === $r['level']
                && ($r['context']['mcp_event'] ?? '') === 'catalog.partial_written',
        ));
        self::assertCount(2, $partialLogs, 'Expected 2 partial-write debug logs');
    }

    /**
     * Test thesis 8: Refresh handler also publishes partial catalogs
     * incrementally during rediscovery.
     */
    public function testRefreshCatalogPublishesPartialCatalogs(): void
    {
        $mcpConfig = [
            'mcpServers' => [
                'server-a' => [
                    'url' => 'http://127.0.0.1:19123/mcp',
                    'timeoutMs' => 5000,
                ],
            ],
        ];
        file_put_contents(
            $this->projectDir.'/.hatfield/mcp.json',
            json_encode($mcpConfig, \JSON_PRETTY_PRINT),
        );

        $stubManager = self::createStub(McpConnectionManagerInterface::class);
        $stubManager->method('discover')
            ->willReturnCallback(static function (string $runId, ?callable $onServerDiscovered = null) {
                $results = [
                    'server-a' => [
                        'status' => 'connected',
                        'transport' => 'http',
                        'tools' => [
                            ['name' => 'tool', 'description' => 'A tool', 'inputSchema' => []],
                        ],
                    ],
                ];
                if (null !== $onServerDiscovered) {
                    $onServerDiscovered($results);
                }

                return $results;
            });

        $handler = new McpInitializeSessionHandler(
            new McpConfigLoader(
                new SettingsPathResolver($this->projectDir, $this->projectDir),
                new McpConfigValidator(),
                new McpEnvInterpolator(),
                $this->projectDir,
            ),
            $stubManager,
            new McpToolNameMapper(),
            $this->catalogStore,
            $this->logger,
        );

        $message = new McpRefreshCatalogCommand(
            runId: 'test-run-refresh-partial',
            correlationId: 'corr-refresh-partial',
        );
        $this->handler = $handler;
        $handler->onRefreshCatalog($message);

        // Partial write + final write = 2 writes
        self::assertCount(2, $this->catalogStore->writeLog, 'Expected 2 writes: 1 partial + 1 final');

        $partialLogs = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'debug' === $r['level']
                && ($r['context']['mcp_event'] ?? '') === 'catalog.partial_written',
        ));
        self::assertCount(1, $partialLogs);
    }
}

/**
 * Test double for McpToolCatalogStoreInterface that records writes.
 */
final class TestMcpCatalogStore implements McpToolCatalogStoreInterface
{
    public bool $wasWritten = false;
    public string $lastRunId = '';
    public ?McpToolCatalogDTO $lastCatalog = null;
    public array $reads = [];

    /**
     * All writes in order, each entry: ['runId' => string, 'catalog' => McpToolCatalogDTO].
     *
     * @var list<array{runId: string, catalog: McpToolCatalogDTO}>
     */
    public array $writeLog = [];

    public function write(string $runId, McpToolCatalogDTO $catalog): void
    {
        $this->wasWritten = true;
        $this->lastRunId = $runId;
        $this->lastCatalog = $catalog;
        $this->reads[$runId] = $catalog;
        $this->writeLog[] = ['runId' => $runId, 'catalog' => $catalog];
    }

    public function read(string $runId): ?McpToolCatalogDTO
    {
        return $this->reads[$runId] ?? null;
    }
}
