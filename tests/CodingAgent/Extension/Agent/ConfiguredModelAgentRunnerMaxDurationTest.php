<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Agent;

use Ineersa\AgentCore\Contract\Model\ModelResolverInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\Http\RequestScopedHttpClient;
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
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderFactory;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolverInterface;
use Symfony\AI\Platform\Bridge\Generic\Completions\ModelClient as GenericCompletionsModelClient;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thesis: AgentCallRequestDTO::maxDurationSeconds reaches HttpClient transport options only,
 * never the JSON body; default calls keep shared timeout budgets; scopes restore after exceptions.
 */
final class ConfiguredModelAgentRunnerMaxDurationTest extends TestCase
{
    public function testMaxDurationReachesTransportAndLeavesJsonBodyClean(): void
    {
        $seen = [];
        $transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options;

            return new MockResponse(self::streamPayload('budgeted'));
        });
        $runner = $this->createRunner($transport);

        $runner->run(new AgentCallRequestDTO(
            model: 'mock/test',
            sessionId: 'run-budget',
            instructions: 'sys',
            input: 'user',
            maxDurationSeconds: 300,
        ));

        $this->assertCount(1, $seen);
        $this->assertSame(300.0, (float) $seen[0]['max_duration']);
        $this->assertSame(30.0, (float) $seen[0]['timeout']);
        $body = $this->decodeBody($seen[0]);
        $this->assertArrayNotHasKey('max_duration', $body);
        $this->assertArrayNotHasKey('timeout', $body);
        $this->assertTrue($body['stream'] ?? false);
        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertArrayNotHasKey('reasoning', $body);
    }

    public function testDefaultCallKeepsSharedBudgetAndRestoresAfterScopedException(): void
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
            // Scope must leave even when the agent call fails.
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
        $this->assertSame(120.0, (float) ($seen[1]['max_duration'] ?? 0));
        $this->assertNotSame(300.0, (float) ($seen[1]['max_duration'] ?? 0));
        $defaultBody = $this->decodeBody($seen[1]);
        $this->assertArrayNotHasKey('max_duration', $defaultBody);
        $this->assertArrayNotHasKey('timeout', $defaultBody);
    }

    private function createRunner(HttpClientInterface $transport): ConfiguredModelAgentRunner
    {
        $ai = new AiConfig(
            http: new AiHttpConfig(timeout: 30, maxDuration: 120),
            providers: [
                'mock' => new AiProviderConfig(
                    id: 'mock',
                    type: 'openai',
                    enabled: true,
                    baseUrl: 'https://example.test',
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
            ],
        );
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            ai: $ai,
            catalog: new HatfieldModelCatalog($ai),
        );
        $factory = new SymfonyAiProviderFactory(
            $appConfig,
            $this->createStub(EventDispatcherInterface::class),
            [],
            new NullLogger(),
            $transport,
        );
        $providers = $factory->createProviders();
        $this->assertArrayHasKey('mock', $providers);
        $http = $this->extractHttpClient($providers['mock']);
        $this->assertTrue(
            $http instanceof RequestScopedHttpClient || $this->containsRequestScopedClient($http),
            'provider transport must wrap RequestScopedHttpClient',
        );

        $platform = new Platform(array_values($providers));
        $modelResolver = new class implements ModelResolverInterface {
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

        return new ConfiguredModelAgentRunner(
            $platform,
            new HatfieldModelCatalog($ai),
            new NullLogger(),
            $this->createStub(ToolCallArgumentResolverInterface::class),
            $modelResolver,
            new ProviderRequestPreparer(),
        );
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

    private function extractHttpClient(object $provider): HttpClientInterface
    {
        $this->assertInstanceOf(Provider::class, $provider);
        $ref = new \ReflectionClass($provider);
        $prop = $ref->getProperty('modelClients');
        $clients = $prop->getValue($provider);
        $this->assertIsArray($clients);
        $this->assertNotEmpty($clients);
        $modelClient = $clients[0];
        $this->assertInstanceOf(GenericCompletionsModelClient::class, $modelClient);
        $clientProp = new \ReflectionProperty(GenericCompletionsModelClient::class, 'httpClient');
        $http = $clientProp->getValue($modelClient);
        $this->assertInstanceOf(HttpClientInterface::class, $http);

        return $http;
    }

    private function containsRequestScopedClient(HttpClientInterface $http): bool
    {
        $current = $http;
        for ($i = 0; $i < 5; ++$i) {
            if ($current instanceof RequestScopedHttpClient) {
                return true;
            }
            $ref = new \ReflectionObject($current);
            if (!$ref->hasProperty('client')) {
                return false;
            }
            $prop = $ref->getProperty('client');
            $inner = $prop->getValue($current);
            if (!$inner instanceof HttpClientInterface) {
                return false;
            }
            $current = $inner;
        }

        return false;
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
