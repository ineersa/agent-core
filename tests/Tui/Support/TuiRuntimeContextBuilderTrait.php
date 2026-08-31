<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Application\TuiSessionServices;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher;
use Ineersa\Tui\Runtime\TuiTickDispatcher;

/**
 * Trait that provides {@see TuiRuntimeContext} construction with sensible
 * defaults for listener tests.
 *
 * Use in any TestCase:
 *
 *   $context = $this->buildTuiContext()
 *       ->withTui($tui)
 *       ->withState($state)
 *       ->withScreen($screen)
 *       ->build();
 *
 * Callers get defaults for client, sessionStore, switch, ticks, and
 * lifecycle. Override any default via ->with*() before ->build().
 */
trait TuiRuntimeContextBuilderTrait
{
    use TuiSessionServicesFactoryTrait;

    /**
     * Create a builder pre-loaded with sensible defaults.
     */
    private function buildTuiContext(): TuiRuntimeContextBuilder
    {
        return $this->newBuilder();
    }

    private function newBuilder(): TuiRuntimeContextBuilder
    {
        \assert($this instanceof \PHPUnit\Framework\TestCase,
            'TuiRuntimeContextBuilderTrait can only be used in PHPUnit TestCase classes');

        $builder = new TuiRuntimeContextBuilder($this);
        $builder->client = $this->createStub(AgentSessionClient::class);
        $builder->sessionStore = self::createSessionStore($this);
        $builder->switchService = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $builder->ticks = new TuiTickDispatcher();
        $builder->lifecycle = new TuiSessionLifecycleDispatcher();
        $builder->historyProvider = $this->createStub(HistoryProviderInterface::class);
        $builder->sessionServices = $this->createSessionServices();

        return $builder;
    }

    private static function createSessionStore(\PHPUnit\Framework\TestCase $testCase): HatfieldSessionStore
    {
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            cwd: '/tmp',
        );

        return new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $testCase->createStub(EntityManagerInterface::class),
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );
    }
}

/**
 * Builder for TuiRuntimeContext.
 *
 * Instantiated via {@see TuiRuntimeContextBuilderTrait::buildTuiContext()}.
 */
final class TuiRuntimeContextBuilder
{
    /** @internal Set by TuiRuntimeContextBuilderTrait */
    public object $client;
    /** @internal Set by TuiRuntimeContextBuilderTrait */
    public HatfieldSessionStore $sessionStore;
    /** @internal Set by TuiRuntimeContextBuilderTrait */
    public object $switchService;
    /** @internal Set by TuiRuntimeContextBuilderTrait */
    public TuiTickDispatcher $ticks;
    /** @internal Set by TuiRuntimeContextBuilderTrait */
    public TuiSessionLifecycleDispatcher $lifecycle;
    public HistoryProviderInterface $historyProvider;
    /** @internal Set by TuiRuntimeContextBuilderTrait */
    public TuiSessionServices $sessionServices;

    private ?object $tui = null;
    private ?object $state = null;
    private ?object $screen = null;

    public function __construct(
        private readonly \PHPUnit\Framework\TestCase $testCase,
    ) {
    }

    public function withTui(object $tui): self
    {
        $this->tui = $tui;

        return $this;
    }

    public function withState(object $state): self
    {
        $this->state = $state;

        return $this;
    }

    public function withScreen(object $screen): self
    {
        $this->screen = $screen;

        return $this;
    }

    public function withClient(object $client): self
    {
        $this->client = $client;

        return $this;
    }

    public function withSessionStore(HatfieldSessionStore $sessionStore): self
    {
        $this->sessionStore = $sessionStore;

        return $this;
    }

    public function withTicks(TuiTickDispatcher $ticks): self
    {
        $this->ticks = $ticks;

        return $this;
    }

    public function withHistoryProvider(HistoryProviderInterface $historyProvider): self
    {
        $this->historyProvider = $historyProvider;

        return $this;
    }

    public function withSessionServices(TuiSessionServices $sessionServices): self
    {
        $this->sessionServices = $sessionServices;

        return $this;
    }

    public function build(): TuiRuntimeContext
    {
        return new TuiRuntimeContext(
            tui: $this->tui ?? throw new \RuntimeException('TuiRuntimeContextBuilder: withTui() is required'),
            client: $this->client,
            state: $this->state ?? throw new \RuntimeException('TuiRuntimeContextBuilder: withState() is required'),
            screen: $this->screen ?? throw new \RuntimeException('TuiRuntimeContextBuilder: withScreen() is required'),
            sessionStore: $this->sessionStore,
            ticks: $this->ticks,
            switch: $this->switchService,
            lifecycle: $this->lifecycle,
            historyProvider: $this->historyProvider ?? $this->testCase->createStub(HistoryProviderInterface::class),
            sessionServices: $this->sessionServices,
        );
    }
}
