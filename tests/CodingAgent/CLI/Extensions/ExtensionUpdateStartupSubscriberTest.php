<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Extensions;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionUpdateStartupSubscriber;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\Exception\ExtensionUpdateException;
use Ineersa\CodingAgent\Extension\ExtensionUpdateService;
use Ineersa\CodingAgent\Tests\Support\ExtensionUpdateFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

// Process isolation is semantic, not synchronization: the startup-once marker
// and PHP's declared extension classes deliberately survive kernel /reload.
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ExtensionUpdateStartupSubscriberTest extends IsolatedKernelTestCase
{
    private ExtensionUpdateFixture $fixture;
    private ExtensionUpdateService $updates;
    private AppConfig $config;
    private string|false $sessionEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = new ExtensionUpdateFixture();
        $this->updates = $this->fixture->service(self::getContainer()->get(SerializerInterface::class), self::getContainer()->get(ValidatorInterface::class));
        $this->config = new AppConfig(tui: new TuiConfig(theme: 'test'), logging: new LoggingConfig(), cwd: $this->fixture->project());
        $this->sessionEnvironment = getenv('HATFIELD_SESSION_ID');
        putenv('HATFIELD_SESSION_ID');
    }

    protected function tearDown(): void
    {
        $this->updates->cleanup();
        $this->fixture->close();
        putenv(false === $this->sessionEnvironment ? 'HATFIELD_SESSION_ID' : 'HATFIELD_SESSION_ID='.$this->sessionEnvironment);
        parent::tearDown();
    }

    public function testYesCompletesBeforeExtensionLoadingAndReloadNeverHotReplacesClasses(): void
    {
        $this->fixture->seed(dependency: true);
        $this->fixture->writePackage('project', '1.1.0', '^1.1');
        $this->fixture->writeDependency('project', '1.1.0');
        $loaded = false;
        $output = $this->dispatch("yes\n", afterGate: function () use (&$loaded): void {
            require $this->fixture->root('project').'/vendor/autoload.php';
            self::assertSame('1.1.0', \constant('HatfieldUpdateFixture\\Version::VALUE'));
            $loaded = true;
        });
        $this->assertTrue($loaded);
        foreach (['test/update-extension', 'test/update-dependency', '1.0.0', '1.1.0', 'composer.lock SHA256', 'install', '--no-plugins', '--no-scripts', $this->fixture->root('project')] as $text) {
            $this->assertStringContainsString($text, $output);
        }
        $command = new Process([$this->fixture->composer, '--working-dir='.$this->fixture->root('project'), 'install', '--no-dev', '--no-interaction', '--no-ansi', '--no-plugins', '--no-scripts']);
        $this->assertStringContainsString($command->getCommandLine(), $output);
        foreach (ExtensionUpdateService::environment($this->fixture->root('project')) as $name => $value) {
            $this->assertStringContainsString($name.'='.escapeshellarg($value), $output);
        }
        $this->fixture->writePackage('project', '1.2.0', '^1.1');
        $this->assertSame('', $this->dispatch(''));
        $this->assertSame('1.1.0', $this->fixture->loadedVersion());
    }

    public function testNoLeavesAllPackagesAndLockfilesUntouchedBeforeContinuing(): void
    {
        $this->fixture->seed('home');
        $this->fixture->seed('project');
        $this->fixture->writeAuthentication();
        $this->fixture->writePackage('home', '1.1.0');
        $this->fixture->writePackage('project', '1.2.0');
        $beforeHome = $this->fixture->snapshot('home');
        $beforeProject = $this->fixture->snapshot();
        $continued = false;
        $output = $this->dispatch("no\n", afterGate: static function () use (&$continued): void { $continued = true; });
        $this->assertTrue($continued);
        $this->assertSame($beforeHome, $this->fixture->snapshot('home'));
        $this->assertSame($beforeProject, $this->fixture->snapshot());
        $this->assertStringContainsString('Updates declined', $output);
        $this->assertStringContainsString('User extensions', $output);
        $this->assertStringContainsString('Project extensions', $output);
        $this->assertStringNotContainsString('fixture-user', $output);
        $this->assertStringNotContainsString('fixture-password', $output);
        $this->assertSame([], glob($this->fixture->directory.'/cache/extension-update-plans/*', \GLOB_ONLYDIR));
    }

    public function testUpToDateDoesNotAskForConsent(): void
    {
        $this->fixture->seed();
        $output = $this->dispatch('');
        $this->assertStringNotContainsString('Apply exactly', $output);
        $this->assertStringNotContainsString('composer.lock SHA256', $output);
    }

    public function testCheckFailureStopsLowerPriorityLoaderAndSessionStartup(): void
    {
        $this->fixture->seed();
        $this->fixture->writePackage('project', '2.0.0');
        $continued = false;
        try {
            $this->expectException(ExtensionUpdateException::class);
            $this->expectExceptionMessage('check failed');
            $this->dispatch("yes\n", afterGate: static function () use (&$continued): void { $continued = true; });
        } finally {
            $this->assertFalse($continued);
        }
    }

    public function testWorkersChildrenAndNoninteractiveInvocationsNeverCheckOrPrompt(): void
    {
        $this->fixture->seed();
        $this->fixture->writePackage('project', '2.0.0'); // Any actual check would fail.
        $this->assertSame('', $this->dispatch('', commandName: 'messenger:consume'));
        $this->assertSame('', $this->dispatch('', options: ['--headless' => true]));
        $this->assertSame('', $this->dispatch('', options: ['--controller' => true]));
        $this->assertSame('', $this->dispatch('', interactive: false));
        putenv('HATFIELD_SESSION_ID=owned-child');
        $this->assertSame('', $this->dispatch(''));
    }

    /** @param array<string, bool> $options */
    private function dispatch(string $answer, string $commandName = 'agent', array $options = [], bool $interactive = true, ?\Closure $afterGate = null): string
    {
        $command = new Command($commandName);
        $command->addOption('headless', mode: InputOption::VALUE_NONE);
        $command->addOption('controller', mode: InputOption::VALUE_NONE);
        $input = new ArrayInput($options, $command->getDefinition());
        $input->setInteractive($interactive);
        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $answer);
        rewind($stream);
        $input->setStream($stream);
        $output = new BufferedOutput();
        $events = new EventDispatcher();
        $events->addSubscriber(new ExtensionUpdateStartupSubscriber($this->updates, $this->config));
        if (null !== $afterGate) {
            // Same priority as the existing ExtensionLoaderSubscriber. Loading
            // before the gate would retain 1.0.0 even after Composer installs 1.1.0.
            $events->addListener(ConsoleEvents::COMMAND, $afterGate, 0);
        }
        try {
            $events->dispatch(new ConsoleCommandEvent($command, $input, $output), ConsoleEvents::COMMAND);

            return $output->fetch();
        } finally {
            fclose($stream);
        }
    }
}
