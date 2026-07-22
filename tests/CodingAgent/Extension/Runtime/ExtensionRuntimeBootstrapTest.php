<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Runtime;

use Ineersa\CodingAgent\CLI\ExtensionRunCommand;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ExtensionsConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\CodingAgent\Tests\Extension\InMemoryExtensionApiBridge;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use Ineersa\Hatfield\ExtensionApi\Runtime\ExtensionEntrypointInterface;
use Ineersa\Hatfield\ExtensionApi\Runtime\RuntimeStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Runtime\RuntimeStoppingEvent;
use Monolog\Level;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Thesis: ExtensionManager registers EventSubscriberInterface extensions on the
 * host dispatcher, and extension:run invokes a loaded entrypoint with the
 * process-local ExtensionApi — without OM-specific host knowledge.
 */
final class ExtensionRuntimeBootstrapTest extends TestCase
{
    public function testManagerRegistersEventSubscriberAndExposesLoadedExtension(): void
    {
        $dispatcher = new EventDispatcher();
        $bridge = new InMemoryExtensionApiBridge('/tmp/project');
        $config = $this->configWithEnabled([TestRuntimeExtension::class]);

        $manager = new ExtensionManager($config, $bridge, new NullLogger(), $dispatcher);
        $diagnostics = $manager->loadExtensions();

        $this->assertSame([], $diagnostics);
        $this->assertInstanceOf(TestRuntimeExtension::class, $manager->getLoadedExtension(TestRuntimeExtension::class));

        $dispatcher->dispatch(new RuntimeStartedEvent(
            sessionId: 'sess-1',
            runtimeCwd: '/tmp/project',
            applicationCommand: [\PHP_BINARY, '/tmp/bin/console'],
            executablePath: '/tmp/bin/console',
        ));
        $dispatcher->dispatch(new RuntimeStoppingEvent(
            sessionId: 'sess-1',
            runtimeCwd: '/tmp/project',
        ));

        $extension = $manager->getLoadedExtension(TestRuntimeExtension::class);
        $this->assertInstanceOf(TestRuntimeExtension::class, $extension);
        $this->assertTrue($extension->started);
        $this->assertTrue($extension->stopped);
    }

    public function testExtensionRunCommandInvokesEntrypoint(): void
    {
        $dispatcher = new EventDispatcher();
        $bridge = new InMemoryExtensionApiBridge('/tmp/project');
        $config = $this->configWithEnabled([TestRuntimeExtension::class]);
        $manager = new ExtensionManager($config, $bridge, new NullLogger(), $dispatcher);
        $manager->loadExtensions();

        $command = new ExtensionRunCommand($manager, $bridge, new NullLogger());
        $exit = $command(TestRuntimeExtension::class, 'probe');

        $this->assertSame(Command::SUCCESS, $exit);
        $extension = $manager->getLoadedExtension(TestRuntimeExtension::class);
        $this->assertInstanceOf(TestRuntimeExtension::class, $extension);
        $this->assertTrue($extension->entrypointRan);
        $this->assertSame($bridge, $extension->entrypointApi);
    }

    public function testExtensionRunCommandRejectsUnknownEntrypoint(): void
    {
        $dispatcher = new EventDispatcher();
        $bridge = new InMemoryExtensionApiBridge('/tmp/project');
        $config = $this->configWithEnabled([TestRuntimeExtension::class]);
        $manager = new ExtensionManager($config, $bridge, new NullLogger(), $dispatcher);
        $manager->loadExtensions();

        $command = new ExtensionRunCommand($manager, $bridge, new NullLogger());
        $exit = $command(TestRuntimeExtension::class, 'missing');

        $this->assertSame(Command::FAILURE, $exit);
    }

    /**
     * @param list<class-string> $enabled
     */
    private function configWithEnabled(array $enabled): AppConfig
    {
        return new AppConfig(
            tui: new TuiConfig(theme: 'default', themePaths: []),
            logging: new LoggingConfig(logDir: '/tmp/project/var/tmp', level: Level::Warning, maxFiles: 7),
            extensions: new ExtensionsConfig(enabled: $enabled, settings: []),
            cwd: '/tmp/project',
        );
    }
}

/**
 * Test-local extension implementing the public runtime contracts.
 */
final class TestRuntimeExtension implements HatfieldExtensionInterface, ExtensionEntrypointInterface, EventSubscriberInterface
{
    public bool $started = false;

    public bool $stopped = false;

    public bool $entrypointRan = false;

    public ?ExtensionApiInterface $entrypointApi = null;

    public function register(ExtensionApiInterface $api): void
    {
    }

    public static function entrypoints(): array
    {
        return ['probe'];
    }

    public function runEntrypoint(string $entrypoint, ExtensionApiInterface $api): int
    {
        if ('probe' !== $entrypoint) {
            return Command::FAILURE;
        }

        $this->entrypointRan = true;
        $this->entrypointApi = $api;

        return Command::SUCCESS;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            RuntimeStartedEvent::class => 'onStarted',
            RuntimeStoppingEvent::class => 'onStopping',
        ];
    }

    public function onStarted(RuntimeStartedEvent $event): void
    {
        $this->started = 'sess-1' === $event->sessionId;
    }

    public function onStopping(RuntimeStoppingEvent $event): void
    {
        $this->stopped = 'sess-1' === $event->sessionId;
    }
}
