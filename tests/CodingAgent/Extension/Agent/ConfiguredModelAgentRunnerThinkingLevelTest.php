<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Agent;

use Ineersa\AgentCore\Contract\Model\ModelResolverInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\Http\RequestScopedHttpClient;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderRequestPreparer;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ReasoningOptionsFeatureShaper;
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
 * Thesis: AgentCallRequestDTO::thinkingLevel=off reaches provider JSON via resolver options,
 * while default/reflector calls omit the disable flag and keep the shared 300s max_duration path intact.
 */
final class ConfiguredModelAgentRunnerThinkingLevelTest extends TestCase
{
    public function testThinkingLevelOffEmitsChatTemplateKwargsAndKeepsMaxDuration(): void
    {
        $seen = [];
        $transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options;

            return new MockResponse(self::streamPayload('dropper'));
        });
        $runner = $this->createRunner($transport, withReasoningDisable: true);

        $runner->run(new AgentCallRequestDTO(
            model: 'llama_cpp/flash',
            sessionId: 'run-dropper',
            instructions: 'sys',
            input: 'user',
            maxDurationSeconds: 300,
            thinkingLevel: 'off',
        ));

        $this->assertCount(1, $seen);
        $this->assertSame(300.0, (float) $seen[0]['max_duration']);
        $this->assertSame(30.0, (float) $seen[0]['timeout']);
        $body = $this->decodeBody($seen[0]);
        $this->assertSame(['enable_thinking' => false], $body['chat_template_kwargs'] ?? null);
        $this->assertArrayNotHasKey('max_duration', $body);
        $this->assertArrayNotHasKey('timeout', $body);
        $this->assertTrue($body['stream'] ?? false);
    }

    public function testDefaultAndReflectorCallsOmitThinkingDisableFlag(): void
    {
        $seen = [];
        $transport = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options;

            return new MockResponse(self::streamPayload('default'));
        });
        $runner = $this->createRunner($transport, withReasoningDisable: true);

        $runner->run(new AgentCallRequestDTO(
            model: 'llama_cpp/flash',
            sessionId: 'run-reflector',
            instructions: 'sys',
            input: 'user',
            maxDurationSeconds: 300,
        ));

        $this->assertCount(1, $seen);
        $this->assertSame(300.0, (float) $seen[0]['max_duration']);
        $body = $this->decodeBody($seen[0]);
        $this->assertArrayNotHasKey('chat_template_kwargs', $body);
        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertArrayNotHasKey('reasoning', $body);
    }

    private function createRunner(HttpClientInterface $transport, bool $withReasoningDisable): ConfiguredModelAgentRunner
    {
        $ai = new AiConfig(
            http: new AiHttpConfig(timeout: 30, maxDuration: 120),
            providers: [
                'llama_cpp' => new AiProviderConfig(
                    id: 'llama_cpp',
                    type: 'generic',
                    enabled: true,
                    baseUrl: 'https://example.test/v1',
                    apiKey: 'test-key',
                    models: [
                        'flash' => new AiModelDefinition(
                            id: 'flash',
                            name: 'flash',
                            toolCalling: true,
                            reasoning: true,
                            contextWindow: 160000,
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
        $this->assertArrayHasKey('llama_cpp', $providers);
        $http = $this->extractHttpClient($providers['llama_cpp']);
        $this->assertTrue(
            $http instanceof RequestScopedHttpClient || $this->containsRequestScopedClient($http),
            'provider transport must wrap RequestScopedHttpClient',
        );

        $platform = new Platform(array_values($providers));
        $modelResolver = new class($withReasoningDisable) implements ModelResolverInterface {
            public function __construct(private readonly bool $withReasoningDisable)
            {
            }

            public function resolve(
                string $defaultModel,
                MessageBag $messages,
                ModelInvocationInput $input,
                ModelResolutionOptions $options,
            ): ResolvedModel {
                $thinkingLevel = $options->values['thinking_level'] ?? null;
                $reasoningOptions = [];
                $compatFeatures = [];
                if ($this->withReasoningDisable && 'off' === $thinkingLevel) {
                    $reasoningOptions = ['chat_template_kwargs' => ['enable_thinking' => false]];
                    $compatFeatures[] = ReasoningOptionsFeatureShaper::FEATURE;
                }

                return new ResolvedModel(
                    model: 'llama_cpp/flash',
                    providerId: 'llama_cpp',
                    reasoning: \is_string($thinkingLevel) ? $thinkingLevel : '',
                    providerOptions: [],
                    compatFeatures: $compatFeatures,
                    reasoningOptions: $reasoningOptions,
                );
            }
        };

        return new ConfiguredModelAgentRunner(
            $platform,
            new HatfieldModelCatalog($ai),
            new NullLogger(),
            $this->createStub(ToolCallArgumentResolverInterface::class),
            $modelResolver,
            new ProviderRequestPreparer(
                hooks: [],
                compatShaper: new \Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderCompatibilityRequestShaper([
                    new ReasoningOptionsFeatureShaper(),
                ]),
            ),
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
