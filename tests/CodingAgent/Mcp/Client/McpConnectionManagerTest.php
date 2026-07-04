<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Client;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Mcp\Client\McpConnectionManager;
use Ineersa\CodingAgent\Mcp\Client\McpConnectionManagerInterface;
use Ineersa\CodingAgent\Mcp\Client\McpSdkClientFactory;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Config\McpConfigValidator;
use Ineersa\CodingAgent\Mcp\Config\McpEnvInterpolator;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis 1: McpConnectionManager can connect to a STDIO fixture MCP
 * server and discover tools (listTools) through McpClientInterface.
 *
 * Test thesis 2: McpConnectionManager can connect to an HTTP fixture MCP
 * server (PHP built-in + Streamable HTTP) and discover tools.
 *
 * Test thesis 3: Discovery returns connected status with correct tool count
 * and tools have name/description/inputSchema keys.
 *
 * Test thesis 4: Failed server discovery is recorded with failed status,
 * diagnostic-safe error message, and no tools — never crashes the session.
 *
 * Test thesis 5: disconnectAll closes broker-owned clients for a run
 * without throwing.
 *
 * Test thesis 6: Error message sanitization redacts secret-bearing substrings
 * (bearer tokens, authorization headers, api_key, password, token values)
 * from log messages while preserving the class of error.
 *
 * Test thesis 7: Callback failure after successful server discovery must not
 * corrupt the discovery result (connected stays connected, discover() does
 * not throw, and callback failure is logged with correct attribution).
 *
 * Test thesis 8: Callback failure after failed server discovery must not
 * propagate (discover() returns failed result, does not throw).
 *
 * Test thesis 9: Callback is invoked once per server result with cumulative
 * results.
 */
class McpConnectionManagerTest extends TestCase
{
    private string $projectDir;
    private string $fixturePath;
    private McpConnectionManagerInterface $manager;
    private TestLogger $logger;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('mcp-conn-mgr');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir);

        // Fixture server path relative to worktree root
        $this->fixturePath = __DIR__.'/../Fixtures/stdio-echo-server.php';

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
        // Disconnect all clients to clean up any lingering processes
        try {
            $this->manager->disconnectAll('test-run');
            $this->manager->disconnectAll('test-run-fail');
            $this->manager->disconnectAll('test-run-empty');
            $this->manager->disconnectAll('test-run-http');
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
                    'command' => \PHP_BINARY,
                    'args' => [$this->fixturePath],
                    'timeoutMs' => 10000,
                    'startupTimeoutMs' => 10000,
                ],
            ],
        ];
        file_put_contents(
            $this->projectDir.'/.hatfield/mcp.json',
            json_encode($mcpConfig, \JSON_PRETTY_PRINT),
        );

        $results = $this->manager->discover('test-run');

        $this->assertArrayHasKey('fixture', $results, 'Fixture server should appear in discovery results');
        $this->assertSame('connected', $results['fixture']['status'], 'Fixture server should connect successfully');
        $this->assertSame('stdio', $results['fixture']['transport']);

        $tools = $results['fixture']['tools'];
        $this->assertGreaterThanOrEqual(2, \count($tools), 'Should discover at least 2 tools (echo, reverse)');

        // Find the echo tool
        $echoTool = $this->findTool($tools, 'echo');
        $this->assertNotNull($echoTool, 'Should discover echo tool');
        $this->assertArrayHasKey('description', $echoTool);
        $this->assertArrayHasKey('inputSchema', $echoTool);
        $this->assertIsArray($echoTool['inputSchema']);
        $this->assertSame('object', $echoTool['inputSchema']['type'] ?? '');

        // Find the reverse tool
        $reverseTool = $this->findTool($tools, 'reverse');
        $this->assertNotNull($reverseTool, 'Should discover reverse tool');

        // Verify structured logs
        $infoLogs = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'info' === $r['level']
                && ($r['context']['mcp_event'] ?? '') === 'discovery.server_connected',
        ));
        $this->assertCount(1, $infoLogs);
        $this->assertSame('fixture', $infoLogs[0]['context']['server_name']);

        // Verify starting log exists before connect
        $startingLogs = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'info' === $r['level']
                && ($r['context']['mcp_event'] ?? '') === 'discovery.server_starting',
        ));
        $this->assertCount(1, $startingLogs);
        $this->assertSame('fixture', $startingLogs[0]['context']['server_name']);
        $this->assertSame('stdio', $startingLogs[0]['context']['transport']);

        // Cleanup
        $this->manager->disconnectAll('test-run');
    }

    public function testDiscoverFailedServerReturnsFailedStatus(): void
    {
        // Use an unused TCP port on localhost for an HTTP connection that
        // will fail cleanly with a ConnectionException (connection refused).
        // Avoids verndor STDIO broken-pipe PHP notices triggered by
        // nonexistent-command process launch.
        $port = $this->findAvailablePort();
        $this->assertNotNull($port, 'No available port found for failed-HTTP test');

        // Write an mcp.json with an HTTP server pointing at an unused port
        $mcpConfig = [
            'mcpServers' => [
                'broken' => [
                    'url' => \sprintf('http://127.0.0.1:%d/mcp', $port),
                    'timeoutMs' => 2000,
                    'startupTimeoutMs' => 2000,
                ],
            ],
        ];
        file_put_contents(
            $this->projectDir.'/.hatfield/mcp.json',
            json_encode($mcpConfig, \JSON_PRETTY_PRINT),
        );

        $results = $this->manager->discover('test-run-fail');

        $this->assertArrayHasKey('broken', $results);
        $this->assertSame('failed', $results['broken']['status']);
        $this->assertSame('http', $results['broken']['transport']);
        $this->assertArrayHasKey('errorMessage', $results['broken']);
        $this->assertCount(0, $results['broken']['tools'], 'Failed server should have no tools');

        // Verify warning log exists for the failed server
        $warnings = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'warning' === $r['level']
                && ($r['context']['mcp_event'] ?? '') === 'discovery.server_failed',
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame('broken', $warnings[0]['context']['server_name']);
        $this->assertSame('http', $warnings[0]['context']['transport']);

        // Verify starting log exists per server before connect attempt
        $startingLogs = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'info' === $r['level']
                && ($r['context']['mcp_event'] ?? '') === 'discovery.server_starting',
        ));
        $this->assertCount(1, $startingLogs);
        $this->assertSame('broken', $startingLogs[0]['context']['server_name']);
        $this->assertSame('http', $startingLogs[0]['context']['transport']);
        $this->assertSame('test-run-fail', $startingLogs[0]['context']['run_id']);
    }

    public function testDiscoverWithEmptyConfigReturnsEmptyResults(): void
    {
        // No mcp.json — empty config
        $results = $this->manager->discover('test-run-empty');

        $this->assertIsArray($results);
        $this->assertCount(0, $results, 'Empty config should produce no discovery results');
    }

    public function testDisconnectAllCleansUpClients(): void
    {
        // First connect
        $mcpConfig = [
            'mcpServers' => [
                'fixture' => [
                    'command' => \PHP_BINARY,
                    'args' => [$this->fixturePath],
                    'timeoutMs' => 10000,
                    'startupTimeoutMs' => 10000,
                ],
            ],
        ];
        file_put_contents(
            $this->projectDir.'/.hatfield/mcp.json',
            json_encode($mcpConfig, \JSON_PRETTY_PRINT),
        );

        $this->manager->discover('test-run');

        // Disconnect should not throw
        $this->manager->disconnectAll('test-run');

        // After disconnect, getClient should return null
        $this->assertNull($this->manager->getClient('test-run', 'fixture'));
    }

    public function testDiscoverHttpServerReturnsConnectedWithTools(): void
    {
        // Find an available port for the HTTP fixture server
        $port = $this->findAvailablePort();
        $this->assertNotNull($port, 'No available port found for HTTP fixture');

        // Start PHP built-in server with the HTTP fixture script
        $fixtureScript = __DIR__.'/../Fixtures/http-echo-server.php';
        $host = '127.0.0.1';
        $process = proc_open(
            \sprintf('exec %s -S %s:%d %s 2>/dev/null', \PHP_BINARY, $host, $port, escapeshellarg($fixtureScript)),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        $this->assertIsResource($process, 'Failed to start HTTP fixture server');

        // Ensure cleanup on exit
        $cleanup = static function () use ($process, $pipes): void {
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    @fclose($pipe);
                }
            }
            if (\is_resource($process)) {
                @proc_terminate($process, \SIGTERM);
                @proc_close($process);
            }
        };

        try {
            // Poll health-check endpoint for readiness with short cap
            $ready = false;
            $startTime = microtime(true);
            while ((microtime(true) - $startTime) < 10.0) {
                $ch = curl_init(\sprintf('http://%s:%d/health', $host, $port));
                curl_setopt_array($ch, [
                    \CURLOPT_RETURNTRANSFER => true,
                    \CURLOPT_TIMEOUT => 1,
                    \CURLOPT_CONNECTTIMEOUT => 1,
                ]);
                $body = curl_exec($ch);
                $httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);
                // curl_close() is deprecated since PHP 8.5; has no effect since 8.0
                \is_resource($ch) && @curl_close($ch);

                if (200 === $httpCode && false !== $body) {
                    $data = json_decode($body, true);
                    if (\is_array($data) && ($data['status'] ?? '') === 'ok') {
                        $ready = true;
                        break;
                    }
                }
                usleep(100000); // 100ms
            }
            $this->assertTrue($ready, 'HTTP fixture server did not become ready within 10s');

            // Write an mcp.json with the HTTP server
            $mcpConfig = [
                'mcpServers' => [
                    'http-fixture' => [
                        'url' => \sprintf('http://%s:%d/mcp', $host, $port),
                        'timeoutMs' => 10000,
                    ],
                ],
            ];
            file_put_contents(
                $this->projectDir.'/.hatfield/mcp.json',
                json_encode($mcpConfig, \JSON_PRETTY_PRINT),
            );

            $results = $this->manager->discover('test-run-http');

            $this->assertArrayHasKey('http-fixture', $results, 'HTTP fixture server should appear in discovery results');
            $this->assertSame('connected', $results['http-fixture']['status'], 'HTTP fixture server should connect successfully');
            $this->assertSame('http', $results['http-fixture']['transport']);

            $tools = $results['http-fixture']['tools'];
            $this->assertGreaterThanOrEqual(2, \count($tools), 'Should discover at least 2 tools (hello, add)');

            // Find the hello tool
            $helloTool = $this->findTool($tools, 'hello');
            $this->assertNotNull($helloTool, 'Should discover hello tool');
            $this->assertArrayHasKey('description', $helloTool);
            $this->assertArrayHasKey('inputSchema', $helloTool);

            // Find the add tool
            $addTool = $this->findTool($tools, 'add');
            $this->assertNotNull($addTool, 'Should discover add tool');

            // Verify structured logs — transport should be http
            $infoLogs = array_values(array_filter(
                $this->logger->records,
                static fn (array $r): bool => 'info' === $r['level']
                    && ($r['context']['mcp_event'] ?? '') === 'discovery.server_connected',
            ));
            $this->assertCount(1, $infoLogs);
            $this->assertSame('http-fixture', $infoLogs[0]['context']['server_name']);
            $this->assertSame('http', $infoLogs[0]['context']['transport']);

            // Starting log must also be present
            $startingLogs = array_values(array_filter(
                $this->logger->records,
                static fn (array $r): bool => 'info' === $r['level']
                    && ($r['context']['mcp_event'] ?? '') === 'discovery.server_starting',
            ));
            $this->assertCount(1, $startingLogs);
            $this->assertSame('http-fixture', $startingLogs[0]['context']['server_name']);

            // Cleanup client
            $this->manager->disconnectAll('test-run-http');
        } finally {
            $cleanup();
        }
    }

    public function testCallbackFailureAfterConnectedServerDoesNotCorruptResults(): void
    {
        // Write an mcp.json with the fixture STDIO server
        $mcpConfig = [
            'mcpServers' => [
                'fixture' => [
                    'command' => \PHP_BINARY,
                    'args' => [$this->fixturePath],
                    'timeoutMs' => 10000,
                    'startupTimeoutMs' => 10000,
                ],
            ],
        ];
        file_put_contents(
            $this->projectDir.'/.hatfield/mcp.json',
            json_encode($mcpConfig, \JSON_PRETTY_PRINT),
        );

        $callbackError = new \RuntimeException('Catalog write disk full');
        $callbackCalled = false;

        $results = $this->manager->discover('test-run-cb', static function (array $cumulative) use ($callbackError, &$callbackCalled): void {
            $callbackCalled = true;
            throw $callbackError;
        });

        // Discover must still return connected result, not throw
        $this->assertArrayHasKey('fixture', $results);
        $this->assertSame('connected', $results['fixture']['status'], 'Connected status must survive callback failure');
        $this->assertTrue($callbackCalled, 'Callback should have been invoked');

        // Verify callback_failed warning was logged with correct attribution
        $cbWarnings = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'warning' === $r['level']
                && ($r['context']['mcp_event'] ?? '') === 'discovery.callback_failed',
        ));
        $this->assertCount(1, $cbWarnings);
        $this->assertSame('test-run-cb', $cbWarnings[0]['context']['run_id']);
        $this->assertSame('fixture', $cbWarnings[0]['context']['server_name']);
        $this->assertSame($callbackError::class, $cbWarnings[0]['context']['error_class']);
        $this->assertStringContainsString('Catalog write disk full', $cbWarnings[0]['context']['error_message'] ?? '');

        // Connected log must still exist — discovery result was not reclassified
        $connectedLogs = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'info' === $r['level']
                && ($r['context']['mcp_event'] ?? '') === 'discovery.server_connected',
        ));
        $this->assertCount(1, $connectedLogs);

        // Cleanup
        $this->manager->disconnectAll('test-run-cb');
    }

    public function testCallbackFailureAfterFailedServerDoesNotThrow(): void
    {
        $port = $this->findAvailablePort();
        $this->assertNotNull($port, 'No available port found for failed-HTTP test');

        $mcpConfig = [
            'mcpServers' => [
                'broken' => [
                    'url' => \sprintf('http://127.0.0.1:%d/mcp', $port),
                    'timeoutMs' => 2000,
                    'startupTimeoutMs' => 2000,
                ],
            ],
        ];
        file_put_contents(
            $this->projectDir.'/.hatfield/mcp.json',
            json_encode($mcpConfig, \JSON_PRETTY_PRINT),
        );

        $callbackCalled = false;

        $results = $this->manager->discover('test-run-fail-cb', static function (array $cumulative) use (&$callbackCalled): void {
            $callbackCalled = true;
            throw new \RuntimeException('Callback boom');
        });

        // Discover must still return failed result, not throw
        $this->assertArrayHasKey('broken', $results);
        $this->assertSame('failed', $results['broken']['status']);
        $this->assertTrue($callbackCalled, 'Callback should have been invoked');

        // Verify callback_failed warning exists
        $cbWarnings = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'warning' === $r['level']
                && ($r['context']['mcp_event'] ?? '') === 'discovery.callback_failed',
        ));
        $this->assertCount(1, $cbWarnings);
        $this->assertSame('broken', $cbWarnings[0]['context']['server_name']);

        // Discovery failed log must still exist
        $failWarnings = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'warning' === $r['level']
                && ($r['context']['mcp_event'] ?? '') === 'discovery.server_failed',
        ));
        $this->assertCount(1, $failWarnings);
    }

    public function testCallbackInvokedOncePerServerResult(): void
    {
        $port = $this->findAvailablePort();
        $this->assertNotNull($port, 'No available port found for failed-HTTP test');

        $mcpConfig = [
            'mcpServers' => [
                'broken' => [
                    'url' => \sprintf('http://127.0.0.1:%d/mcp', $port),
                    'timeoutMs' => 2000,
                    'startupTimeoutMs' => 2000,
                ],
            ],
        ];
        file_put_contents(
            $this->projectDir.'/.hatfield/mcp.json',
            json_encode($mcpConfig, \JSON_PRETTY_PRINT),
        );

        $callCount = 0;
        $lastCumulative = null;

        $this->manager->discover('test-run-cb-count', static function (array $cumulative) use (&$callCount, &$lastCumulative): void {
            ++$callCount;
            $lastCumulative = $cumulative;
        });

        $this->assertSame(1, $callCount, 'Callback must be invoked exactly once per server');
        $this->assertNotNull($lastCumulative);
        $this->assertArrayHasKey('broken', $lastCumulative);
        $this->assertSame('failed', $lastCumulative['broken']['status']);
    }

    public function testSanitizeLogMessageRedactsSecrets(): void
    {
        $testCases = [
            'no secrets' => [
                'input' => 'Connection refused for tcp://127.0.0.1:9000',
                'mustNotContain' => [],
                'mustContain' => ['Connection refused'],
            ],
            'bearer token in error' => [
                'input' => 'HTTP 401: Bearer sk-abc123xyz-secret-token not authorized',
                'mustNotContain' => ['sk-abc123xyz-secret-token'],
                'mustContain' => ['<redacted>'],
            ],
            'authorization header' => [
                'input' => 'Failed with Authorization: Bearer gh_token_1234 and other info',
                'mustNotContain' => ['gh_token_1234'],
                'mustContain' => ['<redacted>'],
            ],
            'api_key in url string' => [
                'input' => 'Request to http://host?api_key=abcdef123456 failed',
                'mustNotContain' => ['abcdef123456'],
                'mustContain' => ['<redacted>'],
            ],
        ];

        foreach ($testCases as $label => $tc) {
            $sanitized = McpConnectionManager::sanitizeLogMessage($tc['input']);
            foreach ($tc['mustNotContain'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $sanitized,
                    \sprintf('[%s] Sanitized message must not contain "%s"', $label, $forbidden),
                );
            }
            foreach ($tc['mustContain'] as $required) {
                $this->assertStringContainsString(
                    $required,
                    $sanitized,
                    \sprintf('[%s] Sanitized message must contain "%s"', $label, $required),
                );
            }
        }
    }

    /**
     * @param list<array{name: string, description?: string|null, inputSchema: array}> $tools
     *
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

    /**
     * Find an available TCP port on localhost.
     */
    private function findAvailablePort(): ?int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0');
        if (false === $socket) {
            return null;
        }
        $address = stream_socket_get_name($socket, false);
        if (false === $address) {
            @fclose($socket);

            return null;
        }
        @fclose($socket);

        $parts = explode(':', $address);
        $port = (int) end($parts);

        return $port > 0 ? $port : null;
    }
}
