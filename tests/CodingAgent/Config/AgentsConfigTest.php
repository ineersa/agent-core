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

    public function testDefaultMaxAgentsIsFour(): void
    {
        $config = AgentsConfig::fromRaw([]);
        $this->assertSame(4, $config->maxAgents);

        $explicit = AgentsConfig::fromRaw(['max_agents' => 6]);
        $this->assertSame(6, $explicit->maxAgents);
    }

    public function testDefaultValues(): void
    {
        $config = new AgentsConfig();

        $this->assertTrue($config->enabled);
        $this->assertCount(0, $config->paths);
        $this->assertSame(4, $config->maxAgents);
        $this->assertSame(86400, $config->subagentToolTimeoutSeconds);
        $this->assertSame(['settings', 'hatfield_docs'], $config->subagentExcludedTools);
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
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for agents');

        AgentsConfig::fromRaw('not-an-array');
    }

    /**
     * @return iterable<string, array{0: mixed, 1: string}>
     */
    public static function malformedRawCases(): iterable
    {
        yield 'explicit null section' => [null, 'Invalid value for agents: expected mapping, got null'];
        yield 'sequential list section' => [['a', 'b'], 'Invalid value for agents: expected mapping, got list'];
        yield 'unknown key' => [['bogus' => 1], 'Invalid key for agents'];
        yield 'associative paths' => [['paths' => ['a' => 'x.md']], 'Invalid value for agents.paths: expected list of strings, got associative array'];
        yield 'extensions explicit null' => [['extensions' => null], 'Invalid value for agents.extensions: expected mapping, got null'];
        yield 'extensions unknown key' => [['extensions' => ['bogus' => 1]], 'Invalid key for agents.extensions'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedRawCases')]
    public function testFromRawRejectsMalformedRaw(mixed $raw, string $messageFragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($messageFragment);

        AgentsConfig::fromRaw($raw);
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

    public function testFromRawRejectsBlankPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for agents.paths[0]');

        AgentsConfig::fromRaw([
            'paths' => ['', '  ', 'valid-path'],
        ]);
    }

    public function testFromRawRejectsNonStringPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for agents.paths[0]');

        AgentsConfig::fromRaw([
            'paths' => [123, true, null, 'valid-path'],
        ]);
    }

    public function testFromRawRejectsWrongTypeEnabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for agents.enabled');

        AgentsConfig::fromRaw(['enabled' => 'yes']);
    }

    public function testFromRawRejectsNonPositiveMaxAgents(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for agents.max_agents');

        AgentsConfig::fromRaw(['max_agents' => 0]);
    }

    public function testFromRawRejectsWrongTypeMaxAgents(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid value for agents.max_agents');

        AgentsConfig::fromRaw(['max_agents' => '3']);
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
        $resolver = new AppConfigLoader($pathResolver);

        $resolution = $resolver->load($defaultsPath, $cwd);
        $merged = $resolution->effective;

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

    public function testFromRawAcceptsCustomAndEmptySubagentExcludedTools(): void
    {
        $custom = AgentsConfig::fromRaw(['subagent_excluded_tools' => ['settings']]);
        $this->assertSame(['settings'], $custom->subagentExcludedTools);

        $empty = AgentsConfig::fromRaw(['subagent_excluded_tools' => []]);
        $this->assertSame([], $empty->subagentExcludedTools);
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function malformedSubagentExcludedToolsCases(): iterable
    {
        yield 'scalar string' => ['settings'];
        yield 'associative map' => [['settings' => true]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedSubagentExcludedToolsCases')]
    public function testFromRawRejectsMalformedSubagentExcludedTools(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('agents.subagent_excluded_tools');

        AgentsConfig::fromRaw(['subagent_excluded_tools' => $value]);
    }

    public function testFromRawParsesAgentsExtensionsAlwaysOn(): void
    {
        $config = AgentsConfig::fromRaw([
            'extensions' => [
                'always_on' => [
                    'Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension',
                    'Ineersa\\HatfieldExt\\TaskWorkflow\\TaskWorkflowExtension',
                ],
            ],
        ]);

        $this->assertSame([
            'Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension',
            'Ineersa\\HatfieldExt\\TaskWorkflow\\TaskWorkflowExtension',
        ], $config->extensions->alwaysOn);
    }

    public function testFromRawRejectsMalformedAgentsExtensionsAlwaysOn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('agents.extensions.always_on');

        AgentsConfig::fromRaw([
            'extensions' => [
                'always_on' => ['ok', ''],
            ],
        ]);
    }

    public function testFromRawRejectsUnusedAgentsExtensionsEnabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('agents.extensions');

        AgentsConfig::fromRaw([
            'extensions' => [
                'always_on' => ['Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension'],
                'enabled' => ['Ineersa\\HatfieldExt\\TaskWorkflow\\TaskWorkflowExtension'],
            ],
        ]);
    }

    public function testForksFromRawParsesExtensionsLists(): void
    {
        $forks = \Ineersa\CodingAgent\Config\ForksConfigDTO::fromRaw([
            'extensions' => [
                'always_on' => ['A\\Safe'],
                'enabled' => ['B\\Castor'],
            ],
        ]);

        $this->assertSame(['A\\Safe'], $forks->extensions->alwaysOn);
        $this->assertSame(['B\\Castor'], $forks->extensions->enabled);
    }

    public function testForksFromRawRejectsNonListEnabled(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('forks.extensions.enabled');

        \Ineersa\CodingAgent\Config\ForksConfigDTO::fromRaw([
            'extensions' => [
                'enabled' => ['x' => 'B\\Castor'],
            ],
        ]);
    }
}
