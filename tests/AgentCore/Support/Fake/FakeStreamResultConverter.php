<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Fake;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * Symfony AI result converter double for the shared
 * {@see FakeSymfonyModelClient} — converts the recorded raw result into a
 * deterministic TextDelta stream so the production adapter's stream consumer
 * runs without network.
 */
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
