<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Providers;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupCommand;
use Ineersa\CodingAgent\CLI\Providers\ProvidersSetupCommand;
use Ineersa\CodingAgent\CLI\Setup\SetupCommand;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ProvidersSetupCommand::class)]
#[CoversClass(ExtensionsSetupCommand::class)]
#[CoversClass(SetupCommand::class)]
final class ProvidersSetupCommandTest extends IsolatedKernelTestCase
{
    #[DataProvider('setupEntrypoints')]
    public function testNonInteractiveSetupRefusesWithoutWriting(string $name, string $scopeOption): void
    {
        $path = $this->isolatedCwd().'/.hatfield/settings.yaml';
        $before = file_get_contents($path);
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find($name));

        $status = $tester->execute([$scopeOption => true], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('needs an interactive terminal', $tester->getDisplay());
        $this->assertSame($before, file_get_contents($path));
    }

    /** @return iterable<array{string, string}> */
    public static function setupEntrypoints(): iterable
    {
        yield ['providers:setup', '--project'];
        yield ['extensions:setup', '--user'];
        yield ['setup', '--project'];
    }
}
