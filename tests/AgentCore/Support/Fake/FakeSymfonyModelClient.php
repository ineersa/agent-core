<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Fake;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * Symfony AI model client double used by integration tests that drive the
 * production {@see \Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter}
 * through a real Symfony AI {@see \Symfony\AI\Platform\Platform} without network.
 *
 * The client records the model name, options, and payload the provider boundary
 * actually receives, so tests can assert the resolved execution identity reached
 * the provider request.
 */
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
