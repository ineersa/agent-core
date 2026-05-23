<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\AgentCore\Domain\Message\PublishRuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

/**
 * Polls the Messenger publish Doctrine transport for runtime events.
 *
 * In async mode, stream subscribers publish transient streaming deltas to
 * the publish bus via MessengerRuntimeEventPublisher. This poller reads
 * those messages from the Doctrine transport, wraps them as RuntimeEvent
 * objects, and yields them for the controller to forward to stdout.
 *
 * Error handling:
 * - The ReceiverInterface::get() call is wrapped in try-catch to prevent
 *   transport exceptions from crashing the Revolt event loop.
 * - Per-envelope processing is isolated — a single bad message is rejected
 *   without affecting the rest of the batch.
 */
final readonly class PublishTransportPoller
{
    public function __construct(
        private readonly ReceiverInterface $receiver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Poll the publish transport and yield RuntimeEvent objects.
     *
     * @return iterable<RuntimeEvent>
     */
    public function poll(): iterable
    {
        try {
            // Drain ALL available messages in a single poll tick.
            // DoctrineReceiver::get() returns only ONE message per call,
            // so we loop until the queue is empty. This batches all
            // pending streaming deltas into a single poll tick, keeping
            // the TUI responsive even under high delta throughput.
            while (true) {
                $envelopes = $this->receiver->get();
                if ([] === $envelopes) {
                    return;
                }

                foreach ($envelopes as $envelope) {
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
            }
        } catch (\Throwable $e) {
            $this->logger->error('Publish transport poll failed', [
                'exception' => $e,
            ]);
        }
    }
}
