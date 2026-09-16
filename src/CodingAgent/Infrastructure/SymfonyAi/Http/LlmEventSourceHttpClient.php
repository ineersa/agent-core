<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\SymfonyAi\Http;

use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\ServerSentEvent;
use Symfony\Component\HttpClient\Exception\EventSourceException;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * SSE framing for LLM streams without EventSource reconnection.
 *
 * Vendor {@see \Symfony\Component\HttpClient\EventSourceHttpClient} swallows
 * transport exceptions (including progress-hook cancel aborts) for up to
 * reconnectionTime (default 10s) and may replace the request. LLM runs must
 * surface cancel/idle failures immediately so {@see \Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter}
 * and the retry executor can react.
 *
 * Framing behavior matches the vendor SSE client for text/event-stream bodies.
 */
final class LlmEventSourceHttpClient implements HttpClientInterface
{
    use AsyncDecoratorTrait;

    public function __construct(?HttpClientInterface $client = null)
    {
        $this->client = $client ?? \Symfony\Component\HttpClient\HttpClient::create();
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $state = new \stdClass();
        $state->buffer = null;
        $state->firstChunkSeen = false;

        $accept = self::normalizeAccept($options['headers'] ?? []);
        if (null !== $accept) {
            $state->buffer = $accept ? '' : null;
            if (null !== $state->buffer) {
                $options['extra']['trace_content'] = false;
            }
        }

        $options['buffer'] = false;
        $options['headers']['Accept'] ??= 'text/event-stream';
        $options['headers']['Cache-Control'] ??= 'no-cache';

        return new AsyncResponse($this->client, $method, $url, $options, static function (ChunkInterface $chunk, AsyncContext $context) use ($state): \Generator {
            try {
                if (null !== $chunk->getInformationalStatus() || $context->getInfo('canceled')) {
                    yield $chunk;

                    return;
                }

                // Propagate idle-timeout ErrorChunks immediately (no reconnect).
                if ($chunk->isTimeout()) {
                    yield $chunk;

                    return;
                }
            } catch (TransportExceptionInterface) {
                // Progress-hook cancel and other transport failures must surface now.
                yield $chunk;

                return;
            }

            if ($chunk->isFirst()) {
                if (preg_match('/^text\/event-stream(;|$)/i', $context->getHeaders()['content-type'][0] ?? '')) {
                    $state->buffer = '';
                } elseif (null !== $state->buffer && 200 === $context->getStatusCode()) {
                    throw new EventSourceException(\sprintf('Response content-type is "%s" while "text/event-stream" was expected for "%s".', $context->getHeaders()['content-type'][0] ?? '', $context->getInfo('url')));
                } else {
                    $context->passthru();
                }

                if (!$state->firstChunkSeen) {
                    $state->firstChunkSeen = true;
                    yield $chunk;
                }

                return;
            }

            if ($chunk->isLast()) {
                if ('' !== $content = (string) $state->buffer) {
                    $state->buffer = '';
                    yield new DataChunk(-1, $content);
                }

                yield $chunk;

                return;
            }

            $content = (string) $state->buffer.$chunk->getContent();
            $events = preg_split('/((?:\r\n){2,}|\r{2,}|\n{2,})/', $content, -1, \PREG_SPLIT_DELIM_CAPTURE);
            $state->buffer = array_pop($events);

            for ($i = 0; isset($events[$i]); $i += 2) {
                $content = $events[$i].$events[1 + $i];
                if (!preg_match('/(?:^|\r\n|[\r\n])[^:\r\n]/', $content)) {
                    yield new DataChunk(-1, $content);

                    continue;
                }

                yield new ServerSentEvent($content);
            }
        });
    }

    /**
     * @param array<string, mixed> $headers
     */
    private static function normalizeAccept(array $headers): ?bool
    {
        foreach ($headers as $name => $value) {
            if (!\is_string($name) || 0 !== strcasecmp($name, 'Accept')) {
                continue;
            }

            $values = \is_array($value) ? $value : [$value];
            foreach ($values as $headerValue) {
                if (!\is_string($headerValue)) {
                    continue;
                }
                if (preg_match('/^\s*text\/event-stream\b/i', $headerValue)) {
                    return true;
                }

                return false;
            }
        }

        return null;
    }
}
