<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Messenger;

use Doctrine\DBAL\DriverManager;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineMessengerConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Thesis: production session DSNs pin redeliver_timeout=60 so a delivered-but-unacked
 * Doctrine row is not immediately receivable, but becomes receivable again once its
 * delivered_at lease is aged past that timeout — without sleeping a full minute.
 *
 * Uses the test container's messenger_transport SQLite path (same DB family as
 * production controller DSNs) with a fresh DBAL connection so DAMA's outer
 * transaction wrapper does not nest under Messenger BEGIN IMMEDIATE.
 *
 * @coversNothing
 */
final class MessengerDoctrineRedeliverTimeoutLeaseTest extends IsolatedKernelTestCase
{
    public function testDeliveredUnackedRowBecomesReceivableOnlyAfterStaleLease(): void
    {
        $sessionId = 'lease-'.bin2hex(random_bytes(4));
        $queueName = 'llm_'.$sessionId;

        $kernelTransport = self::getContainer()->get('doctrine.dbal.messenger_transport_connection');
        $params = $kernelTransport->getParams();
        $path = $params['path'] ?? null;
        $this->assertIsString($path, 'messenger_transport connection must expose a SQLite path');

        // Fresh connection to the same transport file: DAMA wraps the kernel
        // connection in a test transaction that conflicts with Messenger BEGIN.
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
                'doctrine://messenger_transport?queue_name=%s&redeliver_timeout=60',
                $queueName,
            ),
        );
        $this->assertSame(60, (int) $configuration['redeliver_timeout']);
        $this->assertSame($queueName, $configuration['queue_name']);

        $connection = new DoctrineMessengerConnection($configuration, $fresh);
        $transport = new DoctrineTransport($connection, new PhpSerializer());

        $transport->send(new Envelope(new \stdClass()));

        $claimed = iterator_to_array($transport->get());
        $this->assertCount(1, $claimed, 'Fresh queue row must be claimable once.');

        $stillHeld = iterator_to_array($transport->get());
        $this->assertSame([], $stillHeld, 'Fresh delivered_at lease must not be immediately reclaimed.');

        $stale = (new \DateTimeImmutable('UTC'))->modify('-61 seconds')->format('Y-m-d H:i:s');
        $updated = $fresh->executeStatement(
            'UPDATE messenger_messages SET delivered_at = ? WHERE queue_name = ? AND delivered_at IS NOT NULL',
            [$stale, $queueName],
        );
        $this->assertSame(1, $updated, 'Exactly one delivered row must be aged for reclaim.');

        $reclaimed = iterator_to_array($transport->get());
        $this->assertCount(1, $reclaimed, 'Stale delivered_at must make the row receivable again under redeliver_timeout=60.');

        $transport->ack($reclaimed[0]);
        $fresh->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', [$queueName]);
        $fresh->close();
    }
}
