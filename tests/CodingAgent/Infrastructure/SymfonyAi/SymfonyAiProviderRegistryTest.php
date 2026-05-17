<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderFactory;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class SymfonyAiProviderRegistryTest extends TestCase
{
    private AppConfig $appConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->appConfig = new AppConfig(
            tui: TuiConfig::fromArray(['theme' => 'cyberpunk']),
        );
    }

    public function testGetReturnsNullForUnknownProvider(): void
    {
        $factory = new EmptyStubFactory($this->appConfig, $this->createStub(EventDispatcherInterface::class));
        $registry = new SymfonyAiProviderRegistry($factory);

        self::assertNull($registry->get('nonexistent'));
    }

    public function testGetReturnsProviderById(): void
    {
        $provider = $this->createStub(ProviderInterface::class);
        $factory = new FixedProviderFactory(
            $this->appConfig,
            $this->createStub(EventDispatcherInterface::class),
            ['deepseek' => $provider],
        );

        $registry = new SymfonyAiProviderRegistry($factory);

        self::assertSame($provider, $registry->get('deepseek'));
    }

    public function testAllReturnsAllProviders(): void
    {
        $deepseek = $this->createStub(ProviderInterface::class);
        $zai = $this->createStub(ProviderInterface::class);
        $factory = new FixedProviderFactory(
            $this->appConfig,
            $this->createStub(EventDispatcherInterface::class),
            ['deepseek' => $deepseek, 'zai' => $zai],
        );

        $registry = new SymfonyAiProviderRegistry($factory);

        $all = $registry->all();
        self::assertCount(2, $all);
        self::assertSame($deepseek, $all['deepseek']);
        self::assertSame($zai, $all['zai']);
    }

    public function testLazyInitializationOnlyCallsFactoryOnce(): void
    {
        $factory = new CountingCallsFactory($this->appConfig, $this->createStub(EventDispatcherInterface::class));

        $registry = new SymfonyAiProviderRegistry($factory);
        $registry->all();
        $registry->all();
        $registry->get('anything');

        self::assertSame(1, $factory->invokedCount);
    }
}

/**
 * @internal Test helper — extends SymfonyAiProviderFactory to return empty providers.
 */
final class EmptyStubFactory extends SymfonyAiProviderFactory
{
    public function __construct(AppConfig $appConfig, EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($appConfig, $eventDispatcher);
    }

    public function createProviders(): array
    {
        return [];
    }
}

/**
 * @internal Test helper — extends SymfonyAiProviderFactory to return fixed providers.
 */
final class FixedProviderFactory extends SymfonyAiProviderFactory
{
    /** @param array<string, ProviderInterface> $providers */
    public function __construct(
        AppConfig $appConfig,
        EventDispatcherInterface $eventDispatcher,
        private readonly array $providers,
    ) {
        parent::__construct($appConfig, $eventDispatcher);
    }

    public function createProviders(): array
    {
        return $this->providers;
    }
}

/**
 * @internal Test helper — extends SymfonyAiProviderFactory to count calls.
 */
final class CountingCallsFactory extends SymfonyAiProviderFactory
{
    public int $invokedCount = 0;

    public function __construct(AppConfig $appConfig, EventDispatcherInterface $eventDispatcher)
    {
        parent::__construct($appConfig, $eventDispatcher);
    }

    public function createProviders(): array
    {
        ++$this->invokedCount;

        return [];
    }
}
