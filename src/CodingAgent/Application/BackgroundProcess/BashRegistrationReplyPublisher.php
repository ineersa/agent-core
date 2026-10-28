<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationReplyDTO;
use Ineersa\SqliteQueue\Messenger\Transport;
use Ineersa\SqliteQueue\Messenger\TransportFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Service\ResetInterface;

/** Bounded role queues, never one queue per worker incarnation. */
final class BashRegistrationReplyPublisher implements ResetInterface
{
    /** @var array<string, Transport> */
    private array $transports = [];

    public function __construct(
        private readonly string $endpoint,
        private readonly string $sessionId,
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function publish(BashRegistrationReplyDTO $reply): void
    {
        if ($reply->sessionId !== $this->sessionId) {
            throw new \LogicException('Bash registration reply belongs to another session.');
        }
        // Deliberately propagate publication failure: the request must remain
        // retryable even when its metadata transaction has already committed.
        $transport = $this->transports[$reply->role->value] ??= (new TransportFactory())->createTransport(
            'sqlite-queue://'.$reply->role->queue().'?endpoint='.rawurlencode($this->endpoint),
            [], new Serializer($this->serializer),
        );
        $transport->send(new Envelope($reply));
    }

    public function reset(): void
    {
        foreach ($this->transports as $transport) {
            $transport->close();
        }
        $this->transports = [];
    }
}
