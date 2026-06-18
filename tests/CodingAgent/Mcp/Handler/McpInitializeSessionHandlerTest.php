<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Handler;

use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Config\McpConfigValidator;
use Ineersa\CodingAgent\Mcp\Config\McpEnvInterpolator;
use Ineersa\CodingAgent\Mcp\Handler\McpInitializeSessionHandler;
use Ineersa\CodingAgent\Mcp\Message\McpDisconnectSessionCommand;
use Ineersa\CodingAgent\Mcp\Message\McpInitializeSessionCommand;
use Ineersa\CodingAgent\Mcp\Message\McpRefreshCatalogCommand;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis 1: Initialize with empty config emits a structured info log
 * with component=mcp, mcp_event=session.initialize, enabled_server_count=0,
 * and correlation fields (run_id, reason, correlation_id).
 *
 * Test thesis 2: Invalid MCP config (broken JSON) is non-fatal — the
 * handler logs a warning with error_class + error_message and continues
 * without throwing.  The log must never leak raw config, env values,
 * headers, or tokens.
 *
 * Test thesis 3: Refresh catalog and disconnect skeleton handlers log
 * structured debug events with the expected mcp_event values.
 *
 * Constructs the handler manually (no kernel boot) so TestLogger can
 * capture exact log records for assertion.  Temp MCP config files are
 * isolated via TestDirectoryIsolation.
 */
class McpInitializeSessionHandlerTest extends TestCase
{
    private string $projectDir;
    private TestLogger $logger;
    private McpInitializeSessionHandler $handler;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('mcp-handler');
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

        $this->logger = new TestLogger();
        $this->handler = new McpInitializeSessionHandler($configLoader, $this->logger);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testInitializeEmptyConfigLogsStructuredInfo(): void
    {
        $command = new McpInitializeSessionCommand(
            runId: 'test-run-abc',
            reason: 'start_run',
            correlationId: 'corr-001',
        );

        ($this->handler)($command);

        // There should be exactly one info record (no per-server debug records
        // because there are no configured servers).
        $infoRecords = array_values(array_filter(
            $this->logger->records,
            static fn(array $r): bool => $r['level'] === 'info',
        ));
        self::assertCount(1, $infoRecords, 'Expected one info-level log for session initialize');

        $record = $infoRecords[0];
        self::assertStringContainsString('MCP session initialize', $record['message']);

        $ctx = $record['context'];
        self::assertSame('mcp', $ctx['component'], 'component must be mcp');
        self::assertSame('session.initialize', $ctx['mcp_event'], 'mcp_event must be session.initialize');
        self::assertSame('test-run-abc', $ctx['run_id']);
        self::assertSame('test-run-abc', $ctx['session_id']);
        self::assertSame('start_run', $ctx['reason']);
        self::assertSame('corr-001', $ctx['correlation_id']);
        self::assertSame(0, $ctx['enabled_server_count'], 'Empty config should report 0 enabled servers');
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
        self::assertCount(1, $infoRecords);

        $ctx = $infoRecords[0]['context'];
        self::assertSame('resume', $ctx['reason'], 'reason must reflect resume dispatch');
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
        self::assertSame('test-run-bad', $ctx['run_id']);
        self::assertArrayHasKey('error_class', $ctx, 'Should include error_class for diagnostics');
        self::assertArrayHasKey('error_message', $ctx, 'Should include error_message for diagnostics');

        // Verify no raw config, env, or secret values leak into the log.
        $contextJson = json_encode($ctx, \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('{broken', $contextJson, 'Broken JSON must not appear in log context');
        self::assertStringNotContainsString('token', strtolower($contextJson), 'No secret-like content should leak');
    }

    public function testRefreshCatalogSkeletonLogsDebug(): void
    {
        $message = new McpRefreshCatalogCommand(
            runId: 'test-run-cat',
            correlationId: 'corr-cat',
        );

        $this->handler->onRefreshCatalog($message);

        $debugRecords = array_values(array_filter(
            $this->logger->records,
            static fn(array $r): bool => $r['level'] === 'debug',
        ));
        self::assertCount(1, $debugRecords);

        $ctx = $debugRecords[0]['context'];
        self::assertSame('mcp', $ctx['component']);
        self::assertSame('catalog.refresh.requested', $ctx['mcp_event']);
        self::assertSame('test-run-cat', $ctx['run_id']);
        self::assertSame('corr-cat', $ctx['correlation_id']);
    }

    public function testDisconnectSkeletonLogsDebug(): void
    {
        $message = new McpDisconnectSessionCommand(
            runId: 'test-run-disc',
            correlationId: 'corr-disc',
        );

        $this->handler->onDisconnectSession($message);

        $debugRecords = array_values(array_filter(
            $this->logger->records,
            static fn(array $r): bool => $r['level'] === 'debug',
        ));
        self::assertCount(1, $debugRecords);

        $ctx = $debugRecords[0]['context'];
        self::assertSame('mcp', $ctx['component']);
        self::assertSame('session.disconnect.requested', $ctx['mcp_event']);
        self::assertSame('test-run-disc', $ctx['run_id']);
        self::assertSame('corr-disc', $ctx['correlation_id']);
    }
}
