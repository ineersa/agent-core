<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Agent\Fork\ForkConfigResolver;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ForksConfigDTO;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test thesis: ForksConfigDTO is wired from AppConfig so forks.model from Hatfield settings affects runtime.
 */
#[CoversClass(AppConfig::class)]
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
  model: llama_cpp/fork-model
YAML);

        if (self::$booted) {
            self::$kernel->shutdown();
            self::$booted = false;
        }

        self::bootKernel(['environment' => 'test', 'debug' => false]);
    }

    public function testForksConfigServiceResolvesModelFromAppConfig(): void
    {
        $forksConfig = self::getContainer()->get(ForksConfigDTO::class);
        $appConfig = self::getContainer()->get(AppConfig::class);

        Assert::assertSame('llama_cpp/fork-model', $forksConfig->model);
        Assert::assertSame($appConfig->forks, $forksConfig);

        $resolved = self::getContainer()->get(ForkConfigResolver::class)->resolve();
        Assert::assertSame('llama_cpp/fork-model', $resolved->resolvedModel);
    }
}
