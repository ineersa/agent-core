<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Replay;

use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Test-only decorator: inserts wall-clock delay between non-empty stream chunks.
 *
 * MockResponse fully prebuffers iterable body generators during the first
 * readResponse pass, so usleep() inside a MockResponse body never spaces
 * EventSourceHttpClient/SseStream observations. Delaying here, inside
 * stream(), is what actually yields frames across TUI ticks.
 */
final class StreamPacingHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly HttpClientInterface $inner,
        private readonly int $chunkDelayMs,
    ) {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $inner = $this->inner;
        $delayMs = $this->chunkDelayMs;

        return new ResponseStream((static function () use ($inner, $responses, $timeout, $delayMs): \Generator {
            $seenData = false;
            foreach ($inner->stream($responses, $timeout) as $response => $chunk) {
                $content = '';
                try {
                    $content = $chunk->getContent();
                } catch (\Throwable) {
                    // First/last/timeout chunks may throw; still yield them.
                }
                if ('' !== $content) {
                    if ($seenData && $delayMs > 0) {
                        usleep($delayMs * 1000);
                    }
                    $seenData = true;
                }

                yield $response => $chunk;
            }
        })());
    }

    public function withOptions(array $options): static
    {
        return new self($this->inner->withOptions($options), $this->chunkDelayMs);
    }
}
