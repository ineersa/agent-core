<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmStreamCancelledException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Merges a cancel-token progress hook onto outbound LLM HTTP requests.
 *
 * Symfony Curl/Native transports invoke {@see HttpClientInterface} `on_progress`
 * during stalled reads (~1/s). Throwing from that callback aborts the transfer
 * without waiting for idle timeout or max_duration.
 *
 * The active token is read from {@see RunLogContext} so the decorator stays
 * request-scoped without a mutable registry.
 */
final class LlmCancelAwareHttpClient implements HttpClientInterface
{
    private const string CONTEXT_KEY = 'llm_cancel_token';

    public function __construct(
        private readonly HttpClientInterface $inner,
    ) {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $userProgress = $options['on_progress'] ?? null;
        if (null !== $userProgress && !\is_callable($userProgress)) {
            throw new \InvalidArgumentException('HTTP option "on_progress" must be callable when provided.');
        }

        $options['on_progress'] = static function (int $dlNow, int $dlSize, array $info) use ($userProgress): void {
            if (\is_callable($userProgress)) {
                $userProgress($dlNow, $dlSize, $info);
            }

            $token = RunLogContext::current()[self::CONTEXT_KEY] ?? null;
            if ($token instanceof CancellationTokenInterface && $token->isCancellationRequested()) {
                throw new LlmStreamCancelledException('LLM stream cancelled.');
            }
        };

        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options));
    }
}
