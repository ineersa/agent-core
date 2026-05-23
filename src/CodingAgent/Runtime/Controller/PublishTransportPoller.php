<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\AgentCore\Domain\Message\PublishRuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineReceiver;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * Polls the Messenger publish Doctrine transport for runtime events.
 *
 * Uses DoctrineReceiver::all() (Symfony 8.1 batch fetch) instead of get().
 * all() does a single fetchAllAssociative() — no row-level locking, no
 * SQLite contention — and returns up to BATCH_SIZE envelopes per poll tick.
 */
final readonly class PublishTransportPoller
{
    /**
     * Maximum messages to fetch per poll tick.
     *
     * Limits query result size so we don't hold SQLite too long
     * under high streaming delta throughput (hundreds of deltas/sec).
     */
    private const int BATCH_SIZE = 200;

    public function __construct(
        private readonly ReceiverInterface $receiver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Poll the publish transport and yield RuntimeEvent objects.
     *
     * Uses DoctrineReceiver::all() (Symfony 8.1 batch fetch) for efficient
     * bulk reads without SELECT FOR UPDATE. Falls back to get() for
     * non-Doctrine transports (e.g. in-memory test doubles).
     *
     * @return iterable<RuntimeEvent>
     */
    public function poll(): iterable
    {
        if ($this->receiver instanceof DoctrineReceiver) {
            yield from $this->pollBatch();

            return;
        }

        yield from $this->pollSingle();
    }

    /**
     * Batch fetch using DoctrineReceiver::all().
     *
     * @return iterable<RuntimeEvent>
     */
    private function pollBatch(): iterable
    {
        try {
            foreach ($this->receiver->all(self::BATCH_SIZE) as $envelope) {
                try {
                    $message = $envelope->getMessage();

                    if (!$message instanceof PublishRuntimeEvent) {
                        $this->logger->warning('Unexpected message on publish transport', [
                            'message_class' => $message::class,
                        ]);
                        $this->receiver->reject($envelope);

                        continue;
                    }

                    yield new RuntimeEvent(
                        type: $message->type,
                        runId: $message->runId,
                        seq: $message->seq,
                        payload: $message->payload,
                    );

                    $this->receiver->ack($envelope);
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to process publish transport message', [
                        'exception' => $e,
                    ]);
                    $this->receiver->reject($envelope);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Publish transport batch poll failed', [
                'exception' => $e,
            ]);
        }
    }

    /**
     * Fallback single-fetch for non-Doctrine transports.
     *
     * @return iterable<RuntimeEvent>
     */
    private function pollSingle(): iterable
    {
        try {
            foreach ($this->receiver->get() as $envelope) {
                try {
                    $message = $envelope->getMessage();

                    if (!$message instanceof PublishRuntimeEvent) {
                        $this->logger->warning('Unexpected message on publish transport', [
                            'message_class' => $message::class,
                        ]);
                        $this->receiver->reject($envelope);

                        continue;
                    }

                    yield new RuntimeEvent(
                        type: $message->type,
                        runId: $message->runId,
                        seq: $message->seq,
                        payload: $message->payload,
                    );

                    $this->receiver->ack($envelope);
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to process publish transport message', [
                        'exception' => $e,
                    ]);
                    $this->receiver->reject($envelope);
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Publish transport poll failed', [
                'exception' => $e,
            ]);
        }
    }
}
