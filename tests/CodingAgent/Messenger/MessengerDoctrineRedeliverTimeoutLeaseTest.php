<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Messenger;

use Doctrine\DBAL\DriverManager;
use Ineersa\CodingAgent\Runtime\Messenger\Doctrine\ClaimOnlyDoctrineConnection;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineMessengerConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Thesis: ClaimOnlyDoctrineConnection never reclaims a claimed row by age, while
 * a fresh unclaimed row remains receivable. No keepalive or wall-clock wait.
 *
 * Uses the test container's messenger_transport SQLite path (same DB family as
 * production controller DSNs) with a fresh DBAL connection so DAMA's outer
 * transaction wrapper does not nest under Messenger BEGIN IMMEDIATE.
 *
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\Doctrine\ClaimOnlyDoctrineConnection
 */
final class MessengerDoctrineRedeliverTimeoutLeaseTest extends IsolatedKernelTestCase
{
    public function testClaimedRowStaysUnreceivableAfterAgingAndFreshUnclaimedRemainsReceivable(): void
    {
        $sessionId = 'lease-'.bin2hex(random_bytes(4));
        $queueName = 'llm_'.$sessionId;

        $kernelTransport = self::getContainer()->get('doctrine.dbal.messenger_transport_connection');
        $params = $kernelTransport->getParams();
        $path = $params['path'] ?? null;
        $this->assertIsString($path, 'messenger_transport connection must expose a SQLite path');

        $fresh = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $path,
            'driverOptions' => [\PDO::ATTR_TIMEOUT => 5],
        ]);
        $this->assertTrue(
            $fresh->createSchemaManager()->tablesExist(['messenger_messages']),
            'The generated transport migration must provision Messenger storage before a queue is created.',
        );

        $configuration = DoctrineMessengerConnection::buildConfiguration(
            \sprintf(
                'doctrine://messenger_transport?queue_name=%s&redeliver_timeout=3600',
                $queueName,
            ),
        );
        $this->assertSame(3600, (int) $configuration['redeliver_timeout']);
        $this->assertSame($queueName, $configuration['queue_name']);

        $connection = new ClaimOnlyDoctrineConnection($configuration, $fresh);
        $transport = new DoctrineTransport($connection, new PhpSerializer());

        $transport->send(new Envelope(new \stdClass()));

        $claimed = iterator_to_array($transport->get());
        $this->assertCount(1, $claimed, 'Fresh queue row must be claimable once.');

        $stillHeld = iterator_to_array($transport->get());
        $this->assertSame([], $stillHeld, 'Fresh delivered_at claim must not be immediately reclaimed.');

        $stale = (new \DateTimeImmutable('UTC'))->modify('-7200 seconds')->format('Y-m-d H:i:s');
        $updated = $fresh->executeStatement(
            'UPDATE messenger_messages SET delivered_at = ? WHERE queue_name = ? AND delivered_at IS NOT NULL',
            [$stale, $queueName],
        );
        $this->assertSame(1, $updated, 'Exactly one delivered row must be aged.');

        $agedStillHeld = iterator_to_array($transport->get());
        $this->assertSame([], $agedStillHeld, 'Aged claimed row must remain unreceivable without age reclaim.');

        $transport->send(new Envelope(new \stdClass()));
        $freshUnclaimed = iterator_to_array($transport->get());
        $this->assertCount(1, $freshUnclaimed, 'A fresh unclaimed row must still be receivable.');

        $transport->ack($freshUnclaimed[0]);
        $fresh->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', [$queueName]);
        $fresh->close();
    }

    public function testDecoratedDoctrineFactoryWiresClaimOnlyConnection(): void
    {
        $factory = self::getContainer()->get('messenger.transport.doctrine.factory');
        $this->assertInstanceOf(
            \Ineersa\CodingAgent\Runtime\Messenger\Doctrine\ClaimOnlyDoctrineTransportFactory::class,
            $factory,
        );

        $transport = $factory->createTransport(
            'doctrine://messenger_transport?queue_name=claim_only_wiring&redeliver_timeout=3600',
            [],
            new PhpSerializer(),
        );
        $this->assertInstanceOf(DoctrineTransport::class, $transport);

        $reflection = new \ReflectionClass($transport);
        $connectionProperty = $reflection->getProperty('connection');
        $connection = $connectionProperty->getValue($transport);
        $this->assertInstanceOf(ClaimOnlyDoctrineConnection::class, $connection);
    }
}
