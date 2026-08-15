<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ForksConfigDTO;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

/**
 * Container wiring regression for ForksConfigDTO.
 *
 * Thesis: the DI container must resolve ForksConfigDTO via fromAppConfig so
 * project forks.model / forks.thinking_level are not lost to an empty
 * auto-registered all-defaults instance.
 */
final class ForksConfigServiceTest extends IsolatedKernelTestCase
{
    public function testContainerForksConfigReflectsProjectSettingsAndAppConfigIdentity(): void
    {
        /** @var ForksConfigDTO $forksConfig */
        $forksConfig = self::getContainer()->get(ForksConfigDTO::class);
        /** @var AppConfig $appConfig */
        $appConfig = self::getContainer()->get(AppConfig::class);

        $this->assertSame('deepseek/deepseek-v4-flash', $forksConfig->model);
        $this->assertSame('xhigh', $forksConfig->thinkingLevel);
        $this->assertSame($appConfig->forks, $forksConfig);
    }

    protected static function configureIsolatedProjectBeforeKernelBoot(string $classCwd): void
    {
        file_put_contents(
            $classCwd.'/.hatfield/settings.yaml',
            <<<'YAML'
# hatfield settings (forks container wiring isolation)
ai:
    default_model: null
forks:
    model: deepseek/deepseek-v4-flash
    thinking_level: xhigh
YAML
        );
    }
}
