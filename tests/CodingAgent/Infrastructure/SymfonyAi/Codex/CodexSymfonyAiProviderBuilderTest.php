<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\Codex;

use Ineersa\CodingAgent\Auth\CodexAuthStorage;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Codex\CodexSymfonyAiProviderBuilder;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderFactory;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexAuthRecord;
use Symfony\AI\Platform\Bridge\OpenAICodex\Auth\CodexOAuthService;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketConnectionCache;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class CodexSymfonyAiProviderBuilderTest extends TestCase
{
    private CodexAuthStorage $authStorage;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield-factory-test');
        @mkdir($this->tmpDir.'/.hatfield', 0755, true);

        $store = new FlockStore($this->tmpDir);
        $lockFactory = new LockFactory($store);
        $this->authStorage = new CodexAuthStorage($this->tmpDir, $lockFactory);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $path = $this->tmpDir.'/'.CodexAuthStorage::AUTH_FILE;
        if (file_exists($path)) {
            @unlink($path);
        }
        @rmdir($this->tmpDir.'/.hatfield');
        @rmdir($this->tmpDir);
    }

    public function testCodexProviderWithAuthStorageCredentials(): void
    {
        $provider = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
            models: [
                'gpt-5.5' => new AiModelDefinition(
                    id: 'gpt-5.5',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'stored-access-token',
            refresh: 'stored-refresh-token',
            expires: time() + 3600,
            accountId: 'stored-account-id',
        ));

        $factory = $this->createFactory([$provider->id => $provider], $this->authStorage);
        $providers = $factory->createProviders();

        $this->assertArrayHasKey('openai-codex', $providers);
    }

    public function testCodexProviderWithAuthStorageAndEmptyBaseUrl(): void
    {
        $provider = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: '',
            models: [
                'gpt-5.5' => new AiModelDefinition(
                    id: 'gpt-5.5',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'stored-access-token',
            refresh: 'stored-refresh-token',
            expires: time() + 3600,
            accountId: 'stored-account-id',
        ));

        $factory = $this->createFactory([$provider->id => $provider], $this->authStorage);
        $providers = $factory->createProviders();

        $this->assertArrayHasKey('openai-codex', $providers);
    }

    public function testCodexProviderThrowsWhenAuthStorageEmpty(): void
    {
        $provider = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
            models: [
                'gpt-5.5' => new AiModelDefinition(
                    id: 'gpt-5.5',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        $factory = $this->createFactory([$provider->id => $provider], $this->authStorage);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires stored OAuth credentials');

        $factory->createProviders();
    }

    #[DataProvider('authKeyProvider')]
    public function testCodexProviderWithAuthKeyLoadsCorrectCredentials(
        ?string $configAuthKey,
        string $storedKey,
        bool $shouldSucceed,
    ): void {
        $provider = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
            authKey: $configAuthKey,
            models: [
                'gpt-5.5' => new AiModelDefinition(
                    id: 'gpt-5.5',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        // Store credentials under the expected key
        $this->authStorage->saveCredentials($storedKey, new CodexAuthRecord(
            access: 'stored-access-token-'.$storedKey,
            refresh: 'stored-refresh-token',
            expires: time() + 3600,
            accountId: 'stored-account-id',
        ));

        $factory = $this->createFactory([$provider->id => $provider], $this->authStorage);

        if ($shouldSucceed) {
            $providers = $factory->createProviders();
            $this->assertArrayHasKey('openai-codex', $providers);
        } else {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('requires stored OAuth credentials');
            $factory->createProviders();
        }
    }

    /**
     * @return iterable<string, array{0: string|null, 1: string, 2: bool}>
     */
    public static function authKeyProvider(): iterable
    {
        yield 'null auth_key loads from default openai-codex' => [null, 'openai-codex', true];
        yield 'explicit auth_key matches default' => ['openai-codex', 'openai-codex', true];
        yield 'custom auth_key loads from that key' => ['openai-codex-work', 'openai-codex-work', true];
        yield 'custom auth_key with default-key storage fails' => ['openai-codex-work', 'openai-codex', false];
    }

    public function testEmptyAuthKeyFallsBackToDefaultCredentials(): void
    {
        $provider = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
            authKey: '',
            models: [
                'gpt-5.5' => new AiModelDefinition(
                    id: 'gpt-5.5',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        // Store under default key only (empty authKey should fall back to default)
        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'default-access-token',
            refresh: 'stored-refresh-token',
            expires: time() + 3600,
            accountId: 'stored-account-id',
        ));

        $factory = $this->createFactory([$provider->id => $provider], $this->authStorage);
        $providers = $factory->createProviders();

        $this->assertArrayHasKey('openai-codex', $providers);
    }

    public function testMalformedAuthKeyThrowsInvalidKeyError(): void
    {
        $provider = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
            authKey: 'my-custom-key',
            models: [
                'gpt-5.5' => new AiModelDefinition(
                    id: 'gpt-5.5',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'default-access-token',
            refresh: 'stored-refresh-token',
            expires: time() + 3600,
            accountId: 'stored-account-id',
        ));

        $factory = $this->createFactory([$provider->id => $provider], $this->authStorage);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid auth_key');

        $factory->createProviders();
    }

    public function testMissingCredentialsWithProfileAuthKeyShowsProfileHint(): void
    {
        $provider = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
            authKey: 'openai-codex-work',
            models: [
                'gpt-5.5' => new AiModelDefinition(
                    id: 'gpt-5.5',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        // Storage exists but does NOT have credentials for this key
        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'default-access-token',
            refresh: 'stored-refresh-token',
            expires: time() + 3600,
            accountId: 'stored-account-id',
        ));

        $factory = $this->createFactory([$provider->id => $provider], $this->authStorage);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bin/console auth:codex --auth-profile=work');

        $factory->createProviders();
    }

    public function testMissingCredentialsWithDefaultKeyHasNoProfileHint(): void
    {
        $provider = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
            authKey: null,
            models: [
                'gpt-5.5' => new AiModelDefinition(
                    id: 'gpt-5.5',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        // No credentials stored at all
        $factory = $this->createFactory([$provider->id => $provider], $this->authStorage);

        try {
            $factory->createProviders();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('bin/console auth:codex', $e->getMessage());
            $this->assertStringNotContainsString('--auth-profile=', $e->getMessage());
        }
    }

    public function testSupportsCodexTypeOnly(): void
    {
        $builder = new CodexSymfonyAiProviderBuilder(
            $this->createStub(EventDispatcherInterface::class),
            $this->authStorage,
            new CodexOAuthService($this->authStorage),
            new CodexWebSocketConnectionCache(),
        );

        $codex = new AiProviderConfig(id: 'openai-codex', type: 'codex', enabled: true, baseUrl: 'https://example.com');
        $generic = new AiProviderConfig(id: 'deepseek', type: 'generic', enabled: true, baseUrl: 'https://api.deepseek.com');

        $this->assertTrue($builder->supports($codex));
        $this->assertFalse($builder->supports($generic));
    }

    public function testCodexTypeThrowsWithoutAuthStorageViaFactory(): void
    {
        $providerConfig = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires stored OAuth credentials');

        $factory = $this->createFactory(['openai-codex' => $providerConfig]);
        $factory->createProviders();
    }

    public function testCodexTypeThrowsRegardlessOfYamlCredentials(): void
    {
        $providerConfig = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
            apiKey: 'some-access-token',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires stored OAuth credentials');

        $factory = $this->createFactory(['openai-codex' => $providerConfig]);
        $factory->createProviders();
    }

    public function testNullAndBlankTransportDefaultToWebsocketProvider(): void
    {
        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'stored-access-token',
            refresh: 'stored-refresh-token',
            expires: time() + 3600,
            accountId: 'stored-account-id',
        ));

        foreach ([null, '', '   '] as $transport) {
            $provider = new AiProviderConfig(
                id: 'openai-codex',
                type: 'codex',
                enabled: true,
                baseUrl: 'https://chatgpt.com/backend-api',
                transport: $transport,
                models: [
                    'gpt-5.5' => new AiModelDefinition(
                        id: 'gpt-5.5',
                        toolCalling: true,
                        reasoning: true,
                    ),
                ],
            );

            $factory = $this->createFactory([$provider->id => $provider], $this->authStorage);
            $providers = $factory->createProviders();
            $this->assertArrayHasKey('openai-codex', $providers, 'transport='.var_export($transport, true));
        }
    }

    public function testInvalidTransportThrows(): void
    {
        $provider = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
            transport: 'auto',
        );

        $builder = new CodexSymfonyAiProviderBuilder(
            eventDispatcher: $this->createStub(EventDispatcherInterface::class),
            codexAuth: $this->authStorage,
            codexOAuth: new CodexOAuthService($this->authStorage),
            codexWebSocketConnectionCache: new CodexWebSocketConnectionCache(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $builder->build($provider, new MockHttpClient());
    }

    public function testPackageReceivesHatfieldIdentityWithoutInternalWireFields(): void
    {
        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            'stored-access-token', 'stored-refresh-token', time() + 3600, 'stored-account-id',
        ));
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->assertSame('originator: hatfield', $options['normalized_headers']['originator'][0]);
            $this->assertSame('User-Agent: hatfield', $options['normalized_headers']['user-agent'][0]);
            $body = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
            $this->assertArrayNotHasKey('hatfield_run_id', $body);
            $this->assertArrayNotHasKey('hatfield_model_ref', $body);
            $this->assertSame('0194ffff-bbbb-7ccc-8ddd-444444444444', $body['prompt_cache_key']);

            return new MockResponse();
        });
        $builder = new CodexSymfonyAiProviderBuilder(
            eventDispatcher: $this->createStub(EventDispatcherInterface::class),
            codexAuth: $this->authStorage,
            codexOAuth: new CodexOAuthService($this->authStorage),
            codexWebSocketConnectionCache: new CodexWebSocketConnectionCache(),
        );
        $provider = $builder->build(new AiProviderConfig(
            id: 'openai-codex', type: 'codex', enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api', transport: 'sse',
        ), $http);
        $provider->invoke(new CodexModel('gpt-5.5'), new MessageBag(Message::ofUser('hello')), [
            'hatfield_run_id' => 'private-run',
            'hatfield_model_ref' => 'private-model',
            'prompt_cache_key' => '0194ffff-bbbb-7ccc-8ddd-444444444444',
        ]);
        $this->assertSame(1, $http->getRequestsCount());
    }

    /**
     * @param array<string, AiProviderConfig> $providers
     */
    private function createFactory(
        array $providers,
        ?CodexAuthStorage $codexAuth = null,
        ?CodexOAuthService $codexOAuth = null,
    ): SymfonyAiProviderFactory {
        $aiConfig = new AiConfig(
            defaultModel: 'openai-codex/gpt-5.5',
            providers: $providers,
        );

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            catalog: new HatfieldModelCatalog($aiConfig),
        );

        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $storage = $codexAuth ?? $this->authStorage;
        $codexBuilder = new CodexSymfonyAiProviderBuilder(
            eventDispatcher: $eventDispatcher,
            codexAuth: $storage,
            codexOAuth: $codexOAuth ?? new CodexOAuthService($storage),
            codexWebSocketConnectionCache: new CodexWebSocketConnectionCache(),
        );

        return new SymfonyAiProviderFactory(
            appConfig: $appConfig,
            eventDispatcher: $eventDispatcher,
            builders: [$codexBuilder],
        );
    }
}
