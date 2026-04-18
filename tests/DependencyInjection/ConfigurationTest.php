<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\DependencyInjection;

use Ineersa\AgentCore\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

#[CoversClass(Configuration::class)]
final class ConfigurationTest extends TestCase
{
    public function testDefaultConfigurationValues(): void
    {
        $processor = new Processor();
        $config = $processor->processConfiguration(new Configuration(), [[]]);

        self::assertSame('messenger', $config['runtime']);
        self::assertSame('mercure', $config['streaming']);
        self::assertSame('agent_loop.run_logs', $config['storage']['run_log']['flysystem_storage']);
        self::assertSame('%kernel.project_dir%/var/agent-runs', $config['storage']['run_log']['base_path']);
        self::assertSame('doctrine', $config['storage']['hot_prompt']['backend']);
        self::assertSame('sequential', $config['tools']['defaults']['mode']);
        self::assertSame(90, $config['tools']['defaults']['timeout_seconds']);
        self::assertSame(4, $config['tools']['max_parallelism']);
        self::assertSame(120, $config['tools']['overrides']['web_search']['timeout_seconds']);
        self::assertSame('interrupt', $config['tools']['overrides']['ask_user']['mode']);
        self::assertSame(100, $config['commands']['max_pending_per_run']);
        self::assertSame('ext:', $config['commands']['custom_kind_prefix']);
        self::assertSame('ext_', $config['events']['custom_type_prefix']);
        self::assertSame(5, $config['checkpoints']['every_turns']);
        self::assertSame(256, $config['checkpoints']['max_delta_kb']);
        self::assertSame(24, $config['retention']['hot_prompt_ttl_hours']);
        self::assertSame(7, $config['retention']['archive_after_days']);
    }

    public function testInvalidCommandPrefixIsRejected(): void
    {
        $processor = new Processor();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('agent_loop.commands.custom_kind_prefix must start with "ext:".');

        $processor->processConfiguration(new Configuration(), [[
            'commands' => [
                'custom_kind_prefix' => 'custom:',
            ],
        ]]);
    }

    public function testInvalidEventPrefixIsRejected(): void
    {
        $processor = new Processor();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('agent_loop.events.custom_type_prefix must start with "ext_".');

        $processor->processConfiguration(new Configuration(), [[
            'events' => [
                'custom_type_prefix' => 'custom_',
            ],
        ]]);
    }
}
