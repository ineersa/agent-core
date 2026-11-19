<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Setup;

use Ineersa\CodingAgent\CLI\Setup\SetupCommand;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(SetupCommand::class)]
final class SetupCommandTest extends IsolatedKernelTestCase
{
    public function testNonInteractiveSetupRefusesWithoutWriting(): void
    {
        $path = $this->isolatedCwd().'/.hatfield/settings.yaml';
        $before = file_get_contents($path);
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('setup'));

        $status = $tester->execute(['--project' => true], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('needs an interactive terminal', $tester->getDisplay());
        $this->assertSame($before, file_get_contents($path));
    }
}
