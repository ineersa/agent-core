<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\ConvertToLlmHookInterface;
use Ineersa\AgentCore\Contract\Hook\TransformContextHookInterface;
use Ineersa\AgentCore\Contract\Model\ModelResolverInterface;
use Ineersa\AgentCore\Contract\Model\ProviderRegistryInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationOptions;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\BeforeProviderRequestSubscriber;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ModelResolverRoutingSubscriber;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\PlatformInvocationMetadata;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ProjectedSymfonyModelCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface as SymfonyPlatformInterface;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class PlatformIntegrationTest extends TestCase
{
    public function testPlatformInvokerAppliesHookChainAndInjectsDynamicToolDescriptions(): void
    {
        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: 'run-stage-05',
            status: RunStatus::Running,
            version: 1,
            turnNo: 2,
            lastSeq: 5,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'ping']])],
            activeStepId: 'turn-2-llm-1',
        ), 0);

        $calls = [];

        $transformHook = new class($calls) implements TransformContextHookInterface {
            /** @param list<string> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function transformContext(array $messages, ?CancellationTokenInterface $cancelToken = null): array
            {
                $this->calls[] = 'transform_context';

                return $messages;
            }
        };

        $convertHook = new class($calls) implements ConvertToLlmHookInterface {
            /** @param list<string> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function convertToLlm(array $messages, ?CancellationTokenInterface $cancelToken = null): \Symfony\AI\Platform\Message\MessageBag
            {
                $this->calls[] = 'convert_to_llm';

                return new \Symfony\AI\Platform\Message\MessageBag(\Symfony\AI\Platform\Message\Message::ofUser('converted'));
            }
        };

        $beforeProviderHook = new class($calls) implements BeforeProviderRequestHookInterface {
            /** @param list<string> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function beforeProviderRequest(string $model, array $input, array $options, ?CancellationTokenInterface $cancelToken = null): ?ProviderRequest
            {
                $this->calls[] = 'before_provider_request';

                return new ProviderRequest(
                    model: $model.'-patched',
                    input: $input,
                    options: array_replace($options, ['temperature' => 0.2]),
                );
            }
        };

        $modelResolver = new class implements ModelResolverInterface {
            public function resolve(
                string $defaultModel,
                \Symfony\AI\Platform\Message\MessageBag $messages,
                ModelInvocationInput $input,
                ModelResolutionOptions $options,
            ): ResolvedModel {
                unset($messages, $input, $options);

                return new ResolvedModel(model: $defaultModel.'-resolved', options: ['max_tokens' => 64]);
            }
        };

        $toolbox = new class implements ToolboxInterface {
            public function getTools(): array
            {
                return [new Tool(
                    reference: new ExecutionReference(self::class),
                    name: 'web_search',
                    description: 'Search docs for turn 2',
                    parameters: [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string'],
                        ],
                        'required' => ['query'],
                    ],
                )];
            }

            public function execute(ToolCall $toolCall): ToolResult
            {
                throw new \LogicException('Not used in this test.');
            }
        };

        $modelClient = new FakeSymfonyModelClient(new FakeTokenUsage(promptTokens: 7, completionTokens: 3, totalTokens: 10));
        $platform = $this->createSymfonyPlatform(
            modelClient: $modelClient,
            streamFactory: static fn (): iterable => [
                new TextDelta('Hello'),
                new TextDelta(' world'),
            ],
            beforeProviderRequestHooks: [$beforeProviderHook],
            modelResolver: $modelResolver,
        );

        $adapter = new LlmPlatformAdapter(
            runStore: $runStore,
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor($toolbox),
            platform: $platform,
            transformContextHooks: [$transformHook],
            convertToLlmHooks: [$convertHook],
        );

        $response = $adapter->invoke(new ModelInvocationRequest(
            model: 'gpt-test',
            input: new ModelInvocationInput(
                runId: 'run-stage-05',
                turnNo: 2,
                stepId: 'turn-2-llm-1',
            ),
        ));

        $this->assertSame(['transform_context', 'convert_to_llm', 'before_provider_request'], $calls);
        $this->assertSame('gpt-test-resolved-patched', $modelClient->capturedModel);
        $this->assertTrue($modelClient->capturedOptions['stream']);
        $this->assertSame(64, $modelClient->capturedOptions['max_tokens']);
        $this->assertSame(0.2, $modelClient->capturedOptions['temperature']);
        $this->assertSame('Search docs for turn 2', $modelClient->capturedOptions['tools'][0]['function']['description']);
        $this->assertArrayNotHasKey(PlatformInvocationMetadata::OPTION_KEY, $modelClient->capturedOptions);

        $this->assertSame('Hello world', $response->assistantMessage?->asText());
        $this->assertNull($response->stopReason);
        $this->assertSame(7, $response->usage['input_tokens']);
        $this->assertSame(3, $response->usage['output_tokens']);
        $this->assertSame(10, $response->usage['total_tokens']);
    }

    /**
     * Proves the model catalog handles provider-qualified model names.
     *
     * When the model resolver returns "llama_cpp/flash" and the subscriber
     * sets an explicit provider, the full qualified name reaches the
     * provider's own ProjectedSymfonyModelCatalog.  The catalog must
     * accept "llama_cpp/flash" by looking up the bare name "flash".
     */
    public function testProviderQualifiedModelNameIsResolvedByCatalog(): void
    {
        $eventDispatcher = new EventDispatcher();

        $modelResolver = new class implements ModelResolverInterface {
            public function resolve(
                string $defaultModel,
                \Symfony\AI\Platform\Message\MessageBag $messages,
                ModelInvocationInput $input,
                ModelResolutionOptions $options,
            ): ResolvedModel {
                unset($messages, $input, $options);

                return new ResolvedModel(
                    model: 'llama_cpp/flash',
                    providerId: 'llama_cpp',
                );
            }
        };

        $modelClient = new FakeSymfonyModelClient(new FakeTokenUsage());

        // Use a real ProjectedSymfonyModelCatalog (with the new
        // provider-prefix-aware parseModelName) instead of FallbackModelCatalog.
        // The catalog is seeded with the bare model name "flash" —
        // as production's SymfonyAiProviderFactory does.
        $provider = new Provider(
            name: 'llama_cpp',
            modelClients: [$modelClient],
            resultConverters: [new FakeStreamResultConverter(
                static fn (): iterable => [new TextDelta('response')],
            )],
            modelCatalog: new ProjectedSymfonyModelCatalog([
                'flash' => new \Ineersa\CodingAgent\Config\Ai\AiModelDefinition(
                    id: 'flash',
                    name: 'flash',
                    contextWindow: 8000,
                    maxTokens: 4096,
                    input: ['text'],
                    toolCalling: false,
                    reasoning: false,
                ),
            ]),
            eventDispatcher: $eventDispatcher,
        );

        $providerRegistry = new class($provider) implements ProviderRegistryInterface {
            public function __construct(private Provider $provider)
            {
            }

            public function get(string $id): ?\Symfony\AI\Platform\ProviderInterface
            {
                return 'llama_cpp' === $id ? $this->provider : null;
            }

            public function all(): array
            {
                return ['llama_cpp' => $this->provider];
            }
        };

        $eventDispatcher->addSubscriber(
            new ModelResolverRoutingSubscriber($modelResolver, $providerRegistry),
        );

        $platform = new Platform(
            providers: [$provider],
            eventDispatcher: $eventDispatcher,
        );

        $messageBag = new \Symfony\AI\Platform\Message\MessageBag(\Symfony\AI\Platform\Message\Message::ofUser('Hello'));

        $result = $platform->invoke(
            model: 'llama_cpp/flash',
            input: [
                'message_bag' => $messageBag,
            ],
            options: PlatformInvocationMetadata::inject([], new PlatformInvocationMetadata(
                input: new ModelInvocationInput(),
                cancelToken: new ToggleCancellationToken(),
            )),
        );

        // The key regression assertion: invoke() succeeded — no
        // ModelNotFoundException from the catalog.  The
        // ProjectedSymfonyModelCatalog's parseModelName override
        // accepted "llama_cpp/flash" and resolved it to "flash".
        $this->assertNotNull($result);
    }

    public function testStreamingCancellationReturnsAbortedWithPartialOutput(): void
    {
        $platform = $this->createSymfonyPlatform(
            modelClient: new FakeSymfonyModelClient(new FakeTokenUsage(promptTokens: 11, completionTokens: 4, totalTokens: 15)),
            streamFactory: static fn (): iterable => [
                new TextDelta('A'),
                new TextDelta('B'),
                new TextDelta('C'),
            ],
        );

        $adapter = new LlmPlatformAdapter(
            runStore: new InMemoryRunStore(),
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(),
            platform: $platform,
            transformContextHooks: [],
            convertToLlmHooks: [],
        );

        $response = $adapter->invoke(new ModelInvocationRequest(
            model: 'gpt-test',
            input: new ModelInvocationInput(
                messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'cancel me']])],
            ),
            options: new ModelInvocationOptions(
                cancelToken: new ToggleCancellationToken(),
            ),
        ));

        $this->assertSame('aborted', $response->stopReason);
        $this->assertSame('A', $response->assistantMessage?->asText());
        $this->assertSame(15, $response->usage['total_tokens']);
    }

    /**
     * @param iterable<BeforeProviderRequestHookInterface> $beforeProviderRequestHooks
     * @param \Closure(): iterable<mixed>                  $streamFactory
     */
    private function createSymfonyPlatform(
        FakeSymfonyModelClient $modelClient,
        \Closure $streamFactory,
        iterable $beforeProviderRequestHooks = [],
        ?ModelResolverInterface $modelResolver = null,
    ): SymfonyPlatformInterface {
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new ModelResolverRoutingSubscriber($modelResolver));
        $eventDispatcher->addSubscriber(new BeforeProviderRequestSubscriber($beforeProviderRequestHooks));

        return new Platform(
            providers: [new Provider(
                name: 'fake',
                modelClients: [$modelClient],
                resultConverters: [new FakeStreamResultConverter($streamFactory)],
                modelCatalog: new FallbackModelCatalog(),
                eventDispatcher: $eventDispatcher,
            )],
            eventDispatcher: $eventDispatcher,
        );
    }
}

final class FakeSymfonyModelClient implements ModelClientInterface
{
    public ?string $capturedModel = null;

    /** @var array<string, mixed> */
    public array $capturedOptions = [];

    /** @var array<string, mixed>|string|null */
    public array|string|null $capturedPayload = null;

    public function __construct(
        private readonly TokenUsageInterface $tokenUsage,
    ) {
    }

    public function supports(Model $model): bool
    {
        return true;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        $this->capturedModel = $model->getName();
        $this->capturedPayload = $payload;
        $this->capturedOptions = $options;

        return new InMemoryRawResult(['token_usage' => $this->tokenUsage]);
    }
}

final readonly class FakeStreamResultConverter implements ResultConverterInterface
{
    /**
     * @param \Closure(): iterable<mixed> $streamFactory
     */
    public function __construct(
        private \Closure $streamFactory,
    ) {
    }

    public function supports(Model $model): bool
    {
        return true;
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        unset($result, $options);

        $streamFactory = $this->streamFactory;

        return new StreamResult((static function () use ($streamFactory): \Generator {
            foreach ($streamFactory() as $delta) {
                if ($delta instanceof TextDelta) {
                    yield $delta;
                }
            }
        })());
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return new class implements TokenUsageExtractorInterface {
            public function extract(RawResultInterface $rawResult, array $options = []): ?TokenUsageInterface
            {
                unset($options);

                $data = $rawResult->getData();
                $tokenUsage = $data['token_usage'] ?? null;

                return $tokenUsage instanceof TokenUsageInterface ? $tokenUsage : null;
            }
        };
    }
}

final readonly class FakeTokenUsage implements TokenUsageInterface
{
    public function __construct(
        private ?int $promptTokens = null,
        private ?int $completionTokens = null,
        private ?int $totalTokens = null,
    ) {
    }

    public function getPromptTokens(): ?int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): ?int
    {
        return $this->completionTokens;
    }

    public function getThinkingTokens(): ?int
    {
        return null;
    }

    public function getToolTokens(): ?int
    {
        return null;
    }

    public function getCachedTokens(): ?int
    {
        return null;
    }

    public function getCacheCreationTokens(): ?int
    {
        return null;
    }

    public function getCacheReadTokens(): ?int
    {
        return null;
    }

    public function getRemainingTokens(): ?int
    {
        return null;
    }

    public function getRemainingTokensMinute(): ?int
    {
        return null;
    }

    public function getRemainingTokensMonth(): ?int
    {
        return null;
    }

    public function getTotalTokens(): ?int
    {
        return $this->totalTokens;
    }
}

final class ToggleCancellationToken implements CancellationTokenInterface
{
    private int $checks = 0;

    public function isCancellationRequested(): bool
    {
        ++$this->checks;

        return $this->checks > 1;
    }
}
