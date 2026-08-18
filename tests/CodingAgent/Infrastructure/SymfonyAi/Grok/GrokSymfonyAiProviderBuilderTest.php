<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\Grok;

use Ineersa\CodingAgent\Auth\GrokAuthRecord;
use Ineersa\CodingAgent\Auth\GrokAuthStorage;
use Ineersa\CodingAgent\Auth\GrokOAuthConfig;
use Ineersa\CodingAgent\Auth\GrokOAuthService;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Grok\GrokSymfonyAiProviderBuilder;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderFactory;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class GrokSymfonyAiProviderBuilderTest extends TestCase
{
    private GrokAuthStorage $authStorage;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield-grok-factory-test');
        @mkdir($this->tmpDir.'/.hatfield', 0755, true);

        $store = new FlockStore($this->tmpDir);
        $lockFactory = new LockFactory($store);
        $this->authStorage = new GrokAuthStorage($this->tmpDir, $lockFactory);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testSupportsGrokTypeOnly(): void
    {
        $builder = new GrokSymfonyAiProviderBuilder(
            $this->createStub(EventDispatcherInterface::class),
            $this->authStorage,
            new GrokOAuthService($this->authStorage),
        );

        $grok = new AiProviderConfig(id: 'grok-cli', type: 'grok', enabled: true, baseUrl: 'https://cli-chat-proxy.grok.com');
        $generic = new AiProviderConfig(id: 'deepseek', type: 'generic', enabled: true, baseUrl: 'https://api.deepseek.com');
        $codex = new AiProviderConfig(id: 'openai-codex', type: 'codex', enabled: true, baseUrl: 'https://chatgpt.com/backend-api');

        $this->assertTrue($builder->supports($grok));
        $this->assertFalse($builder->supports($generic));
        $this->assertFalse($builder->supports($codex));
    }

    public function testMissingCredentialsThrowsWithAuthGrokHint(): void
    {
        $provider = new AiProviderConfig(
            id: 'grok-cli',
            type: 'grok',
            enabled: true,
            baseUrl: 'https://cli-chat-proxy.grok.com',
            models: [
                'grok-composer-2.5-fast' => new AiModelDefinition(
                    id: 'grok-composer-2.5-fast',
                    toolCalling: true,
                ),
            ],
        );

        $factory = $this->createFactory([$provider->id => $provider]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bin/console auth:grok');

        $factory->createProviders();
    }

    public function testHappyPathBuildsProvider(): void
    {
        $provider = new AiProviderConfig(
            id: 'grok-cli',
            type: 'grok',
            enabled: true,
            baseUrl: 'https://cli-chat-proxy.grok.com',
            models: [
                'grok-composer-2.5-fast' => new AiModelDefinition(
                    id: 'grok-composer-2.5-fast',
                    toolCalling: true,
                    reasoning: false,
                ),
            ],
        );

        $this->authStorage->saveCredentials(GrokOAuthConfig::PROVIDER_KEY, new GrokAuthRecord(
            access: 'stored-access-token',
            refresh: 'stored-refresh-token',
            expires: time() + 3600,
        ));

        $factory = $this->createFactory([$provider->id => $provider]);
        $providers = $factory->createProviders();

        $this->assertArrayHasKey('grok-cli', $providers);
    }

    public function testBuildUsesDefaultBaseUrlWhenEmpty(): void
    {
        $provider = new AiProviderConfig(
            id: 'grok-cli',
            type: 'grok',
            enabled: true,
            baseUrl: '',
            models: [
                'grok-build' => new AiModelDefinition(id: 'grok-build', toolCalling: true, reasoning: true),
            ],
        );

        $this->authStorage->saveCredentials(GrokOAuthConfig::PROVIDER_KEY, new GrokAuthRecord(
            access: 'stored-access-token',
            refresh: 'stored-refresh-token',
            expires: time() + 3600,
        ));

        $builder = new GrokSymfonyAiProviderBuilder(
            $this->createStub(EventDispatcherInterface::class),
            $this->authStorage,
            new GrokOAuthService($this->authStorage),
        );

        $built = $builder->build($provider, new MockHttpClient());
        $this->assertSame('grok-cli', $built->getName());
    }

    /**
     * @param array<string, AiProviderConfig> $providers
     */
    private function createFactory(array $providers): SymfonyAiProviderFactory
    {
        $aiConfig = new AiConfig(
            defaultModel: 'grok-cli/grok-composer-2.5-fast',
            providers: $providers,
        );

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            catalog: new HatfieldModelCatalog($aiConfig),
        );

        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $grokBuilder = new GrokSymfonyAiProviderBuilder(
            eventDispatcher: $eventDispatcher,
            grokAuth: $this->authStorage,
            grokOAuth: new GrokOAuthService($this->authStorage),
        );

        return new SymfonyAiProviderFactory(
            appConfig: $appConfig,
            eventDispatcher: $eventDispatcher,
            builders: [$grokBuilder],
        );
    }
}
