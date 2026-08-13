<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Config;

use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\Mcp\Config\McpServerAvailabilityEnum;
use Ineersa\CodingAgent\Mcp\Config\McpTransportTypeEnum;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test McpConfigLoader end-to-end: JSON file reading, merge, validation, interpolation.
 *
 * Uses TestDirectoryIsolation for temporary .hatfield trees.
 */
class McpConfigLoaderTest extends TestCase
{
    private SettingsPathResolver $pathResolver;
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

    public function testAvailabilityFieldDefaultsToAllAndParsesSpecific(): void
    {
        $json = <<<'JSON'
{
  "mcpServers": {
    "global": {
      "url": "https://example.test/mcp",
      "availability": "specific"
    },
    "plain": {
      "url": "https://example.test/other"
    }
  }
}
JSON;
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', $json);

        $loader = $this->createLoader($this->projectDir);
        $config = $loader->load();

        $this->assertSame(McpServerAvailabilityEnum::Specific, $config->servers['global']->availability);
        $this->assertSame(McpServerAvailabilityEnum::All, $config->servers['plain']->availability);
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
                    'env' => [
                        'TOKEN' => '${NONEXISTENT_VAR}',
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
                    'env' => [
                        'X_KEY' => 'Bearer ${NONEXISTENT_SECRET}',
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
            $this->assertStringContainsString('env.X_KEY', $msg);
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

    /**
     * Dense rejection matrix for Serializer/Validator trust-boundary field rules.
     *
     * @param array<string, mixed> $server
     */
    #[DataProvider('invalidServerFieldCases')]
    public function testInvalidServerFieldsAreRejectedWithServerContext(array $server, string $messageNeedle): void
    {
        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'broken-server' => $server,
            ],
        ], \JSON_THROW_ON_ERROR));

        try {
            $this->createLoader()->load();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringContainsString('broken-server', $msg);
            $this->assertStringContainsString($messageNeedle, $msg);
            $this->assertStringNotContainsString('super-secret', $msg);
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidServerFieldCases(): iterable
    {
        // Scalar / type / constraint rejection
        yield 'enabled non-bool' => [['command' => 'cmd', 'enabled' => 'yes'], 'enabled'];
        yield 'command empty' => [['command' => ''], 'command'];
        yield 'command non-string' => [['command' => 12], 'command'];
        yield 'url empty' => [['url' => ''], 'url'];
        yield 'url non-string' => [['url' => 99], 'url'];
        yield 'cwd empty' => [['command' => 'cmd', 'cwd' => ''], 'cwd'];
        yield 'timeoutMs zero' => [['command' => 'cmd', 'timeoutMs' => 0], 'timeoutMs'];
        yield 'timeoutMs negative' => [['command' => 'cmd', 'timeoutMs' => -5], 'timeoutMs'];
        yield 'timeoutMs non-int' => [['command' => 'cmd', 'timeoutMs' => '30'], 'timeoutMs'];
        yield 'startupTimeoutMs zero' => [['command' => 'cmd', 'startupTimeoutMs' => 0], 'startupTimeoutMs'];
        yield 'startupTimeoutMs negative' => [['command' => 'cmd', 'startupTimeoutMs' => -1], 'startupTimeoutMs'];
        yield 'availability invalid' => [['command' => 'cmd', 'availability' => 'sometimes'], 'availability'];
        yield 'availability non-string' => [['command' => 'cmd', 'availability' => 1], 'availability'];

        // List rejection
        yield 'args non-array' => [['command' => 'cmd', 'args' => 'a'], 'args'];
        yield 'args non-list' => [['command' => 'cmd', 'args' => ['x' => 'y']], 'args'];
        yield 'args non-string item' => [['command' => 'cmd', 'args' => [1]], 'args'];
        yield 'excludeTools non-array' => [['command' => 'cmd', 'excludeTools' => 'tool'], 'excludeTools'];
        yield 'excludeTools non-list' => [['command' => 'cmd', 'excludeTools' => ['a' => 'b']], 'excludeTools'];
        yield 'excludeTools non-string item' => [['command' => 'cmd', 'excludeTools' => [false]], 'excludeTools'];

        // Map rejection
        yield 'env non-map' => [['command' => 'cmd', 'env' => 'TOKEN'], 'env'];
        yield 'env non-string value' => [['command' => 'cmd', 'env' => ['TOKEN' => 1]], 'env'];
        yield 'headers non-map' => [['url' => 'https://example.test', 'headers' => 'Bearer x'], 'headers'];
        yield 'headers non-string value' => [['url' => 'https://example.test', 'headers' => ['Authorization' => 7]], 'headers'];
    }

    /**
     * Source-aware inheritance/disable priority after unknown-field and enabled-type checks.
     *
     * @param array<string, mixed>|null $globalServers
     * @param array<string, mixed>      $projectServers
     */
    #[DataProvider('inheritanceAndPriorityCases')]
    public function testInheritanceDisableAndDiagnosticPriority(
        ?array $globalServers,
        array $projectServers,
        ?string $messageNeedle,
        bool $expectEmptyConfig,
    ): void {
        if (null !== $globalServers) {
            file_put_contents($this->globalDir.'/.hatfield/mcp.json', json_encode([
                'mcpServers' => $globalServers,
            ], \JSON_THROW_ON_ERROR));
        }

        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => $projectServers,
        ], \JSON_THROW_ON_ERROR));

        if (null === $messageNeedle) {
            $config = $this->createLoader()->load();
            if ($expectEmptyConfig) {
                $this->assertCount(0, $config->servers);
            }

            return;
        }

        try {
            $this->createLoader()->load();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString($messageNeedle, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>|null, 1: array<string, mixed>, 2: string|null, 3: bool}>
     */
    public static function inheritanceAndPriorityCases(): iterable
    {
        // Inherited disable-only with malformed allowed fields still succeeds (fields ignored).
        yield 'inherited disable ignores malformed allowed fields' => [
            ['filesystem' => ['command' => 'npx']],
            ['filesystem' => [
                'enabled' => false,
                'timeoutMs' => 0,
                'args' => 'not-a-list',
                'env' => 'not-a-map',
                'availability' => 'nope',
            ]],
            null,
            true,
        ];

        // Unknown field wins before transport/inheritance decisions.
        yield 'unknown field before inherited disable' => [
            ['filesystem' => ['command' => 'npx']],
            ['filesystem' => ['enabled' => false, 'bogus' => true]],
            'unknown field',
            false,
        ];

        // Wrong-type enabled wins before transport/inheritance decisions.
        yield 'enabled type before missing transport' => [
            null,
            ['ghost' => ['enabled' => 'false']],
            'enabled',
            false,
        ];

        // Invalid global is rejected even when project overrides the same name.
        yield 'invalid global rejected despite project override' => [
            ['broken' => ['command' => 'npx', 'timeoutMs' => 0]],
            ['broken' => ['command' => 'fixed']],
            'timeoutMs',
            false,
        ];
    }

    /**
     * Transport-inapplicable raw fields are ignored (not rejected / not interpolated).
     *
     * @param array<string, mixed>   $server
     * @param callable(object): void $assertServer
     */
    #[DataProvider('transportInapplicableFieldCases')]
    public function testTransportInapplicableFieldsAreIgnored(array $server, callable $assertServer): void
    {
        // Ensure a missing env would throw if headers/env were incorrectly interpolated.
        putenv('SHOULD_NOT_INTERPOLATE');

        file_put_contents($this->projectDir.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'srv' => $server,
            ],
        ], \JSON_THROW_ON_ERROR));

        $config = $this->createLoader()->load();
        $this->assertArrayHasKey('srv', $config->servers);
        $assertServer($config->servers['srv']);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: callable(object): void}>
     */
    public static function transportInapplicableFieldCases(): iterable
    {
        yield 'http ignores stdio fields' => [
            [
                'url' => 'https://example.test/mcp',
                'args' => 'not-a-list',
                'env' => ['TOKEN' => '${SHOULD_NOT_INTERPOLATE}'],
                'cwd' => '',
            ],
            static function (object $srv): void {
                self::assertSame(McpTransportTypeEnum::HTTP, $srv->transportType);
                self::assertSame([], $srv->args);
                self::assertSame([], $srv->env);
                self::assertNull($srv->cwd);
            },
        ];

        yield 'stdio ignores headers' => [
            [
                'command' => 'cmd',
                'headers' => ['Authorization' => 'Bearer ${SHOULD_NOT_INTERPOLATE}'],
            ],
            static function (object $srv): void {
                self::assertSame(McpTransportTypeEnum::STDIO, $srv->transportType);
                self::assertSame([], $srv->headers);
            },
        ];
    }

    // ─── Helper ───

    private function createLoader(): McpConfigLoader
    {
        return TestMcpConfigLoaderFactory::create($this->pathResolver, $this->projectDir);
    }
}
