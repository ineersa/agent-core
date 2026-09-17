<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http;

use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmInvocationCancelScope;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmStreamCancelledException;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Adds cancel-aware progress checks under vendor SSE framing.
 *
 * Progress throws {@see LlmStreamCancelledException}. Vendor
 * {@see \Symfony\Component\HttpClient\EventSourceHttpClient} may swallow that as a
 * reconnectable transport error, so {@see stream()} also inspects error chunks and
 * rethrows the typed cancel before EventSource reconnect logic can hide it.
 */
final class LlmCancelAwareHttpClient implements HttpClientInterface
{
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

            $token = LlmInvocationCancelScope::current();
            if (null !== $token && $token->isCancellationRequested()) {
                throw new LlmStreamCancelledException('LLM stream cancelled.');
            }
        };

        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $inner = $this->inner;

        return new ResponseStream((static function () use ($inner, $responses, $timeout): \Generator {
            foreach ($inner->stream($responses, $timeout) as $response => $chunk) {
                $error = $chunk->getError();
                if (self::isCancelError($error) || self::isActiveCancelRequested()) {
                    if ($chunk instanceof ErrorChunk) {
                        $chunk->didThrow(true);
                    }
                    $response->cancel();
                    throw new LlmStreamCancelledException('LLM stream cancelled.');
                }

                yield $response => $chunk;
            }
        })());
    }

    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options));
    }

    private static function isCancelError(?string $error): bool
    {
        return null !== $error && str_contains($error, 'LLM stream cancelled');
    }

    private static function isActiveCancelRequested(): bool
    {
        $token = LlmInvocationCancelScope::current();

        return null !== $token && $token->isCancellationRequested();
    }
}
