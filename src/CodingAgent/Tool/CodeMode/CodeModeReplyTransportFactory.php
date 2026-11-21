<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\SqliteQueue\Messenger\Transport;
use Ineersa\SqliteQueue\Messenger\TransportFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final readonly class CodeModeReplyTransportFactory
{
    public function __construct(
        private TransportFactory $factory,
        #[Autowire(service: 'messenger.transport.symfony_serializer')] private SerializerInterface $serializer,
        #[Autowire('%env(HATFIELD_SQLITE_QUEUE_ENDPOINT)%')] private string $endpoint,
    ) {
    }

    public function terminal(): Transport
    {
        return $this->factory->createTransport('sqlite-queue://code_mode_requests', ['endpoint' => $this->endpoint], $this->serializer);
    }
}
