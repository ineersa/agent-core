<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ForksConfigDTO;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\ToolFilterRuntimeConfig;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Container wiring regression for ForksConfigDTO.
 *
 * Thesis: the DI container must resolve ForksConfigDTO via fromAppConfig so
 * project forks.model / forks.thinking_level are not lost to an empty
 * auto-registered all-defaults instance.
 */
final class ForksConfigServiceTest extends PerMethodIsolatedKernelTestCase
{
    #[TestWith([true])]
    #[TestWith([false])]
    public function testForkIsUnavailableRegardlessOfProjectSettings(bool $enabled): void
    {
        $container = self::getContainer();
        $forksConfig = $container->get(ForksConfigDTO::class);
        $appConfig = $container->get(AppConfig::class);

        $this->assertSame($enabled, $forksConfig->enabled);
        $this->assertSame('deepseek/deepseek-v4-flash', $forksConfig->model);
        $this->assertSame('xhigh', $forksConfig->thinkingLevel);
        $this->assertSame($appConfig->forks, $forksConfig);

        $registry = $container->get(ToolRegistryInterface::class);
        $container->get(ToolFilterRuntimeConfig::class)->applyFromCli('fork,subagent,agent_resume', '', $registry);
        $this->assertSame(['agent_resume', 'subagent'], $registry->activeToolNames());
        $this->assertArrayNotHasKey('fork', $registry->permanentGuidelinesByTool());

        $this->expectException(ToolNotFoundException::class);
        $container->get(ToolboxInterface::class)->execute(new ToolCall('test-fork', 'fork', ['task' => 'unavailable']));
    }

    protected function createIsolatedWorkingDirectory(): string
    {
        $cwd = parent::createIsolatedWorkingDirectory();
        TestDirectoryIsolation::createHatfieldTree($cwd);
        $path = $cwd.'/.hatfield/settings.yaml';
        $this->assertSame($path, realpath($path));
        $enabled = $this->providedData()[0] ? 'true' : 'false';
        file_put_contents($path, <<<YAML
ai:
    default_model: null
forks:
    enabled: {$enabled}
    model: deepseek/deepseek-v4-flash
    thinking_level: xhigh
YAML
        );

        return $cwd;
    }
}
