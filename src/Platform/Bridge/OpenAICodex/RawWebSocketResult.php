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

    public function __construct(
        private readonly WebsocketConnection $connection,
        private readonly float $idleTimeoutSeconds,
        ?LoggerInterface $logger = null,
        private bool $aborted = false,
    ) {
        $this->handle = new CodexWebSocketResultHandle();
        $this->logger = $logger ?? new NullLogger();
    }

    public function __destruct()
    {
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

        try {
            yield from $this->iterateEvents();
        } finally {
            $this->closeConnection();
        }
    }

    public function getObject(): object
    {
        return $this->handle;
    }

    public function abort(): void
    {
        $this->aborted = true;
        $this->closeConnection();
    }

    /**
     * @return \Generator<int, array<string, mixed>, mixed, void>
     */
    private function iterateEvents(): \Generator
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
            if ('response.completed' === $type || 'response.done' === $type) {
                return;
            }
        }
    }

    /** Idempotent: stream iteration, abort(), and __destruct() all funnel here. */
    private function closeConnection(): void
    {
        if ($this->connectionClosed) {
            return;
        }

        $this->connectionClosed = true;

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
