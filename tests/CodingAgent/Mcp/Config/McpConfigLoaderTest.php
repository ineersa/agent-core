<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Config;

use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Config\McpConfigValidator;
use Ineersa\CodingAgent\Mcp\Config\McpEnvInterpolator;
use Ineersa\CodingAgent\Mcp\Config\McpTransportTypeEnum;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Test McpConfigLoader end-to-end: JSON file reading, merge, validation, interpolation.
 *
 * Uses TestDirectoryIsolation for temporary .hatfield trees.
 */
class McpConfigLoaderTest extends TestCase
{
    private SettingsPathResolver $pathResolver;
    private McpConfigValidator $validator;
    private McpEnvInterpolator $interpolator;
    private string $globalDir;
    private string $projectDir;

    protected function setUp(): void
    {
        // Create two isolated directories simulating home and project
        $this->globalDir = TestDirectoryIsolation::createProjectTempDir('mcp-global');
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('mcp-project');

        // Scaffold .hatfield trees
        TestDirectoryIsolation::createHatfieldTree($this->globalDir);
        TestDirectoryIsolation::createHatfieldTree($this->projectDir);

        $this->pathResolver = new SettingsPathResolver(
            appRoot: '/app',  // irrelevant for these tests
            homeDir: $this->globalDir,
        );

        $this->validator = new McpConfigValidator();
        $this->interpolator = new McpEnvInterpolator();

        // Set up required env vars for interpolation tests
        putenv('MCP_TEST_TOKEN=test-token-value');
        putenv('MCP_TEST_API_KEY=test-api-key');
        putenv('MCP_EMPTY_VAR=');  // explicitly empty for the empty-var test
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->globalDir);
        TestDirectoryIsolation::removeDirectory($this->projectDir);

        // Clean up env vars
        putenv('MCP_TEST_TOKEN');
        putenv('MCP_TEST_API_KEY');
        putenv('MCP_EMPTY_VAR');
    }

    // ─── Empty / missing config ───

    public function testEmptyConfigWhenNoFilesExist(): void
    {
        $loader = $this->createLoader();
        $config = $loader->load();

        $this->assertCount(0, $config->servers);
    }

    public function testEmptyConfigWhenFilesAreEmptyObjects(): void
    {
        file_put_contents($this->globalDir.'/.hatfield/mcp.json', '{}');

        $loader = $this->createLoader();
        $config = $loader->load();

        $this->assertCount(0, $config->servers);
    }

    // ─── Global config loads STDIO and HTTP ───

    public function testGlobalConfigLoadsStdioServer(): void
    {
        file_put_contents($this->globalDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'filesystem' => [
                    'command' => 'npx',
                    'args' => ['-y', '@modelcontextprotocol/server-filesystem', '.'],
                    'cwd' => '.',
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();
        $config = $loader->load();

        $this->assertCount(1, $config->servers);
        $this->assertArrayHasKey('filesystem', $config->servers);

        $srv = $config->servers['filesystem'];
        $this->assertTrue($srv->enabled);
        $this->assertSame('npx', $srv->command);
        $this->assertSame(['-y', '@modelcontextprotocol/server-filesystem', '.'], $srv->args);
        $this->assertSame(McpTransportTypeEnum::STDIO, $srv->transportType);
        // cwd '.' resolved against project dir becomes absolute
        $this->assertNotSame('.', $srv->cwd);
        $this->assertStringStartsWith('/', $srv->cwd ?? '');
    }

    public function testGlobalConfigLoadsHttpServer(): void
    {
        file_put_contents($this->globalDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'github' => [
                    'url' => 'https://api.githubcopilot.com/mcp',
                    'headers' => [
                        'Authorization' => 'Bearer ${MCP_TEST_TOKEN}',
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();
        $config = $loader->load();

        $this->assertCount(1, $config->servers);
        $this->assertArrayHasKey('github', $config->servers);

        $srv = $config->servers['github'];
        $this->assertTrue($srv->enabled);
        $this->assertSame('https://api.githubcopilot.com/mcp', $srv->url);
        $this->assertSame(McpTransportTypeEnum::HTTP, $srv->transportType);
        $this->assertSame('Bearer test-token-value', $srv->headers['Authorization']);
    }

    // ─── Project overrides global (whole-server replacement) ───

    public function testProjectOverridesGlobalByWholeServerReplacement(): void
    {
        // Global: defines filesystem with env var
        file_put_contents($this->globalDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'filesystem' => [
                    'command' => 'npx',
                    'args' => ['-y', '@scope/mcp'],
                    'env' => ['OLD_VAR' => 'old'],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        // Project: replaces filesystem entirely — old env/args should NOT survive
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'filesystem' => [
                    'command' => 'node',
                    'args' => ['server.js'],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();
        $config = $loader->load();

        $this->assertCount(1, $config->servers);
        $srv = $config->servers['filesystem'];
        $this->assertSame('node', $srv->command);
        $this->assertSame(['server.js'], $srv->args);
        $this->assertSame([], $srv->env);  // old env did NOT survive
    }

    // ─── Disable inherited server ───

    public function testProjectDisableOnlyOverridesInheritedServer(): void
    {
        file_put_contents($this->globalDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'filesystem' => [
                    'command' => 'npx',
                    'args' => ['-y', '@scope/mcp'],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'filesystem' => [
                    'enabled' => false,
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();
        $config = $loader->load();

        // The server should be absent (disabled and removed)
        $this->assertCount(0, $config->servers);
    }

    // ─── Non-inherited disable-only fails ───

    public function testNonInheritedDisableOnlyFails(): void
    {
        // Only project config with a disable-only entry (no global inherited server)
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'ghost' => [
                    'enabled' => false,
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot define a server with only "enabled": false');

        $loader->load();
    }

    // ─── Complete disabled local definition with transport ───

    public function testCompleteDisabledLocalDefinitionIsAccepted(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'playwright' => [
                    'enabled' => false,
                    'command' => 'npx',
                    'args' => ['-y', '@playwright/mcp'],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();
        $config = $loader->load();

        // Should be absent (disabled means removed from final config)
        $this->assertCount(0, $config->servers);
    }

    // ─── Invalid command+url ───

    public function testInvalidCommandAndUrlFails(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'broken' => [
                    'command' => 'npx',
                    'url' => 'https://example.com/mcp',
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot define both "command" (STDIO) and "url" (HTTP)');

        $loader->load();
    }

    // ─── Missing transport on enabled server fails ───

    public function testMissingTransportOnEnabledServerFails(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'broken' => [
                    'enabled' => true,
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing transport');

        $loader->load();
    }

    // ─── Env interpolation succeeds ───

    public function testEnvInterpolationSucceeds(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'test-server' => [
                    'command' => 'test-cmd',
                    'env' => [
                        'TOKEN' => '${MCP_TEST_TOKEN}',
                        'KEY' => '${MCP_TEST_API_KEY}',
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();
        $config = $loader->load();

        $srv = $config->servers['test-server'];
        $this->assertSame('test-token-value', $srv->env['TOKEN']);
        $this->assertSame('test-api-key', $srv->env['KEY']);
    }

    // ─── Header interpolation succeeds ───

    public function testHeaderInterpolationSucceeds(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'test-server' => [
                    'url' => 'https://example.com/mcp',
                    'headers' => [
                        'Authorization' => 'Bearer ${MCP_TEST_TOKEN}',
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();
        $config = $loader->load();

        $srv = $config->servers['test-server'];
        $this->assertSame('Bearer test-token-value', $srv->headers['Authorization']);
    }

    // ─── Missing env var fails ───

    public function testMissingEnvVarFails(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'test-server' => [
                    'command' => 'test-cmd',
                    'headers' => [
                        'Authorization' => 'Bearer ${NONEXISTENT_VAR}',
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('NONEXISTENT_VAR');

        $loader->load();
    }

    // ─── Empty env var fails ───

    public function testEmptyEnvVarFails(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'test-server' => [
                    'command' => 'test-cmd',
                    'env' => [
                        'KEY' => '${MCP_EMPTY_VAR}',
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP_EMPTY_VAR');

        $loader->load();
    }

    // ─── Error message contains server name and variable but no secret ───

    public function testErrorDoesNotContainSecretValues(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'my-server' => [
                    'command' => 'test-cmd',
                    'headers' => [
                        'X-Key' => 'Bearer ${NONEXISTENT_SECRET}',
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();

        try {
            $loader->load();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('my-server', $msg);
            $this->assertStringContainsString('NONEXISTENT_SECRET', $msg);
            $this->assertStringContainsString('headers.X-Key', $msg);
            // Must NOT leak the secret value (which doesn't exist anyway, but the
            // error message pattern should not include surrounding values)
        }
    }

    // ─── Relative cwd resolves against project directory ───

    public function testRelativeCwdResolvesAgainstProjectDir(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'test-server' => [
                    'command' => 'test-cmd',
                    'cwd' => './subdir',
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();
        $config = $loader->load();

        $srv = $config->servers['test-server'];
        $this->assertStringEndsWith('/subdir', $srv->cwd ?? '');
        $this->assertStringStartsWith('/', $srv->cwd ?? '');
    }

    // ─── Project adds new server alongside global ones ───

    public function testProjectAddsNewServer(): void
    {
        file_put_contents($this->globalDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'global-server' => [
                    'command' => 'global-cmd',
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'project-server' => [
                    'command' => 'project-cmd',
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();
        $config = $loader->load();

        $this->assertCount(2, $config->servers);
        $this->assertArrayHasKey('global-server', $config->servers);
        $this->assertArrayHasKey('project-server', $config->servers);
    }

    // ─── Unknown field is rejected ───

    public function testUnknownFieldIsRejected(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'test-server' => [
                    'command' => 'test-cmd',
                    'bogusField' => 'value',
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unknown field');

        $loader->load();
    }

    // ─── Invalid JSON file fails clearly ───

    public function testInvalidJsonFileFailsClearly(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', '{broken json');

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');

        $loader->load();
    }

    // ─── Multiple servers in one file ───

    public function testMultipleServersLoadCorrectly(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'stdio-server' => [
                    'command' => 'test-cmd',
                    'timeoutMs' => 15000,
                    'startupTimeoutMs' => 10000,
                ],
                'http-server' => [
                    'url' => 'https://example.com/mcp',
                    'timeoutMs' => 60000,
                    'excludeTools' => ['unsafe_tool'],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();
        $config = $loader->load();

        $this->assertCount(2, $config->servers);

        $stdio = $config->servers['stdio-server'];
        $this->assertSame(McpTransportTypeEnum::STDIO, $stdio->transportType);
        $this->assertSame(15000, $stdio->timeoutMs);
        $this->assertSame(10000, $stdio->startupTimeoutMs);

        $http = $config->servers['http-server'];
        $this->assertSame(McpTransportTypeEnum::HTTP, $http->transportType);
        $this->assertSame(60000, $http->timeoutMs);
        $this->assertSame(['unsafe_tool'], $http->excludeTools);
        $this->assertSame(['unsafe_tool'], $http->excludeTools);
    }

    // ─── Edge case: mcpServers present but wrong type ───

    public function testMpcServersNotAnObjectFailsClearly(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => 'this-should-be-an-object',
        ], \JSON_THROW_ON_ERROR));

        $loader = $this->createLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mcpServers.*must be a JSON object/i');

        $loader->load();
    }
    // ─── Helper ───

    private function createLoader(): McpConfigLoader
    {
        return new McpConfigLoader(
            $this->pathResolver,
            $this->validator,
            $this->interpolator,
            $this->projectDir,
        );
    }
}
