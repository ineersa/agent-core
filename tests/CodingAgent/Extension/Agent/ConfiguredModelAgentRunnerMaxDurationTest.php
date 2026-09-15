<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Agent;

use Ineersa\AgentCore\Contract\Model\ModelResolverInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderRequestPreparer;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiHttpConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\Agent\ConfiguredModelAgentRunner;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ConfiguredSymfonyAiPlatformFactory;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderFactory;
use Ineersa\CodingAgent\Tool\RawAwareToolCallArgumentResolver;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolver;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Platform;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thesis: AgentCallRequestDTO::maxDurationSeconds builds a selected-provider Platform
 * with matching idle timeout and max_duration transport options, never JSON body keys;
 * default calls keep shared budgets after budgeted success/failure.
 */
final class ConfiguredModelAgentRunnerMaxDurationTest extends TestCase
{
    public function testMaxDurationReachesTransportAndLeavesJsonBodyClean(): void
    {
        $seen = [];
        $runner = $this->createRunner($this->captureTransport($seen));

        $runner->run(new AgentCallRequestDTO(
            model: 'mock/test',
            sessionId: 'run-budget',
            instructions: 'sys',
            input: 'user',
            maxDurationSeconds: 300,
        ));

        $this->assertCount(1, $seen);
        $this->assertSame(300.0, (float) $seen[0]['max_duration']);
        $this->assertSame(300.0, (float) $seen[0]['timeout']);
        $body = $this->decodeBody($seen[0]);
        $this->assertArrayNotHasKey('max_duration', $body);
        $this->assertArrayNotHasKey('timeout', $body);
        $this->assertTrue($body['stream'] ?? false);
        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertArrayNotHasKey('reasoning', $body);
    }

    public function testDefaultCallKeepsSharedBudgetAfterBudgetedFailure(): void
    {
        $seen = [];
        $transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options;
            if (1 === \count($seen)) {
                return new MockResponse('provider boom', ['http_code' => 500]);
            }

            return new MockResponse(self::streamPayload('default'));
        });
        $runner = $this->createRunner($transport);

        $failure = null;
        try {
            $runner->run(new AgentCallRequestDTO(
                model: 'mock/test',
                sessionId: 'run-fail',
                instructions: 'sys',
                input: 'user',
                maxDurationSeconds: 300,
            ));
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        $this->assertInstanceOf(\Symfony\AI\Platform\Exception\ServerException::class, $failure);

        $runner->run(new AgentCallRequestDTO(
            model: 'mock/test',
            sessionId: 'run-default',
            instructions: 'sys',
            input: 'user',
        ));

        $this->assertCount(2, $seen);
        $this->assertSame(300.0, (float) $seen[0]['max_duration']);
        $this->assertSame(300.0, (float) $seen[0]['timeout']);
        $this->assertSame(120.0, (float) ($seen[1]['max_duration'] ?? 0));
        $this->assertSame(30.0, (float) ($seen[1]['timeout'] ?? 0));
        $defaultBody = $this->decodeBody($seen[1]);
        $this->assertArrayNotHasKey('max_duration', $defaultBody);
        $this->assertArrayNotHasKey('timeout', $defaultBody);
    }

    public function testBudgetedPlatformIsBuiltOncePerAgentRun(): void
    {
        $seen = [];
        $builds = 0;
        $transport = $this->captureTransport($seen);
        $fixture = $this->createFixture($transport);
        $counting = new class($fixture['providerFactory'], $builds) extends SymfonyAiProviderFactory {
            public function __construct(
                private readonly SymfonyAiProviderFactory $inner,
                private int &$builds,
            ) {
            }

            public function createProviders(): array
            {
                return $this->inner->createProviders();
            }

            public function createProvider(
                string $providerId,
                ?int $timeoutSeconds = null,
                ?int $maxDurationSeconds = null,
            ): \Symfony\AI\Platform\ProviderInterface {
                ++$this->builds;

                return $this->inner->createProvider($providerId, $timeoutSeconds, $maxDurationSeconds);
            }
        };
        $platformFactory = new ConfiguredSymfonyAiPlatformFactory($counting, $fixture['dispatcher']);
        $runner = new ConfiguredModelAgentRunner(
            $fixture['sharedPlatform'],
            $platformFactory,
            $fixture['catalog'],
            new NullLogger(),
            new RawAwareToolCallArgumentResolver(new ToolCallArgumentResolver()),
            $this->modelResolver(),
            new ProviderRequestPreparer(),
        );

        $runner->run(new AgentCallRequestDTO(
            model: 'mock/test',
            sessionId: 'run-once',
            instructions: 'sys',
            input: 'user',
            maxDurationSeconds: 300,
        ));

        $this->assertSame(1, $builds);
        $this->assertCount(1, $seen);
        $this->assertSame(300.0, (float) $seen[0]['max_duration']);
        $this->assertSame(300.0, (float) $seen[0]['timeout']);
    }

    public function testSelectedProviderConstructionOnlyBuildsRequestedProvider(): void
    {
        $seenProviders = [];
        $transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seenProviders): MockResponse {
            $seenProviders[] = $url;

            return new MockResponse(self::streamPayload('budgeted'));
        });
        $runner = $this->createRunner($transport, includeExtraProvider: true);

        $runner->run(new AgentCallRequestDTO(
            model: 'mock/test',
            sessionId: 'run-selected',
            instructions: 'sys',
            input: 'user',
            maxDurationSeconds: 300,
        ));

        $this->assertCount(1, $seenProviders);
        $this->assertStringContainsString('mock.example.test', $seenProviders[0]);
        $this->assertStringNotContainsString('other.example.test', $seenProviders[0]);
    }

    /**
     * @param list<array<string, mixed>> $seen
     */
    private function captureTransport(array &$seen): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options;

            return new MockResponse(self::streamPayload('budgeted'));
        });
    }

    private function createRunner(
        HttpClientInterface $transport,
        bool $includeExtraProvider = false,
    ): ConfiguredModelAgentRunner {
        $fixture = $this->createFixture($transport, $includeExtraProvider);

        return new ConfiguredModelAgentRunner(
            $fixture['sharedPlatform'],
            $fixture['platformFactory'],
            $fixture['catalog'],
            new NullLogger(),
            new RawAwareToolCallArgumentResolver(new ToolCallArgumentResolver()),
            $this->modelResolver(),
            new ProviderRequestPreparer(),
        );
    }

    /**
     * @return array{
     *     providerFactory: SymfonyAiProviderFactory,
     *     platformFactory: ConfiguredSymfonyAiPlatformFactory,
     *     sharedPlatform: Platform,
     *     catalog: HatfieldModelCatalog,
     *     dispatcher: EventDispatcherInterface
     * }
     */
    private function createFixture(
        HttpClientInterface $transport,
        bool $includeExtraProvider = false,
    ): array {
        $providers = [
            'mock' => new AiProviderConfig(
                id: 'mock',
                type: 'openai',
                enabled: true,
                baseUrl: 'https://mock.example.test',
                apiKey: 'test-key',
                models: [
                    'test' => new AiModelDefinition(
                        id: 'test',
                        name: 'Test',
                        toolCalling: true,
                        reasoning: false,
                        contextWindow: 8192,
                    ),
                ],
            ),
        ];
        if ($includeExtraProvider) {
            $providers['other'] = new AiProviderConfig(
                id: 'other',
                type: 'openai',
                enabled: true,
                baseUrl: 'https://other.example.test',
                apiKey: 'other-key',
                models: [
                    'test' => new AiModelDefinition(
                        id: 'test',
                        name: 'Other',
                        toolCalling: true,
                        reasoning: false,
                        contextWindow: 8192,
                    ),
                ],
            );
        }

        $ai = new AiConfig(
            http: new AiHttpConfig(timeout: 30, maxDuration: 120),
            providers: $providers,
        );
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            ai: $ai,
            catalog: new HatfieldModelCatalog($ai),
        );
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $providerFactory = new SymfonyAiProviderFactory(
            $appConfig,
            $dispatcher,
            [],
            new NullLogger(),
            $transport,
        );
        $platformFactory = new ConfiguredSymfonyAiPlatformFactory($providerFactory, $dispatcher);

        return [
            'providerFactory' => $providerFactory,
            'platformFactory' => $platformFactory,
            'sharedPlatform' => new Platform(array_values($providerFactory->createProviders())),
            'catalog' => new HatfieldModelCatalog($ai),
            'dispatcher' => $dispatcher,
        ];
    }

    private function modelResolver(): ModelResolverInterface
    {
        return new class implements ModelResolverInterface {
            public function resolve(
                string $defaultModel,
                MessageBag $messages,
                ModelInvocationInput $input,
                ModelResolutionOptions $options,
            ): ResolvedModel {
                return new ResolvedModel(
                    model: 'mock/test',
                    providerId: 'mock',
                    reasoning: '',
                    providerOptions: [],
                    compatFeatures: [],
                    reasoningOptions: [],
                );
            }
        };
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function decodeBody(array $options): array
    {
        $body = $options['body'] ?? null;
        if (\is_string($body)) {
            $decoded = json_decode($body, true);
            $this->assertIsArray($decoded);

            return $decoded;
        }
        if (\is_array($options['json'] ?? null)) {
            return $options['json'];
        }

        $this->fail('expected JSON request body');
    }

    private static function streamPayload(string $content): string
    {
        $chunk = json_encode([
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion.chunk',
            'choices' => [[
                'index' => 0,
                'delta' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => null,
            ]],
        ], \JSON_THROW_ON_ERROR);
        $done = json_encode([
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion.chunk',
            'choices' => [[
                'index' => 0,
                'delta' => new \stdClass(),
                'finish_reason' => 'stop',
            ]],
        ], \JSON_THROW_ON_ERROR);

        return "data: {$chunk}\n\ndata: {$done}\n\ndata: [DONE]\n\n";
    }
}
