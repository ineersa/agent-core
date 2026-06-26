<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
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

        $this->assertTrue($config->enabled);
        $this->assertCount(0, $config->paths);
        $this->assertSame(8, $config->maxAgents);
        $this->assertSame(1800, $config->subagentToolTimeoutSeconds);
    }

    public function testFromRawWithMaxAgents(): void
    {
        $config = AgentsConfig::fromRaw(['max_agents' => 4]);

        $this->assertSame(4, $config->maxAgents);
    }

    public function testFromRawEmptyArray(): void
    {
        $config = AgentsConfig::fromRaw([]);

        $this->assertTrue($config->enabled);
        $this->assertCount(0, $config->paths);
    }

    public function testFromRawNonArray(): void
    {
        $config = AgentsConfig::fromRaw('not-an-array');

        $this->assertTrue($config->enabled);
        $this->assertCount(0, $config->paths);
    }

    public function testFromRawWithEnabled(): void
    {
        $config = AgentsConfig::fromRaw(['enabled' => false]);

        $this->assertFalse($config->enabled);
    }

    public function testFromRawWithPaths(): void
    {
        $config = AgentsConfig::fromRaw([
            'paths' => [
                '~/custom/agent.md',
                '.hatfield/team-agents',
            ],
        ]);

        $this->assertTrue($config->enabled);
        $this->assertCount(2, $config->paths);
        $this->assertSame('~/custom/agent.md', $config->paths[0]);
        $this->assertSame('.hatfield/team-agents', $config->paths[1]);
    }

    public function testFromRawIgnoresBlankPaths(): void
    {
        $config = AgentsConfig::fromRaw([
            'paths' => ['', '  ', 'valid-path'],
        ]);

        $this->assertCount(1, $config->paths);
        $this->assertSame('valid-path', $config->paths[0]);
    }

    public function testFromRawIgnoresNonStringPaths(): void
    {
        $config = AgentsConfig::fromRaw([
            'paths' => [123, true, null, 'valid-path'],
        ]);

        $this->assertCount(1, $config->paths);
        $this->assertSame('valid-path', $config->paths[0]);
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

        $this->assertArrayHasKey('agents', $merged);
        $this->assertArrayHasKey('paths', $merged['agents']);
        // The relative path './custom' should be resolved to an absolute path under $cwd
        $this->assertStringStartsWith($cwd, $merged['agents']['paths'][0]);
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

        $this->assertSame($agentsConfig, $result);
    }

    public function testFromRawRejectsSubagentToolTimeoutBelowMinimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('below the minimum of 60');

        AgentsConfig::fromRaw(['subagent_tool_timeout_seconds' => 30]);
    }

    public function testFromRawRejectsNonIntegerSubagentToolTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('agents.subagent_tool_timeout_seconds');

        AgentsConfig::fromRaw(['subagent_tool_timeout_seconds' => '1200']);
    }

    public function testFromRawAcceptsMinimumSubagentToolTimeout(): void
    {
        $config = AgentsConfig::fromRaw(['subagent_tool_timeout_seconds' => 60]);

        $this->assertSame(60, $config->subagentToolTimeoutSeconds);
    }

    public function testFromRawWithSubagentToolTimeoutSeconds(): void
    {
        $config = AgentsConfig::fromRaw(['subagent_tool_timeout_seconds' => 600]);

        $this->assertSame(600, $config->subagentToolTimeoutSeconds);
    }
}
