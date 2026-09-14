<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi\Http;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * HttpClient decorator that merges nested request-option overrides onto each request.
 *
 * Symfony AI Completions/OpenResponses model clients put provider options in the JSON body
 * and do not forward HttpClient transport keys such as max_duration. This decorator is the
 * host seam for per-call transport budgets without raising the shared client default.
 */
final class RequestScopedHttpClient implements HttpClientInterface, ResetInterface
{
    /** @var list<array<string, mixed>> */
    private static array $optionStack = [];

    private HttpClientInterface $client;

    public function __construct(?HttpClientInterface $client = null)
    {
        $this->client = $client ?? HttpClient::create();
    }

    /**
     * @param array<string, mixed> $options Symfony HttpClient options merged for the duration of $callback
     */
    public static function runWithOptions(array $options, callable $callback): mixed
    {
        self::$optionStack[] = $options;
        try {
            return $callback();
        } finally {
            array_pop(self::$optionStack);
        }
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->client->request($method, $url, $this->mergeScopedOptions($options));
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);

        return $clone;
    }

    public function reset(): void
    {
        self::$optionStack = [];
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function mergeScopedOptions(array $options): array
    {
        foreach (self::$optionStack as $scoped) {
            $options = array_replace($options, $scoped);
        }

        return $options;
    }
}
