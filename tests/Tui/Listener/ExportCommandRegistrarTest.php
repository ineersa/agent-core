<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Listener\ExportCommandRegistrar;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExportCommandRegistrar::class)]
final class ExportCommandRegistrarTest extends TestCase
{
    #[Test]
    public function registersExportCommandWithMetadata(): void
    {
        $registry = new SlashCommandRegistry();
        $registrar = new ExportCommandRegistrar($registry);

        $this->assertFalse($registry->has('export'), 'Export should not be registered yet');

        $registrar->register($this->createContext());

        $this->assertTrue($registry->has('export'), 'Export should be registered after register()');
        $this->assertTrue($registry->has('exp'), 'Alias /exp should be registered');

        $meta = $registry->getMetadata('export');
        $this->assertNotNull($meta);
        $this->assertSame('export', $meta->name);
        $this->assertContains('exp', $meta->aliases);
        $this->assertTrue($meta->acceptsArguments);
        $this->assertNotEmpty($meta->description);
    }

    #[Test]
    public function registersIdempotentlyWithoutThrowing(): void
    {
        $registry = new SlashCommandRegistry();
        $registrar = new ExportCommandRegistrar($registry);

        // First registration.
        $registrar->register($this->createContext());
        $this->assertTrue($registry->has('export'));

        // Second registration — must not throw.
        $registrar->register($this->createContext());
        $this->assertTrue($registry->has('export'));

        // Third registration — still fine.
        $registrar->register($this->createContext());
        $this->assertTrue($registry->has('export'));
    }

    #[Test]
    public function metadataDescriptionContainsExport(): void
    {
        $registry = new SlashCommandRegistry();
        $registrar = new ExportCommandRegistrar($registry);
        $registrar->register($this->createContext());

        $meta = $registry->getMetadata('export');
        $this->assertNotNull($meta);
        $this->assertStringContainsStringIgnoringCase('export', $meta->description);
    }

    /**
     * Create a minimal TuiRuntimeContext for registrar testing.
     *
     * Several TuiRuntimeContext dependency types are final and cannot be
     * doubled with createStub(). We use uninitialized surrogates via
     * reflection exclusively for tests — not in production code.
     */
    private function createContext(): TuiRuntimeContext
    {
        $state = new TuiSessionState('test-session');

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: sys_get_temp_dir(),
        );
        $sessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(EntityManagerInterface::class),
        );

        return new TuiRuntimeContext(
            tui: $this->createStub(\Symfony\Component\Tui\Tui::class),
            client: $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient::class),
            state: $state,
            screen: (new \ReflectionClass(\Ineersa\Tui\Screen\ChatScreen::class))->newInstanceWithoutConstructor(),
            sessionStore: $sessionStore,
            ticks: (new \ReflectionClass(\Ineersa\Tui\Runtime\TuiTickDispatcher::class))->newInstanceWithoutConstructor(),
            switch: $this->createStub(\Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface::class),
            lifecycle: (new \ReflectionClass(\Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher::class))->newInstanceWithoutConstructor(),
            turnTreeProvider: $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface::class),
        );
    }
}
