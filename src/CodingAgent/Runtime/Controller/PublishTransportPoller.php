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
 * Uses DoctrineReceiver::get() to fetch one message per call. Called
 * every 20ms from the Revolt event loop in HeadlessController.
 *
 * Note: get() uses SELECT FOR UPDATE which blocks under heavy SQLite
 * contention. A future optimization could use all() (Symfony 8.1 batch
 * fetch) which does fetchAllAssociative() without row-level locking.
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
            $envelopes = $this->receiver->get();

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
        } catch (\Throwable $e) {
            $this->logger->error('Publish transport poll failed', [
                'exception' => $e,
            ]);
        }
    }
}
