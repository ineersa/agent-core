<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Amp\Sync\LocalMutex;
use Ineersa\SqliteQueue\Messenger\Transport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

/** Serialize broker publishers without decorating receivers or their native WAIT identity. */
final class SerializedBrokerSendersLocator implements SendersLocatorInterface
{
    /** @var \WeakMap<Transport, SenderInterface> */
    private readonly \WeakMap $senders;

    public function __construct(private readonly SendersLocatorInterface $inner)
    {
        $this->senders = new \WeakMap();
    }

    public function getSenders(Envelope $envelope): iterable
    {
        foreach ($this->inner->getSenders($envelope) as $alias => $sender) {
            yield $alias => $sender instanceof Transport ? ($this->senders[$sender] ??= new class($sender) implements SenderInterface {
                private readonly LocalMutex $mutex;

                public function __construct(private readonly SenderInterface $sender)
                {
                    $this->mutex = new LocalMutex();
                }

                public function send(Envelope $envelope): Envelope
                {
                    // Include deferred connection acquisition and the complete reply.
                    $lock = $this->mutex->acquire();
                    try {
                        return $this->sender->send($envelope);
                    } finally {
                        $lock->release();
                    }
                }
            }) : $sender;
        }
    }
}
