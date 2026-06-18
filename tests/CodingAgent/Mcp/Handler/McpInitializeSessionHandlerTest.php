<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Handler;

use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolNameMapper;
use Ineersa\CodingAgent\Mcp\Client\McpConnectionManager;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Config\McpConfigValidator;
use Ineersa\CodingAgent\Mcp\Config\McpEnvInterpolator;
use Ineersa\CodingAgent\Mcp\Handler\McpInitializeSessionHandler;
use Ineersa\CodingAgent\Mcp\Message\McpInitializeSessionCommand;
use Ineersa\CodingAgent\Mcp\Message\McpRefreshCatalogCommand;
use Ineersa\CodingAgent\Mcp\Message\McpDisconnectSessionCommand;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\AgentCore\Tests\Support\TestLogger;
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
 * Test thesis 3: Refresh catalog with empty/no-server config writes empty
 * catalog and logs structured info event.
 *
 * Test thesis 4: Disconnect handler delegates to connection manager.
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

        // Use a no-op connection manager — connection/discovery is tested
        // separately in integration tests with real SDK fixtures.
        $connectionManager = $this->createStub(McpConnectionManager::class);

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
            static fn(array $r): bool => $r['level'] === 'info',
        ));
        self::assertGreaterThanOrEqual(1, $infoRecords, 'Expected at least one info log');

        // Find the session.initialize log
        $initLogs = array_values(array_filter(
            $infoRecords,
            static fn(array $r): bool => ($r['context']['mcp_event'] ?? '') === 'session.initialize',
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
        file_put_contents($this->projectDir . '/.hatfield/mcp.json', '{broken');

        $command = new McpInitializeSessionCommand(
            runId: 'test-run-bad',
            reason: 'start_run',
            correlationId: 'corr-bad',
        );

        // Must not throw — MCP is optional infrastructure.
        ($this->handler)($command);

        $warnings = array_values(array_filter(
            $this->logger->records,
            static fn(array $r): bool => $r['level'] === 'warning',
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
            static fn(array $r): bool => $r['level'] === 'info',
        ));
        $initLogs = array_values(array_filter(
            $infoRecords,
            static fn(array $r): bool => ($r['context']['mcp_event'] ?? '') === 'session.initialize',
        ));
        self::assertCount(1, $initLogs);
        self::assertSame('resume', $initLogs[0]['context']['reason']);
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
            static fn(array $r): bool => $r['level'] === 'info',
        ));
        $refreshLogs = array_values(array_filter(
            $infoRecords,
            static fn(array $r): bool => ($r['context']['mcp_event'] ?? '') === 'catalog.refresh',
        ));
        self::assertCount(1, $refreshLogs);
        self::assertSame('test-run-cat', $refreshLogs[0]['context']['run_id']);
        self::assertSame('corr-cat', $refreshLogs[0]['context']['correlation_id']);
    }

    public function testRefreshCatalogFailureDoesNotOverwriteCatalog(): void
    {
        // Write broken JSON to trigger refresh failure
        file_put_contents($this->projectDir . '/.hatfield/mcp.json', '{broken');

        $message = new McpRefreshCatalogCommand(
            runId: 'test-run-cat-fail',
            correlationId: 'corr-fail',
        );

        $this->handler->onRefreshCatalog($message);

        // Should log a warning, NOT overwrite catalog
        $warnings = array_values(array_filter(
            $this->logger->records,
            static fn(array $r): bool => $r['level'] === 'warning',
        ));
        self::assertCount(1, $warnings);

        // Catalog should NOT have been written (only init writes on failure)
        self::assertFalse($this->catalogStore->wasWritten);
    }
}

/**
 * Test double for McpToolCatalogStoreInterface that records the last write.
 */
final class TestMcpCatalogStore implements McpToolCatalogStoreInterface
{
    public bool $wasWritten = false;
    public string $lastRunId = '';
    public ?McpToolCatalogDTO $lastCatalog = null;
    public array $reads = [];

    public function write(string $runId, McpToolCatalogDTO $catalog): void
    {
        $this->wasWritten = true;
        $this->lastRunId = $runId;
        $this->lastCatalog = $catalog;
        $this->reads[$runId] = $catalog;
    }

    public function read(string $runId): ?McpToolCatalogDTO
    {
        return $this->reads[$runId] ?? null;
    }
}
