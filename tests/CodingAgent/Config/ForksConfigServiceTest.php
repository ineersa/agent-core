<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Agent\Fork\ForkConfigResolver;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ForkLevelEnum;
use Ineersa\CodingAgent\Config\ForksConfigDTO;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test thesis: ForksConfigDTO is wired from AppConfig in the DI container so
 * forks.default_level and forks.levels.*.model from Hatfield settings affect runtime.
 */
#[CoversClass(ForksConfigDTO::class)]
final class ForksConfigServiceTest extends IsolatedKernelTestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        file_put_contents(getcwd().'/.hatfield/settings.yaml', <<<'YAML'
# hatfield settings (test isolation)
ai:
    default_model: null
forks:
  default_level: senior
  levels:
    senior:
      model: llama_cpp/senior-fork
YAML);

        if (self::$booted) {
            self::$kernel->shutdown();
            self::$booted = false;
        }

        self::bootKernel(['environment' => 'test', 'debug' => false]);
    }

    public function testForksConfigServiceResolvesFromAppConfigWithLevelModelOverride(): void
    {
        $forksConfig = self::getContainer()->get(ForksConfigDTO::class);
        $appConfig = self::getContainer()->get(AppConfig::class);

        $this->assertSame(ForkLevelEnum::Senior, $forksConfig->defaultLevel);
        $this->assertSame('llama_cpp/senior-fork', $forksConfig->levelConfig(ForkLevelEnum::Senior)->model);
        $this->assertSame($appConfig->forks, $forksConfig);

        $resolved = self::getContainer()->get(ForkConfigResolver::class)->resolve(ForkLevelEnum::Senior);
        $this->assertSame('llama_cpp/senior-fork', $resolved->resolvedModel);
    }
}
