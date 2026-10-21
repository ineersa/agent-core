<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\KeepaliveReceiverInterface;

/** Native serialization/queue behavior with observable acknowledgement identities. */
final class ToolPayloadWorkerTransport extends InMemoryTransport implements KeepaliveReceiverInterface
{
    /** @var list<Envelope> */
    public array $received = [];
    /** @var list<Envelope> */
    public array $acked = [];
    /** @var list<Envelope> */
    public array $rejected = [];
    /** @var list<Envelope> */
    public array $keepaliveRequests = [];

    public function get(): iterable
    {
        foreach (parent::get() as $envelope) {
            $this->received[] = $envelope;
            yield $envelope;
        }
    }

    public function ack(Envelope $envelope): void
    {
        $this->acked[] = $envelope;
        parent::ack($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        $this->rejected[] = $envelope;
        parent::reject($envelope);
    }

    public function keepalive(Envelope $envelope, ?int $seconds = null): void
    {
        $this->keepaliveRequests[] = $envelope;
    }
}
