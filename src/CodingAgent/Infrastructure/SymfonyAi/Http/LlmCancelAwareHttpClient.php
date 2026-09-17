<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
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
 * The cancel token is captured at {@see request()} time so nested/sibling
 * scopes cannot cancel the wrong in-flight transfer. Progress throws
 * {@see LlmStreamCancelledException}. Vendor
 * {@see \Symfony\Component\HttpClient\EventSourceHttpClient} may swallow that as a
 * reconnectable transport error, so {@see stream()} rethrows the typed cancel
 * from the captured token / exact cancel error before reconnect can hide it.
 */
final class LlmCancelAwareHttpClient implements HttpClientInterface
{
    /** @var \WeakMap<ResponseInterface, CancellationTokenInterface> */
    private \WeakMap $responseTokens;

    /**
     * @param array<string, mixed> $defaultOptions
     */
    public function __construct(
        private readonly HttpClientInterface $inner,
        private readonly array $defaultOptions = [],
    ) {
        $this->responseTokens = new \WeakMap();
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options = array_merge($this->defaultOptions, $options);
        $userProgress = $options['on_progress'] ?? null;
        if (null !== $userProgress && !\is_callable($userProgress)) {
            throw new \InvalidArgumentException('HTTP option "on_progress" must be callable when provided.');
        }

        $token = LlmInvocationCancelScope::current();
        $options['on_progress'] = static function (int $dlNow, int $dlSize, array $info) use ($userProgress, $token): void {
            if (\is_callable($userProgress)) {
                $userProgress($dlNow, $dlSize, $info);
            }

            if (null !== $token && $token->isCancellationRequested()) {
                throw new LlmStreamCancelledException();
            }
        };

        $response = $this->inner->request($method, $url, $options);
        if (null !== $token) {
            $this->responseTokens[$response] = $token;
        }

        return $response;
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $inner = $this->inner;
        $responseTokens = $this->responseTokens;

        return new ResponseStream((static function () use ($inner, $responses, $timeout, $responseTokens): \Generator {
            foreach ($inner->stream($responses, $timeout) as $response => $chunk) {
                $token = $responseTokens[$response] ?? null;
                $error = $chunk->getError();
                if (self::isCancelError($error) || (null !== $token && $token->isCancellationRequested())) {
                    if ($chunk instanceof ErrorChunk) {
                        $chunk->didThrow(true);
                    }
                    $response->cancel();
                    throw new LlmStreamCancelledException();
                }

                yield $response => $chunk;
            }
        })());
    }

    /**
     * @param array<string, mixed> $options
     */
    public function withOptions(array $options): static
    {
        return new self(
            $this->inner->withOptions($options),
            array_merge($this->defaultOptions, $options),
        );
    }

    private static function isCancelError(?string $error): bool
    {
        return LlmStreamCancelledException::MESSAGE === $error;
    }
}
