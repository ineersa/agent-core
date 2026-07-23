<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Thesis: OM supervisor starts only for interactive public command name "agent"
 * and skips controller/headless modes.
 */
final class OmConsoleLifecycleSelectionTest extends TestCase
{
    public function testSkipsNonAgentCommands(): void
    {
        $extension = new ObservationalMemoryExtension();
        $extension->setLogger(new NullLogger());
        $extension->register($this->createStub(ExtensionApiInterface::class));

        $command = new class extends Command {
            public function __construct()
            {
                parent::__construct('cache:clear');
            }
        };

        $extension->onConsoleCommand(new ConsoleCommandEvent(
            $command,
            new ArrayInput([]),
            new NullOutput(),
        ));

        $ref = new \ReflectionProperty($extension, 'started');
        $this->assertFalse($ref->getValue($extension));
    }

    public function testSkipsAgentControllerMode(): void
    {
        $extension = new ObservationalMemoryExtension();
        $extension->setLogger(new NullLogger());
        $api = $this->createStub(ExtensionApiInterface::class);
        $api->method('getCwd')->willReturn(sys_get_temp_dir());
        $api->method('getSettings')->willReturn(['enabled' => true]);
        $extension->register($api);

        $command = new class extends Command {
            public function __construct()
            {
                parent::__construct('agent');
            }
        };

        $extension->onConsoleCommand(new ConsoleCommandEvent(
            $command,
            new ArrayInput(['--controller' => true]),
            new NullOutput(),
        ));

        $ref = new \ReflectionProperty($extension, 'started');
        $this->assertFalse($ref->getValue($extension));
    }
}
