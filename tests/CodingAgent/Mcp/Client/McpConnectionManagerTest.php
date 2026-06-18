<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Client;

use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Mcp\Client\McpConnectionManager;
use Ineersa\CodingAgent\Mcp\Client\McpSdkClientFactory;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Config\McpConfigValidator;
use Ineersa\CodingAgent\Mcp\Config\McpEnvInterpolator;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis 1: McpConnectionManager can connect to a STDIO fixture MCP
 * server and discover tools (listTools) through McpClientInterface.
 *
 * Test thesis 2: Discovery returns connected status with correct tool count
 * and tools have name/description/inputSchema keys.
 *
 * Test thesis 3: Failed server discovery is recorded with failed status,
 * diagnostic-safe error message, and no tools — never crashes the session.
 *
 * Test thesis 4: disconnectAll closes broker-owned clients for a run
 * without throwing.
 *
 * These tests use a real PHP MCP SDK server process via STDIO transport.
 * The fixture server is a standalone PHP script that registers two simple
 * tools (echo, reverse).  The server process is started by the SDK's
 * StdioTransport via proc_open and terminated when the client disconnects.
 */
class McpConnectionManagerTest extends TestCase
{
    private string $projectDir;
    private string $fixturePath;
    private McpConnectionManager $manager;
    private TestLogger $logger;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('mcp-conn-mgr');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir);

        // Fixture server path relative to worktree root
        $this->fixturePath = __DIR__ . '/../Fixtures/stdio-echo-server.php';

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
        $this->manager = new McpConnectionManager(
            $configLoader,
            new McpSdkClientFactory(),
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        // Disconnect all clients to clean up any lingering STDIO processes
        try {
            $this->manager->disconnectAll('test-run');
        } catch (\Throwable) {
            // Best-effort cleanup
        }

        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testDiscoverStdioServerReturnsConnectedWithTools(): void
    {
        // Write an mcp.json with the fixture STDIO server
        $mcpConfig = [
            'mcpServers' => [
                'fixture' => [
                    'command' => PHP_BINARY,
                    'args' => [$this->fixturePath],
                    'timeoutMs' => 10000,
                    'startupTimeoutMs' => 10000,
                ],
            ],
        ];
        file_put_contents(
            $this->projectDir . '/.hatfield/mcp.json',
            json_encode($mcpConfig, \JSON_PRETTY_PRINT),
        );

        $results = $this->manager->discover('test-run');

        self::assertArrayHasKey('fixture', $results, 'Fixture server should appear in discovery results');
        self::assertSame('connected', $results['fixture']['status'], 'Fixture server should connect successfully');
        self::assertSame('stdio', $results['fixture']['transport']);

        $tools = $results['fixture']['tools'];
        self::assertGreaterThanOrEqual(2, count($tools), 'Should discover at least 2 tools (echo, reverse)');

        // Find the echo tool
        $echoTool = $this->findTool($tools, 'echo');
        self::assertNotNull($echoTool, 'Should discover echo tool');
        self::assertArrayHasKey('description', $echoTool);
        self::assertArrayHasKey('inputSchema', $echoTool);
        self::assertIsArray($echoTool['inputSchema']);
        self::assertSame('object', $echoTool['inputSchema']['type'] ?? '');

        // Find the reverse tool
        $reverseTool = $this->findTool($tools, 'reverse');
        self::assertNotNull($reverseTool, 'Should discover reverse tool');

        // Verify structured logs
        $infoLogs = array_filter(
            $this->logger->records,
            static fn(array $r): bool =>
                $r['level'] === 'info' &&
                ($r['context']['mcp_event'] ?? '') === 'discovery.server_connected',
        );
        self::assertCount(1, $infoLogs);
        self::assertSame('fixture', $infoLogs[0]['context']['server_name']);

        // Cleanup
        $this->manager->disconnectAll('test-run');
    }

    public function testDiscoverFailedServerReturnsFailedStatus(): void
    {
        // Write an mcp.json with a non-existent command
        $mcpConfig = [
            'mcpServers' => [
                'broken' => [
                    'command' => '/nonexistent/command/that/does/not/exist',
                    'args' => [],
                    'timeoutMs' => 5000,
                    'startupTimeoutMs' => 2000,
                ],
            ],
        ];
        file_put_contents(
            $this->projectDir . '/.hatfield/mcp.json',
            json_encode($mcpConfig, \JSON_PRETTY_PRINT),
        );

        $results = $this->manager->discover('test-run-fail');

        self::assertArrayHasKey('broken', $results);
        self::assertSame('failed', $results['broken']['status']);
        self::assertArrayHasKey('errorMessage', $results['broken']);
        self::assertCount(0, $results['broken']['tools'], 'Failed server should have no tools');

        // Verify warning log exists for the failed server
        $warnings = array_filter(
            $this->logger->records,
            static fn(array $r): bool =>
                $r['level'] === 'warning' &&
                ($r['context']['mcp_event'] ?? '') === 'discovery.server_failed',
        );
        self::assertCount(1, $warnings);
        self::assertSame('broken', $warnings[0]['context']['server_name']);
    }

    public function testDiscoverWithEmptyConfigReturnsEmptyResults(): void
    {
        // No mcp.json — empty config
        $results = $this->manager->discover('test-run-empty');

        self::assertIsArray($results);
        self::assertCount(0, $results, 'Empty config should produce no discovery results');
    }

    public function testDisconnectAllCleansUpClients(): void
    {
        // First connect
        $mcpConfig = [
            'mcpServers' => [
                'fixture' => [
                    'command' => PHP_BINARY,
                    'args' => [$this->fixturePath],
                    'timeoutMs' => 10000,
                    'startupTimeoutMs' => 10000,
                ],
            ],
        ];
        file_put_contents(
            $this->projectDir . '/.hatfield/mcp.json',
            json_encode($mcpConfig, \JSON_PRETTY_PRINT),
        );

        $this->manager->discover('test-run');

        // Disconnect should not throw
        $this->manager->disconnectAll('test-run');

        // After disconnect, getClient should return null
        self::assertNull($this->manager->getClient('test-run', 'fixture'));
    }

    /**
     * @param list<array{name: string, description?: string|null, inputSchema: array}> $tools
     * @return array{name: string, description?: string|null, inputSchema: array}|null
     */
    private function findTool(array $tools, string $name): ?array
    {
        foreach ($tools as $tool) {
            if (($tool['name'] ?? '') === $name) {
                return $tool;
            }
        }

        return null;
    }
}
