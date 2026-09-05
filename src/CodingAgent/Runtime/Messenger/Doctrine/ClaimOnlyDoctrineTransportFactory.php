<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger\Doctrine;

use Doctrine\Persistence\ConnectionRegistry;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineMessengerConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransportFactory;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Decorates Symfony's Doctrine transport factory with claim-only eligibility.
 *
 * @implements TransportFactoryInterface<DoctrineTransport>
 */
#[AsDecorator(decorates: 'messenger.transport.doctrine.factory')]
final class ClaimOnlyDoctrineTransportFactory implements TransportFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly DoctrineTransportFactory $inner,
        #[Autowire(service: 'doctrine')]
        private readonly ConnectionRegistry $registry,
    ) {
    }

    /**
     * @phpstan-ignore missingType.iterableValue
     */
    public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        unset($options['transport_name'], $options['use_notify']);
        $configuration = DoctrineMessengerConnection::buildConfiguration($dsn, $options);

        try {
            $driverConnection = $this->registry->getConnection($configuration['connection']);
        } catch (\InvalidArgumentException $e) {
            throw new TransportException('Could not find Doctrine connection from Messenger DSN.', 0, $e);
        }

        return new DoctrineTransport(
            new ClaimOnlyDoctrineConnection($configuration, $driverConnection),
            $serializer,
        );
    }

    /**
     * @phpstan-ignore missingType.iterableValue
     */
    public function supports(#[\SensitiveParameter] string $dsn, array $options): bool
    {
        return $this->inner->supports($dsn, $options);
    }
}
