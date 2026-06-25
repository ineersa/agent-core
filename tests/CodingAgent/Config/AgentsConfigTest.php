<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AgentsConfig covering defaults, fromRaw, and fromAppConfig.
 *
 * Test thesis: AgentsConfig correctly reads settings from YAML config data
 * including enabled flag and path resolution via AppConfigLoader.
 */
final class AgentsConfigTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir();
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tempDir);
    }

    public function testDefaultValues(): void
    {
        $config = new AgentsConfig();

        self::assertTrue($config->enabled);
        self::assertCount(0, $config->paths);
        self::assertSame(8, $config->maxAgents);
        self::assertSame(900, $config->subagentToolTimeoutSeconds);
    }

    public function testFromRawWithMaxAgents(): void
    {
        $config = AgentsConfig::fromRaw(['max_agents' => 4]);

        self::assertSame(4, $config->maxAgents);
    }

    public function testFromRawEmptyArray(): void
    {
        $config = AgentsConfig::fromRaw([]);

        self::assertTrue($config->enabled);
        self::assertCount(0, $config->paths);
    }

    public function testFromRawNonArray(): void
    {
        $config = AgentsConfig::fromRaw('not-an-array');

        self::assertTrue($config->enabled);
        self::assertCount(0, $config->paths);
    }

    public function testFromRawWithEnabled(): void
    {
        $config = AgentsConfig::fromRaw(['enabled' => false]);

        self::assertFalse($config->enabled);
    }

    public function testFromRawWithPaths(): void
    {
        $config = AgentsConfig::fromRaw([
            'paths' => [
                '~/custom/agent.md',
                '.hatfield/team-agents',
            ],
        ]);

        self::assertTrue($config->enabled);
        self::assertCount(2, $config->paths);
        self::assertSame('~/custom/agent.md', $config->paths[0]);
        self::assertSame('.hatfield/team-agents', $config->paths[1]);
    }

    public function testFromRawIgnoresBlankPaths(): void
    {
        $config = AgentsConfig::fromRaw([
            'paths' => ['', '  ', 'valid-path'],
        ]);

        self::assertCount(1, $config->paths);
        self::assertSame('valid-path', $config->paths[0]);
    }

    public function testFromRawIgnoresNonStringPaths(): void
    {
        $config = AgentsConfig::fromRaw([
            'paths' => [123, true, null, 'valid-path'],
        ]);

        self::assertCount(1, $config->paths);
        self::assertSame('valid-path', $config->paths[0]);
    }

    public function testPathResolutionThroughAppConfigLoader(): void
    {
        $appRoot = $this->tempDir.'/app';
        mkdir($appRoot, 0755, true);
        mkdir($appRoot.'/config', 0755, true);

        $defaultsPath = $appRoot.'/config/hatfield.defaults.yaml';
        file_put_contents($defaultsPath, "agents:\n  enabled: true\n  paths:\n    - ./custom\n");

        $cwd = $this->tempDir.'/project';
        mkdir($cwd, 0755, true);

        $pathResolver = new SettingsPathResolver($appRoot);
        $loader = new AppConfigLoader($pathResolver);

        $merged = $loader->load($defaultsPath, $cwd);

        self::assertArrayHasKey('agents', $merged);
        self::assertArrayHasKey('paths', $merged['agents']);
        // The relative path './custom' should be resolved to an absolute path under $cwd
        self::assertStringStartsWith($cwd, $merged['agents']['paths'][0]);
    }

    public function testFromAppConfigReturnsAgentsConfig(): void
    {
        $agentsConfig = new AgentsConfig(enabled: true, paths: ['test-path']);
        $appConfig = new AppConfig(
            tui: new \Ineersa\CodingAgent\Config\TuiConfig('cyberpunk'),
            logging: new \Ineersa\CodingAgent\Config\LoggingConfig(),
            agents: $agentsConfig,
        );

        $result = AgentsConfig::fromAppConfig($appConfig);

        self::assertSame($agentsConfig, $result);
    }

    public function testFromRawWithSubagentToolTimeoutSeconds(): void
    {
        $config = AgentsConfig::fromRaw(['subagent_tool_timeout_seconds' => 600]);

        self::assertSame(600, $config->subagentToolTimeoutSeconds);
    }
}
