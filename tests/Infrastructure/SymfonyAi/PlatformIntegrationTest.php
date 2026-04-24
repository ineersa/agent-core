<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Application\Handler\ToolCatalogResolver;
use Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\ConvertToLlmHookInterface;
use Ineersa\AgentCore\Contract\Hook\TransformContextHookInterface;
use Ineersa\AgentCore\Contract\Tool\ModelResolverInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCatalogProviderInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\MessageBag;
use Ineersa\AgentCore\Domain\Tool\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Tool\ModelResolutionContext;
use Ineersa\AgentCore\Domain\Tool\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ProviderRequest;
use Ineersa\AgentCore\Domain\Tool\ResolvedModel;
use Ineersa\AgentCore\Domain\Tool\ToolCatalogContext;
use Ineersa\AgentCore\Domain\Tool\ToolDefinition;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\Platform;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\SymfonyMessageMapper;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\SymfonyPlatformInvoker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

final class PlatformIntegrationTest extends TestCase
{
    public function testPlatformInvokerAppliesHookChainAndInjectsDynamicToolDescriptions(): void
    {
        $platformClient = new FakeSymfonyPlatform(
            streamFactory: static fn (): iterable => [
                new TextDelta('Hello'),
                new TextDelta(' world'),
            ],
            metadata: new FakeMetadata([
                'token_usage' => new FakeTokenUsage(promptTokens: 7, completionTokens: 3, totalTokens: 10),
            ]),
        );

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

            public function convertToLlm(array $messages, ?CancellationTokenInterface $cancelToken = null): MessageBag
            {
                $this->calls[] = 'convert_to_llm';

                return new MessageBag([
                    (object) [
                        'role' => 'user',
                        'content' => 'converted',
                    ],
                ]);
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
                MessageBag $messages,
                ModelResolutionContext $context,
                ModelResolutionOptions $options,
            ): ResolvedModel {
                unset($messages, $context, $options);

                return new ResolvedModel($defaultModel.'-resolved', ['max_tokens' => 64]);
            }
        };

        $toolProvider = new class implements ToolCatalogProviderInterface {
            public function resolveToolCatalog(ToolCatalogContext $context): array
            {
                return [new ToolDefinition(
                    name: 'web_search',
                    description: sprintf('Search docs for turn %d', $context->turnNo ?? 0),
                    schema: [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string'],
                        ],
                        'required' => ['query'],
                    ],
                )];
            }
        };

        $platform = new Platform(
            invoker: new SymfonyPlatformInvoker($platformClient),
            runStore: $runStore,
            toolCatalogResolver: new ToolCatalogResolver([$toolProvider], new ObjectNormalizer()),
            messageMapper: new SymfonyMessageMapper(),
            transformContextHooks: [$transformHook],
            convertToLlmHooks: [$convertHook],
            beforeProviderRequestHooks: [$beforeProviderHook],
            modelResolver: $modelResolver,
            defaultModel: 'gpt-test',
        );

        $response = $platform->invoke(new ModelInvocationRequest(
            model: 'default',
            input: [
                'run_id' => 'run-stage-05',
                'turn_no' => 2,
                'step_id' => 'turn-2-llm-1',
            ],
        ));

        self::assertSame(['transform_context', 'convert_to_llm', 'before_provider_request'], $calls);
        self::assertSame('gpt-test-resolved-patched', $platformClient->capturedModel);
        self::assertTrue($platformClient->capturedOptions['stream']);
        self::assertSame(64, $platformClient->capturedOptions['max_tokens']);
        self::assertSame(0.2, $platformClient->capturedOptions['temperature']);
        self::assertSame('Search docs for turn 2', $platformClient->capturedOptions['tools'][0]['function']['description']);

        self::assertSame('assistant', $response->assistantMessage['role']);
        self::assertSame('Hello world', $response->assistantMessage['content'][0]['text']);
        self::assertNull($response->stopReason);
        self::assertSame(7, $response->usage['input_tokens']);
        self::assertSame(3, $response->usage['output_tokens']);
        self::assertSame(10, $response->usage['total_tokens']);
    }

    public function testStreamingCancellationReturnsAbortedWithPartialOutput(): void
    {
        $platformClient = new FakeSymfonyPlatform(
            streamFactory: static fn (): iterable => [
                new TextDelta('A'),
                new TextDelta('B'),
                new TextDelta('C'),
            ],
            metadata: new FakeMetadata([
                'token_usage' => new FakeTokenUsage(promptTokens: 11, completionTokens: 4, totalTokens: 15),
            ]),
        );

        $platform = new Platform(
            invoker: new SymfonyPlatformInvoker($platformClient),
            runStore: new InMemoryRunStore(),
            toolCatalogResolver: new ToolCatalogResolver([], new ObjectNormalizer()),
            messageMapper: new SymfonyMessageMapper(),
            transformContextHooks: [],
            convertToLlmHooks: [],
            beforeProviderRequestHooks: [],
            modelResolver: null,
            defaultModel: 'gpt-test',
        );

        $response = $platform->invoke(new ModelInvocationRequest(
            model: 'default',
            input: [
                'messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'cancel me']]],
                ],
            ],
            options: [
                'cancel_token' => new ToggleCancellationToken(),
            ],
        ));

        self::assertSame('aborted', $response->stopReason);
        self::assertSame('A', $response->assistantMessage['content'][0]['text']);
        self::assertSame(15, $response->usage['total_tokens']);
    }
}

final class FakeSymfonyPlatform
{
    public ?string $capturedModel = null;

    /** @var array<string, mixed> */
    public array $capturedOptions = [];

    public mixed $capturedInput = null;

    /**
     * @param \Closure(): iterable<mixed> $streamFactory
     */
    public function __construct(
        private readonly \Closure $streamFactory,
        private readonly ?FakeMetadata $metadata = null,
    ) {
    }

    public function invoke(string $model, array|string|object $input, array $options = []): FakeDeferredResult
    {
        $this->capturedModel = $model;
        $this->capturedInput = $input;
        $this->capturedOptions = $options;

        $streamFactory = $this->streamFactory;

        return new FakeDeferredResult($streamFactory(), $this->metadata);
    }
}

final readonly class FakeDeferredResult
{
    /**
     * @param iterable<mixed> $stream
     */
    public function __construct(
        private iterable $stream,
        private ?FakeMetadata $metadata = null,
    ) {
    }

    /**
     * @return iterable<mixed>
     */
    public function asStream(): iterable
    {
        return $this->stream;
    }

    public function getMetadata(): ?FakeMetadata
    {
        return $this->metadata;
    }
}

final readonly class FakeMetadata
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(
        private array $entries,
    ) {
    }

    public function get(string $name): mixed
    {
        return $this->entries[$name] ?? null;
    }
}

final readonly class TextDelta
{
    public function __construct(private string $text)
    {
    }

    public function getText(): string
    {
        return $this->text;
    }
}

final readonly class FakeTokenUsage
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
