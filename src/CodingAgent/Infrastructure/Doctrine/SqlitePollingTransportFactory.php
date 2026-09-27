<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\Doctrine;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/** @implements TransportFactoryInterface<DoctrineTransport> */
final readonly class SqlitePollingTransportFactory implements TransportFactoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    /** @param array<array-key, mixed> $options */
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        unset($options['transport_name'], $options['use_notify']);
        $configuration = SqlitePollingConnection::buildConfiguration($dsn, $options);

        return new DoctrineTransport(new SqlitePollingConnection($configuration, $this->connection), $serializer);
    }

    /** @param array<array-key, mixed> $options */
    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return str_starts_with($dsn, 'doctrine://')
            && 'messenger_transport' === parse_url($dsn, \PHP_URL_HOST)
            && $this->connection->getDatabasePlatform() instanceof SQLitePlatform;
    }
}
