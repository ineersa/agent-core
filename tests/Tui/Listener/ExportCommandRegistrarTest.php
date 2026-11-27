<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Listener\ExportCommandRegistrar;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Runtime\TuiTickDispatcher;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\SessionEventsExportServiceFactory;
use Ineersa\Tui\Tests\Support\TuiSessionServicesFactoryTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Tui\Tui;

#[CoversClass(ExportCommandRegistrar::class)]
final class ExportCommandRegistrarTest extends TestCase
{
    use TuiSessionServicesFactoryTrait;

    #[Test]
    public function registersExportCommandWithMetadata(): void
    {
        $catalog = new SlashCommandCatalog();
        $registrar = new ExportCommandRegistrar(SessionEventsExportServiceFactory::create());

        $registrar->register($this->createContext($catalog));

        $this->assertTrue($catalog->has('export'), 'Export is a built-in catalog command');
        $this->assertTrue($catalog->has('exp'), 'Alias /exp should be registered');

        $meta = $catalog->getMetadata('export');
        $this->assertNotNull($meta);
        $this->assertSame('export', $meta->name);
        $this->assertContains('exp', $meta->aliases);
        $this->assertTrue($meta->acceptsArguments);
        $this->assertNotEmpty($meta->description);
    }

    #[Test]
    public function repeatsBindingWithoutThrowing(): void
    {
        $catalog = new SlashCommandCatalog();
        $registrar = new ExportCommandRegistrar(SessionEventsExportServiceFactory::create());

        // First session iteration.
        $registrar->register($this->createContext($catalog));
        $this->assertTrue($catalog->has('export'));

        // Second session iteration — must not throw.
        $registrar->register($this->createContext($catalog));
        $this->assertTrue($catalog->has('export'));
    }

    #[Test]
    public function metadataDescriptionContainsExport(): void
    {
        $catalog = new SlashCommandCatalog();
        $registrar = new ExportCommandRegistrar(SessionEventsExportServiceFactory::create());
        $registrar->register($this->createContext($catalog));

        $meta = $catalog->getMetadata('export');
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
    private function createContext(?SlashCommandCatalog $catalog = null): TuiRuntimeContext
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
            dispatcher: new EventDispatcher(), lockFactory: new LockFactory(new FlockStore()));

        return new TuiRuntimeContext(
            tui: $this->createStub(Tui::class),
            client: $this->createStub(AgentSessionClient::class),
            state: $state,
            screen: (new \ReflectionClass(ChatScreen::class))->newInstanceWithoutConstructor(),
            sessionStore: $sessionStore,
            ticks: (new \ReflectionClass(TuiTickDispatcher::class))->newInstanceWithoutConstructor(),
            switch: $this->createStub(TuiSessionSwitchServiceInterface::class),
            lifecycle: (new \ReflectionClass(TuiSessionLifecycleDispatcher::class))->newInstanceWithoutConstructor(),
            historyProvider: $this->createStub(HistoryProviderInterface::class),
            sessionServices: $this->createSessionServices(catalog: $catalog ?? new SlashCommandCatalog()),
        );
    }
}
