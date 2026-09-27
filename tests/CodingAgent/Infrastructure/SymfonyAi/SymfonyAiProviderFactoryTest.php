<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmInvocationCancelScope;
use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiHttpConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ReasoningOptionsResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\OpenCodeGo\OpenCodeGoSymfonyAiProviderBuilder;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ProjectedSymfonyModelCatalog;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderBuilderInterface;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderFactory;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SymfonyAiProviderFactoryTest extends TestCase
{
    // ── Raw stream capture writer (env-gated) ──────────────────────────

    private ?string $savedCaptureEnv = null;
    private ?string $savedCapturePathEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Save env vars so we can restore them in tearDown
        $this->savedCaptureEnv = false !== getenv('HATFIELD_LLM_RAW_STREAM_CAPTURE') ? getenv('HATFIELD_LLM_RAW_STREAM_CAPTURE') : null;
        $this->savedCapturePathEnv = false !== getenv('HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH') ? getenv('HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH') : null;
    }

    protected function tearDown(): void
    {
        // Restore env vars
        if (null !== $this->savedCaptureEnv) {
            putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE='.$this->savedCaptureEnv);
            $_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE'] = $this->savedCaptureEnv;
            $_SERVER['HATFIELD_LLM_RAW_STREAM_CAPTURE'] = $this->savedCaptureEnv;
        } else {
            putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE');
            unset($_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE'], $_SERVER['HATFIELD_LLM_RAW_STREAM_CAPTURE']);
        }
        if (null !== $this->savedCapturePathEnv) {
            putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH='.$this->savedCapturePathEnv);
            $_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH'] = $this->savedCapturePathEnv;
            $_SERVER['HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH'] = $this->savedCapturePathEnv;
        } else {
            putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH');
            unset($_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH'], $_SERVER['HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH']);
        }
        parent::tearDown();
    }

    public function testGenericTypeBuildsProvider(): void
    {
        $appConfig = $this->appConfig();
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $factory = new SymfonyAiProviderFactory($appConfig, $eventDispatcher);

        $providers = $factory->createProviders();

        $this->assertArrayHasKey('deepseek', $providers);
        $this->assertNotNull($providers['deepseek']);

        // Verify the generic path still produces CompletionsModel
        $catalog = $providers['deepseek']->getModelCatalog();
        $model = $catalog->getModel('deepseek/deepseek-v4-pro');
        $this->assertInstanceOf(CompletionsModel::class, $model);
    }

    public function testDisabledCodexProviderIsSkipped(): void
    {
        $providerConfig = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: false,
            baseUrl: 'https://chatgpt.com/backend-api',
        );

        $factory = $this->createFactory(['openai-codex' => $providerConfig]);
        $providers = $factory->createProviders();

        $this->assertArrayNotHasKey('openai-codex', $providers);
    }

    public function testBundledOpenCodeGoRoutesToSubscriptionEndpointWithApiKey(): void
    {
        $home = TestDirectoryIsolation::createProjectTempDir('opencode-go-catalog');
        $originalEnv = getenv('OPENCODE_API_KEY');
        $originalPhpEnv = $_ENV['OPENCODE_API_KEY'] ?? null;
        $originalServerEnv = $_SERVER['OPENCODE_API_KEY'] ?? null;
        try {
            putenv('OPENCODE_API_KEY=test-go-key');
            $_ENV['OPENCODE_API_KEY'] = 'test-go-key';
            $_SERVER['OPENCODE_API_KEY'] = 'test-go-key';
            $catalog = new AiCatalog(\dirname(__DIR__, 4).'/config/ai-catalog.yaml', $home);
            $settings = $catalog->loadProviders()['ai'];
            $settings['providers']['opencode-go']['enabled'] = true;
            $ai = AiConfig::fromArray($settings);
            $urls = [];
            $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$urls): MockResponse {
                $urls[] = $url;
                self::assertSame('POST', $method);
                self::assertSame('Authorization: Bearer test-go-key', $options['normalized_headers']['authorization'][0]);
                self::assertSame('x-opencode-session: run-go-1', $options['normalized_headers']['x-opencode-session'][0]);
                $body = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
                if (str_ends_with($url, '/chat/completions')) {
                    self::assertSame('deepseek-v4.1-flash', $body['model']);
                    self::assertSame('Hi', $body['messages'][0]['content']);
                    self::assertSame(['type' => 'enabled'], $body['thinking']);
                    self::assertSame('low', $body['reasoning_effort']);

                    return new MockResponse('{"choices":[{"message":{"role":"assistant","content":"Hello"},"finish_reason":"stop"}]}');
                }
                self::assertSame('https://opencode.ai/zen/go/v1/responses', $url);
                self::assertSame('muse-spark-1.3-contributor', $body['model']);
                self::assertSame(['effort' => 'high', 'summary' => 'auto'], $body['reasoning']);

                return new MockResponse('{"id":"resp_go","object":"response","status":"completed","output":[{"id":"msg_go","type":"message","role":"assistant","content":[{"type":"output_text","text":"Muse"}]}]}');
            });
            $appConfig = new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                catalog: new HatfieldModelCatalog($ai),
            );
            $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
            $factory = new SymfonyAiProviderFactory(
                $appConfig,
                $eventDispatcher,
                [new OpenCodeGoSymfonyAiProviderBuilder($eventDispatcher, $this->createStub(LoggerInterface::class))],
                httpClient: $http,
            );
            $provider = $factory->createProvider('opencode-go', 10);

            $this->assertInstanceOf(CompletionsModel::class, $provider->getModelCatalog()->getModel('opencode-go/deepseek-v4.1-flash'));
            $this->assertInstanceOf(ResponsesModel::class, $provider->getModelCatalog()->getModel('opencode-go/muse-spark-1.3-contributor'));
            $this->assertInstanceOf(CompletionsModel::class, $provider->getModelCatalog()->getModel('opencode-go/space-bunny-free'));
            $this->assertInstanceOf(CompletionsModel::class, $provider->getModelCatalog()->getModel('opencode-go/longcat-2.5-preview-free'));
            $reasoning = new ReasoningOptionsResolver(new HatfieldModelCatalog($ai));
            $deepseekOptions = $reasoning->resolve(new AiModelReference('opencode-go', 'deepseek-v4.1-flash'), 'low');
            $museOptions = $reasoning->resolve(new AiModelReference('opencode-go', 'muse-spark-1.3-contributor'), 'high');
            LlmInvocationCancelScope::enter($this->createStub(CancellationTokenInterface::class), 'run-go-1');
            try {
                $provider->invoke('opencode-go/deepseek-v4.1-flash', new MessageBag(Message::ofUser('Hi')), $deepseekOptions)->getResult();
                $provider->invoke('opencode-go/muse-spark-1.3-contributor', new MessageBag(Message::ofUser('Hi')), $museOptions)->getResult();
                $this->assertSame([
                    'https://opencode.ai/zen/go/v1/chat/completions',
                    'https://opencode.ai/zen/go/v1/responses',
                ], $urls);
            } finally {
                LlmInvocationCancelScope::leave();
            }
        } finally {
            false === $originalEnv ? putenv('OPENCODE_API_KEY') : putenv('OPENCODE_API_KEY='.$originalEnv);
            if (null === $originalPhpEnv) {
                unset($_ENV['OPENCODE_API_KEY']);
            } else {
                $_ENV['OPENCODE_API_KEY'] = $originalPhpEnv;
            }
            if (null === $originalServerEnv) {
                unset($_SERVER['OPENCODE_API_KEY']);
            } else {
                $_SERVER['OPENCODE_API_KEY'] = $originalServerEnv;
            }
            TestDirectoryIsolation::removeDirectory($home);
        }
    }

    public function testCustomHttpConfigIsAcceptedByFactory(): void
    {
        $deepseek = new AiProviderConfig(
            id: 'deepseek',
            type: 'generic',
            enabled: true,
            baseUrl: 'https://api.deepseek.com',
            apiKey: 'dummy-key',
            models: [
                'deepseek-v4-pro' => new AiModelDefinition(
                    id: 'deepseek-v4-pro',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        $http = new AiHttpConfig(timeout: 15, maxDuration: 60);
        $aiConfig = new AiConfig(
            defaultModel: 'deepseek/deepseek-v4-pro',
            http: $http,
            providers: ['deepseek' => $deepseek],
        );

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            catalog: new HatfieldModelCatalog($aiConfig),
        );

        $factory = new SymfonyAiProviderFactory(
            $appConfig,
            $this->createStub(EventDispatcherInterface::class),
        );

        $providers = $factory->createProviders();
        $this->assertArrayHasKey('deepseek', $providers);
    }

    public function testCaptureDisabledByDefaultDoesNotCreateFile(): void
    {
        // Ensure env is not set
        putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE');
        putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH');
        unset($_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE'], $_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH']);

        $factory = $this->createFactory([
            'test-provider' => new AiProviderConfig(
                id: 'test-provider',
                type: 'generic',
                enabled: true,
                baseUrl: 'https://api.example.com',
                apiKey: null,
            ),
        ]);

        $capture = $this->invokeBuildCaptureListener($factory, 'test-provider');

        $this->assertNull($capture, 'buildCaptureListener should return null when env is not set');
    }

    public function testCaptureEnabledReturnsClosureThatWritesValidJsonl(): void
    {
        $tmpDir = TestDirectoryIsolation::createProjectTempDir('capture-test', 0o750);
        $capturePath = $tmpDir.'/capture.jsonl';

        try {
            putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE=1');
            putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH='.$capturePath);
            $_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE'] = '1';
            $_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH'] = $capturePath;

            $factory = $this->createFactory([
                'test-provider' => new AiProviderConfig(
                    id: 'test-provider',
                    type: 'generic',
                    enabled: true,
                    baseUrl: 'https://api.example.com',
                    apiKey: null,
                ),
            ]);

            $capture = $this->invokeBuildCaptureListener($factory, 'test-provider');

            $this->assertNotNull($capture, 'buildCaptureListener should return a closure when env is set');
            $this->assertFileExists($capturePath, 'Capture file should be created');

            // Check file permissions are restrictive (0600)
            $perms = fileperms($capturePath) & 0o777;
            $this->assertSame(0o600, $perms, 'Capture file should have 0600 permissions');

            // Check directory permissions are restrictive (0700)
            $dirPerms = fileperms($tmpDir) & 0o777;
            $this->assertSame(0o750, $dirPerms, 'Temp dir should have 0750 permissions');
            $captureDirPerms = fileperms(\dirname($capturePath)) & 0o777;
            // The immediate parent is $tmpDir which we set to 0750
            $this->assertSame(0o750, $captureDirPerms);

            // Write sample events through the closure
            $capture('capture_start', -1, ['provider_id' => 'test-provider']);
            $capture('raw_chunk', 0, ['data' => ['choices' => [['delta' => ['content' => 'Hello']]]]]);
            $capture('converted_delta', 0, ['type' => 'TextDelta', 'text' => 'Hello']);
            $capture('capture_end', -1, ['stop_reason' => 'stop']);

            // Read back and validate JSONL
            $lines = file($capturePath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
            $this->assertCount(4, $lines, 'Should have 4 JSONL lines');

            $records = array_map(static fn (string $line) => json_decode($line, true, flags: \JSON_THROW_ON_ERROR), $lines);

            // capture_start
            $this->assertSame('capture_start', $records[0]['event']);
            $this->assertSame('test-provider', $records[0]['provider_id']);
            $this->assertArrayHasKey('timestamp', $records[0]);
            $this->assertSame(-1, $records[0]['ordinal']);

            // raw_chunk
            $this->assertSame('raw_chunk', $records[1]['event']);
            $this->assertSame('test-provider', $records[1]['provider_id']);
            $this->assertSame(0, $records[1]['ordinal']);
            $this->assertArrayHasKey('data', $records[1]);

            // converted_delta
            $this->assertSame('converted_delta', $records[2]['event']);
            $this->assertSame('test-provider', $records[2]['provider_id']);
            $this->assertSame(0, $records[2]['ordinal']);
            $this->assertSame('TextDelta', $records[2]['type']);

            // capture_end
            $this->assertSame('capture_end', $records[3]['event']);
            $this->assertSame('test-provider', $records[3]['provider_id']);
            $this->assertSame('stop', $records[3]['stop_reason']);
        } finally {
            TestDirectoryIsolation::removeDirectory($tmpDir);
        }
    }

    public function testCaptureDisabledWithExplicitZeroDoesNotCreateFile(): void
    {
        $tmpDir = TestDirectoryIsolation::createProjectTempDir('capture-disabled', 0o750);
        $capturePath = $tmpDir.'/capture.jsonl';

        try {
            putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE=0');
            putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH='.$capturePath);
            $_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE'] = '0';
            $_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH'] = $capturePath;

            $factory = $this->createFactory([
                'test-provider' => new AiProviderConfig(
                    id: 'test-provider',
                    type: 'generic',
                    enabled: true,
                    baseUrl: 'https://api.example.com',
                    apiKey: null,
                ),
            ]);

            $capture = $this->invokeBuildCaptureListener($factory, 'test-provider');

            $this->assertNull($capture, 'buildCaptureListener should return null when HATFIELD_LLM_RAW_STREAM_CAPTURE=0');
            $this->assertFileDoesNotExist($capturePath, 'Capture file should not be created when disabled');
        } finally {
            TestDirectoryIsolation::removeDirectory($tmpDir);
        }
    }

    public function testDelegatesToFirstMatchingBuilder(): void
    {
        $sentinel = new Provider('stub-builder', [], [], new ProjectedSymfonyModelCatalog(hatfieldModels: [], modelClass: CompletionsModel::class, providerId: 'stub'));

        $stubBuilder = new class($sentinel) implements SymfonyAiProviderBuilderInterface {
            public function __construct(private readonly ProviderInterface $sentinel)
            {
            }

            public function supports(AiProviderConfig $provider): bool
            {
                return 'custom-builder' === $provider->type;
            }

            public function build(AiProviderConfig $provider, HttpClientInterface $httpClient): ProviderInterface
            {
                return $this->sentinel;
            }
        };

        $providerConfig = new AiProviderConfig(
            id: 'custom',
            type: 'custom-builder',
            enabled: true,
            baseUrl: 'https://api.example.com',
            apiKey: 'key',
            models: [
                'm1' => new AiModelDefinition(id: 'm1', toolCalling: true, reasoning: false),
            ],
        );

        $factory = $this->createFactory(['custom' => $providerConfig], [$stubBuilder]);
        $providers = $factory->createProviders();

        $this->assertSame($sentinel, $providers['custom']);
    }

    public function testInjectedTransportDoesNotStackRetriesWhenAiHttpMaxRetriesConfigured(): void
    {
        $requests = 0;
        $transport = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('rejected', ['http_code' => 400]);
        });
        $builder = $this->createMock(SymfonyAiProviderBuilderInterface::class);
        $builder->method('supports')->willReturn(true);
        $builder->expects($this->once())->method('build')->willReturnCallback(
            function (AiProviderConfig $provider, HttpClientInterface $client): ProviderInterface {
                $response = $client->request('POST', 'https://example.test/responses');
                $this->assertSame(400, $response->getStatusCode());

                return new Provider($provider->id, [], [], new ProjectedSymfonyModelCatalog(hatfieldModels: [], modelClass: CompletionsModel::class, providerId: $provider->id));
            },
        );
        $ai = new AiConfig(
            http: new AiHttpConfig(maxRetries: 2, baseDelayMs: 0),
            providers: ['test' => new AiProviderConfig(id: 'test', type: 'custom', enabled: true)],
        );
        $factory = new SymfonyAiProviderFactory(
            new AppConfig(tui: new TuiConfig(theme: 'cyberpunk'), logging: new LoggingConfig(), ai: $ai, catalog: new HatfieldModelCatalog($ai)),
            $this->createStub(EventDispatcherInterface::class),
            [$builder],
            httpClient: $transport,
        );

        $factory->createProviders();
        // Count transport calls through any decorators: configured retries must not be stacked.
        $this->assertSame(1, $requests);
    }

    /**
     * @param array<string, AiProviderConfig> $providers
     */
    private function createFactory(array $providers, iterable $builders = []): SymfonyAiProviderFactory
    {
        $aiConfig = new AiConfig(
            defaultModel: 'deepseek/deepseek-v4-pro',
            providers: $providers,
        );

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            catalog: new HatfieldModelCatalog($aiConfig),
        );

        return new SymfonyAiProviderFactory(
            $appConfig,
            $this->createStub(EventDispatcherInterface::class),
            $builders,
        );
    }

    /**
     * Invoke private SymfonyAiProviderFactory::buildCaptureListener() via reflection.
     *
     * @return (\Closure(string, int, array<string, mixed>): void)|null
     */
    private function invokeBuildCaptureListener(SymfonyAiProviderFactory $factory, string $providerId): mixed
    {
        $method = new \ReflectionMethod(SymfonyAiProviderFactory::class, 'buildCaptureListener');

        return $method->invoke($factory, $providerId);
    }

    private function appConfig(): AppConfig
    {
        $deepseekConfig = new AiProviderConfig(
            id: 'deepseek',
            type: 'generic',
            enabled: true,
            baseUrl: 'https://api.deepseek.com',
            apiKey: 'dummy-key',
            models: [
                'deepseek-v4-pro' => new AiModelDefinition(
                    id: 'deepseek-v4-pro',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        $aiConfig = new AiConfig(
            defaultModel: 'deepseek/deepseek-v4-pro',
            providers: ['deepseek' => $deepseekConfig],
        );

        return new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            catalog: new HatfieldModelCatalog($aiConfig),
        );
    }
}
