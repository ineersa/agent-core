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

/** One durable session queue, not an abandoned queue for each worker incarnation. */
final class BashRegistrationReplyPublisher implements ResetInterface
{
    private ?Transport $transport = null;

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
        $this->transport ??= (new TransportFactory())->createTransport(
            'sqlite-queue://bash_registration_reply?endpoint='.rawurlencode($this->endpoint),
            [], new Serializer($this->serializer),
        );
        $this->transport->send(new Envelope($reply));
    }

    public function reset(): void
    {
        $this->transport?->close();
        $this->transport = null;
    }
}
