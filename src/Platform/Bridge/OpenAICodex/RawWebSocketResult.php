<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex;

use Amp\CancelledException;
use Amp\Websocket\Client\WebsocketConnection;
use Ineersa\Platform\Result\CancellableRawResultInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Codex WebSocket raw result: JSON event stream without an HTTP response envelope.
 */
final class RawWebSocketResult implements CancellableRawResultInterface
{
    private readonly CodexWebSocketResultHandle $handle;

    private bool $connectionClosed = false;

    private readonly LoggerInterface $logger;

    private bool $retainConnection = false;

    private bool $cacheFinalized = false;

    public function __construct(
        private readonly WebsocketConnection $connection,
        private readonly float $idleTimeoutSeconds,
        ?LoggerInterface $logger = null,
        private bool $aborted = false,
        private readonly ?CodexWebSocketCachedStreamContext $cachedStreamContext = null,
    ) {
        $this->handle = new CodexWebSocketResultHandle();
        $this->logger = $logger ?? new NullLogger();
    }

    public function __destruct()
    {
        if (!$this->cacheFinalized) {
            $this->finalizeCachedLifecycle(false);
        }
        $this->closeConnection();
    }

    public function getData(): array
    {
        throw new \RuntimeException('Codex WebSocket results are streaming-only.');
    }

    public function getDataStream(): iterable
    {
        if ($this->aborted) {
            return;
        }

        $streamSucceeded = false;
        try {
            yield from $this->iterateEvents($streamSucceeded);
        } catch (\Throwable $e) {
            $this->finalizeCachedLifecycle(false);
            $this->closeConnection();
            throw $e;
        } finally {
            if ($streamSucceeded) {
                $this->finalizeCachedLifecycle(true);
            } else {
                $this->finalizeCachedLifecycle(false);
                $this->closeConnection();
            }
        }
    }

    public function getObject(): object
    {
        return $this->handle;
    }

    public function abort(): void
    {
        $this->aborted = true;
        $this->finalizeCachedLifecycle(false);
        $this->closeConnection();
    }

    /**
     * @return \Generator<int, array<string, mixed>, mixed, void>
     *
     * @param-out bool $streamSucceeded
     */
    private function iterateEvents(bool &$streamSucceeded): \Generator
    {
        while (!$this->aborted) {
            try {
                $message = $this->connection->receive(
                    new \Amp\TimeoutCancellation($this->idleTimeoutSeconds),
                );
            } catch (CancelledException $e) {
                throw new \RuntimeException('Codex WebSocket idle timeout.', previous: $e);
            }

            if (null === $message) {
                throw new \RuntimeException('Codex WebSocket connection closed before response.completed.');
            }

            if (!$message->isText()) {
                throw new \RuntimeException('Codex WebSocket frame was not a text message.');
            }

            $payload = $message->buffer();
            if ('' === $payload) {
                continue;
            }

            try {
                $event = json_decode($payload, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException('Codex WebSocket frame contained invalid JSON.', previous: $e);
            }

            if (!\is_array($event)) {
                throw new \RuntimeException('Codex WebSocket frame must decode to a JSON object.');
            }

            /* @var array<string, mixed> $event */
            yield $event;

            $type = $event['type'] ?? '';
            if ('response.completed' === $type) {
                $this->commitContinuationIfSuccessful($event);
                $streamSucceeded = true;

                return;
            }

            if ('response.done' === $type) {
                if ($this->isSuccessfulTerminalResponse($event)) {
                    $this->commitContinuationIfSuccessful($event);
                    $streamSucceeded = true;

                    return;
                }

                throw new \RuntimeException('Codex WebSocket stream ended with a non-success terminal event.');
            }

            if ('response.failed' === $type || 'response.incomplete' === $type || str_starts_with((string) $type, 'error')) {
                throw new \RuntimeException('Codex WebSocket stream ended with a non-success terminal event.');
            }
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function isSuccessfulTerminalResponse(array $event): bool
    {
        $response = $event['response'] ?? null;
        if (!\is_array($response)) {
            return false;
        }

        $responseId = $response['id'] ?? null;

        return \is_string($responseId) && '' !== $responseId;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function commitContinuationIfSuccessful(array $event): void
    {
        $context = $this->cachedStreamContext;
        if (null === $context || $context->lease->oneShot || null === $context->lease->entry) {
            return;
        }

        $response = $event['response'] ?? null;
        if (!\is_array($response)) {
            return;
        }

        $responseId = $response['id'] ?? null;
        if (!\is_string($responseId) || '' === $responseId) {
            return;
        }

        $output = $response['output'] ?? [];
        if (!\is_array($output)) {
            $output = [];
        }

        $items = [];
        foreach ($output as $item) {
            if (\is_array($item)) {
                $items[] = $item;
            }
        }

        $context->lease->entry->continuation = CodexWebSocketContinuationState::fromSuccessfulResponse(
            $context->fullRequestBody,
            $responseId,
            $items,
        );
    }

    private function finalizeCachedLifecycle(bool $success): void
    {
        if ($this->cacheFinalized) {
            return;
        }
        $context = $this->cachedStreamContext;
        if (null === $context) {
            return;
        }
        $this->cacheFinalized = true;

        if ($success && $context->lease->cached && !$context->lease->oneShot) {
            $this->retainConnection = true;
            $context->cache->release($context->lease, true);

            return;
        }

        $context->cache->invalidateEntry($context->lease->entry, $success ? 'stream_end' : 'stream_failure');
        $this->connectionClosed = true;
    }

    /** Idempotent: stream iteration, abort(), and __destruct() all funnel here. */
    private function closeConnection(): void
    {
        if ($this->connectionClosed) {
            return;
        }

        $this->connectionClosed = true;

        if ($this->retainConnection) {
            return;
        }

        try {
            $this->connection->close();
        } catch (\Throwable $e) {
            $this->logger->warning('codex.websocket.close_failed', [
                'event_type' => 'codex.websocket.close_failed',
                'component' => 'raw_websocket_result',
                'exception_class' => $e::class,
            ]);
        }
    }
}
