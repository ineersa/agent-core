<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Support;

use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * @internal
 */
final class ProviderBoundaryCaptureSupport
{
    public function __construct(
        private readonly LlmPlatformAdapter $adapter,
        public readonly FakeSymfonyModelClientForCapture $client,
    ) {
    }

    public static function create(ToolboxInterface $toolbox, ?ToolSetResolverInterface $toolSetResolver = null): self
    {
        $client = new FakeSymfonyModelClientForCapture(new FakeTokenUsageForCapture());
        $platform = new Platform(
            providers: [new Provider(
                name: 'fake',
                modelClients: [$client],
                resultConverters: [new FakeStreamResultConverterForCapture()],
                modelCatalog: new FallbackModelCatalog(),
                eventDispatcher: new EventDispatcher(),
            )],
            eventDispatcher: new EventDispatcher(),
        );

        $adapter = new LlmPlatformAdapter(
            runStore: new InMemoryRunStore(),
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor($toolbox, $toolSetResolver),
            platform: $platform,
            transformContextHooks: [],
            convertToLlmHooks: [],
            streamObserver: null,
            costCalculator: null,
            modelResolver: null,
            logger: new NullLogger(),
        );

        return new self($adapter, $client);
    }

    /**
     * @param list<AgentMessage> $messages
     */
    public function captureForRun(string $runId, array $messages, string $toolsRef = 'default'): void
    {
        $this->client->resetCapture();
        $this->adapter->invoke(new ModelInvocationRequest(
            model: 'gpt-test',
            input: new ModelInvocationInput(
                runId: $runId,
                turnNo: 0,
                stepId: 'gf05-capture',
                messages: $messages,
                toolsRef: $toolsRef,
            ),
        ));
    }

    /** @return list<array{role: string, text: string}> */
    public function capturedProviderMessages(): array
    {
        $payload = $this->client->capturedPayload;
        if (!\is_array($payload)) {
            return [];
        }

        $messages = $payload['messages'] ?? [];
        if (!\is_array($messages)) {
            return [];
        }

        $out = [];
        foreach ($messages as $message) {
            if (!\is_array($message)) {
                continue;
            }
            $role = (string) ($message['role'] ?? '');
            $content = $message['content'] ?? '';
            $text = \is_string($content) ? $content : '';
            if (\is_array($content)) {
                foreach ($content as $part) {
                    if (\is_array($part) && isset($part['text'])) {
                        $text .= (string) $part['text'];
                    }
                }
            }
            $out[] = ['role' => $role, 'text' => $text];
        }

        return $out;
    }

    /** @return list<array{name: string, description: string}> */
    public function capturedProviderToolSchemas(): array
    {
        $tools = $this->client->capturedOptions['tools'] ?? [];
        if (!\is_array($tools)) {
            return [];
        }

        $out = [];
        foreach ($tools as $tool) {
            if (!\is_array($tool)) {
                continue;
            }
            $fn = $tool['function'] ?? $tool;
            if (!\is_array($fn)) {
                continue;
            }
            $out[] = [
                'name' => (string) ($fn['name'] ?? ''),
                'description' => (string) ($fn['description'] ?? ''),
            ];
        }

        return $out;
    }

    /** @param list<string> $toolNames */
    public static function fixedToolSetResolver(array $toolNames): ToolSetResolverInterface
    {
        return new class($toolNames) implements ToolSetResolverInterface {
            public function __construct(private array $toolNames)
            {
            }

            public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): ActiveToolSet
            {
                unset($toolsRef, $turnNo, $runId);

                return new ActiveToolSet(toolNames: $this->toolNames);
            }
        };
    }
}

final class FakeSymfonyModelClientForCapture implements ModelClientInterface
{
    public ?string $capturedModel = null;
    /** @var array<string, mixed> */
    public array $capturedOptions = [];
    /** @var array<string, mixed>|string|null */
    public array|string|null $capturedPayload = null;

    public function __construct(private readonly TokenUsageInterface $tokenUsage)
    {
    }

    public function resetCapture(): void
    {
        $this->capturedModel = null;
        $this->capturedOptions = [];
        $this->capturedPayload = null;
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

final readonly class FakeStreamResultConverterForCapture implements ResultConverterInterface
{
    public function supports(Model $model): bool
    {
        return true;
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        unset($result, $options);

        return new StreamResult((static function (): \Generator {
            yield new TextDelta('ok');
        })());
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return new class implements TokenUsageExtractorInterface {
            public function extract(RawResultInterface $rawResult, array $options = []): ?TokenUsageInterface
            {
                unset($rawResult, $options);

                return null;
            }
        };
    }
}

final class FakeTokenUsageForCapture implements TokenUsageInterface
{
    public function getPromptTokens(): ?int { return 1; }
    public function getCompletionTokens(): ?int { return 1; }
    public function getThinkingTokens(): ?int { return null; }
    public function getToolTokens(): ?int { return null; }
    public function getCachedTokens(): ?int { return null; }
    public function getCacheCreationTokens(): ?int { return null; }
    public function getCacheReadTokens(): ?int { return null; }
    public function getRemainingTokens(): ?int { return null; }
    public function getRemainingTokensMinute(): ?int { return null; }
    public function getRemainingTokensMonth(): ?int { return null; }
    public function getTotalTokens(): ?int { return 2; }
}
