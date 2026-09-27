<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmInvocationCancelScope;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/** Adds the stable conversation ID required by OpenCode Go to each provider request. */
final readonly class OpenCodeSessionHttpClient implements HttpClientInterface
{
    public function __construct(private HttpClientInterface $inner, private ?string $missingKeyEnv = null)
    {
    }

    /** @param array<array-key, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if (null !== $this->missingKeyEnv) {
            throw new \RuntimeException(\sprintf('OpenCode Go API key environment variable %s is not set. Export it or correct ai.providers.opencode-go.api_key.', $this->missingKeyEnv));
        }

        $runId = LlmInvocationCancelScope::currentRunId();
        if (null === $runId || '' === $runId) {
            throw new \RuntimeException('OpenCode Go requires an active run ID for its session header.');
        }

        $options['headers']['x-opencode-session'] = $runId;
        $options['headers']['x-opencode-client'] = 'hatfield';
        $options['headers']['User-Agent'] = 'hatfield';

        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    /** @param array<array-key, mixed> $options */
    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options), $this->missingKeyEnv);
    }
}
