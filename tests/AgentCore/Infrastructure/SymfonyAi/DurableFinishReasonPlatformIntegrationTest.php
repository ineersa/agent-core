<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\Platform\Bridge\Generic\DurableResultConverter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Integration proof: DurableResultConverter → DeferredResult metadata listener → LlmPlatformAdapter stopReason.
 */
#[CoversNothing]
final class DurableFinishReasonPlatformIntegrationTest extends TestCase
{
    public function testFinishOnlyStreamMapsStopReasonThroughDeferredResultMetadata(): void
    {
        $adapter = $this->createAdapterWithDurableStream([
            ['choices' => [['finish_reason' => 'stop']]],
        ]);

        $response = $adapter->invoke(new ModelInvocationRequest(
            model: 'generic-test',
            input: new ModelInvocationInput(runId: 'run-finish-1', turnNo: 1, stepId: 'step-1'),
        ));

        $this->assertNull($response->assistantMessage);
        $this->assertSame('stop', $response->stopReason);
        $this->assertNull($response->error);
    }

    public function testTextStreamWithStopFinishReasonMapsThroughAdapter(): void
    {
        $adapter = $this->createAdapterWithDurableStream([
            ['choices' => [['delta' => ['content' => 'Hi']]]],
            ['choices' => [['finish_reason' => 'stop']]],
        ]);

        $response = $adapter->invoke(new ModelInvocationRequest(
            model: 'generic-test',
            input: new ModelInvocationInput(runId: 'run-finish-2', turnNo: 1, stepId: 'step-2'),
        ));

        $this->assertNotNull($response->assistantMessage);
        $this->assertSame('Hi', $response->assistantMessage->asText());
        $this->assertSame('stop', $response->stopReason);
    }

    /**
     * @param list<array<string, mixed>> $chunks
     */
    private function createAdapterWithDurableStream(array $chunks): LlmPlatformAdapter
    {
        $modelClient = new RecordingGenericModelClient($chunks);
        $platform = new Platform(
            providers: [new Provider(
                name: 'generic',
                modelClients: [$modelClient],
                resultConverters: [new DurableResultConverter()],
                modelCatalog: new CompletionsOnlyModelCatalog(),
            )],
        );

        return new LlmPlatformAdapter(
            runStore: new InMemoryRunStore(),
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(
                new class implements ToolboxInterface {
                    public function execute(ToolCall $toolCall): ToolResult
                    {
                        return new ToolResult(new \Symfony\AI\Platform\Message\Content\Text(''));
                    }

                    public function getToolIterator(): \Traversable
                    {
                        return new \ArrayIterator([]);
                    }

                    public function getTools(): array
                    {
                        return [];
                    }
                },
            ),
            platform: $platform,
            transformContextHooks: [],
            convertToLlmHooks: [],
            streamObserver: null,
            costCalculator: null,
            modelResolver: null,
            logger: new NullLogger(),
        );
    }
}

/**
 * @internal
 */
final class CompletionsOnlyModelCatalog implements ModelCatalogInterface
{
    public function getModel(string $modelName): Model
    {
        return new CompletionsModel($modelName, [Capability::INPUT_TEXT]);
    }

    public function getModels(): array
    {
        return [];
    }
}

final class RecordingGenericModelClient implements ModelClientInterface
{
    /** @param list<array<string, mixed>> $chunks */
    public function __construct(private readonly array $chunks)
    {
    }

    public function supports(Model $model): bool
    {
        return true;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        unset($model, $payload);

        $response = new class implements ResponseInterface {
            public function getStatusCode(): int
            {
                return 200;
            }

            public function getHeaders(bool $throw = true): array
            {
                return [];
            }

            public function getContent(bool $throw = true): string
            {
                return '';
            }

            public function toArray(bool $throw = true): array
            {
                return [];
            }

            public function cancel(): void
            {
            }

            public function getInfo(?string $type = null): mixed
            {
                return null;
            }
        };

        return new RawHttpResult(
            $response,
            new class($this->chunks) implements \Symfony\AI\Platform\Result\Stream\HttpStreamInterface {
                /** @param list<array<string, mixed>> $chunks */
                public function __construct(private readonly array $chunks)
                {
                }

                public function stream(ResponseInterface $response): iterable
                {
                    foreach ($this->chunks as $chunk) {
                        yield $chunk;
                    }
                }
            },
        );
    }
}
