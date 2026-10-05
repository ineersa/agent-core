<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Extension\CommandCancellationOptions;
use Ineersa\AgentCore\Infrastructure\Storage\DoctrineCommandStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class DoctrineCommandStoreTest extends IsolatedKernelTestCase
{
    public function testConfiguredStoreSharesFifoPendingCommandsAndTerminalIdentities(): void
    {
        $store = self::getContainer()->get(CommandStoreInterface::class);
        $this->assertInstanceOf(DoctrineCommandStore::class, $store);
        $other = $this->freshStore();
        $first = new PendingCommand('fifo', 'steer', 'first', ['text' => 'earlier', 'typed' => new CommandCancellationOptions(safe: true)], new CommandCancellationOptions(safe: true));
        $second = new PendingCommand('fifo', 'continue', 'second');
        $this->assertTrue($store->enqueue($first));
        $this->assertTrue($other->enqueue($second));
        $this->assertFalse($other->enqueue(new PendingCommand('fifo', 'different', 'first', ['text' => 'changed'])));
        $this->assertEquals([$first, $second], $other->pending('fifo'));
        $this->assertSame(2, $store->countPending('fifo'));
        $other->markApplied('fifo', 'first');
        $other->markApplied('fifo', 'first');
        $this->assertEquals([$second], $store->pending('fifo'));
        $store->markRejected('fifo', 'second', 'superseded');
        $store->markRejected('fifo', 'second', 'superseded');
        $this->assertSame([], $other->pending('fifo'));
        $this->assertSame(0, $other->countPending('fifo'));
        $this->assertTrue($other->has('fifo', 'first'));
        $this->assertTrue($other->has('fifo', 'second'));
        $this->assertFalse($other->enqueue($first));
        $this->assertFalse($other->enqueue($second));
        $this->assertFalse($other->has('other-run', 'first'));
    }

    public function testFinalizationWithoutEnqueuePreventsLaterRecreation(): void
    {
        $store = self::getContainer()->get(CommandStoreInterface::class);
        $store->markApplied('finalized', 'applied');
        $store->markRejected('finalized', 'rejected', 'refused');
        $store->markApplied('finalized', 'applied');
        $store->markRejected('finalized', 'rejected', 'refused');
        $other = $this->freshStore();
        foreach (['applied', 'rejected'] as $key) {
            $this->assertTrue($other->has('finalized', $key));
            $this->assertFalse($other->enqueue(new PendingCommand('finalized', 'continue', $key)));
        }
        $this->assertSame([], $other->pending('finalized'));
        $this->assertSame(0, $other->countPending('finalized'));
    }

    public function testCacheClearAndKernelRestartPreservePendingAndAcceptedIdentities(): void
    {
        $store = self::getContainer()->get(CommandStoreInterface::class);
        $command = new PendingCommand('cache-clear', 'follow_up', 'pending', ['text' => 'kept']);
        $store->enqueue($command);
        $store->markApplied('cache-clear', 'accepted');
        $this->assertTrue(self::getContainer()->get('cache.app')->clear());
        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::$kernel->reboot(null);
        $fresh = self::getContainer()->get(CommandStoreInterface::class);
        $this->assertNotSame($store, $fresh);
        $this->assertEquals([$command], $fresh->pending('cache-clear'));
        $this->assertSame(1, $fresh->countPending('cache-clear'));
        $this->assertTrue($fresh->has('cache-clear', 'accepted'));
        $this->assertFalse($fresh->enqueue(new PendingCommand('cache-clear', 'continue', 'accepted')));
    }

    public function testRetiredLargePayloadsAreRemovedAndNeverDecodedByIndexedReads(): void
    {
        $store = self::getContainer()->get(CommandStoreInterface::class);
        $large = str_repeat('history', 16384);
        for ($i = 0; $i < 128; ++$i) {
            $key = 'old-'.$i;
            $store->enqueue(new PendingCommand('history', 'follow_up', $key, ['text' => $large]));
            $store->markApplied('history', $key);
        }
        $connection = self::getContainer()->get(Connection::class);
        $this->assertSame(128, (int) $connection->fetchOne('SELECT COUNT(*) FROM run_command WHERE run_id = ? AND payload IS NULL AND payload_hash IS NULL', ['history']));
        $current = new PendingCommand('history', 'steer', 'current', ['text' => 'now']);
        $store->enqueue($current);
        $realSerializer = self::getContainer()->get('messenger.transport.native_php_serializer');
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->expects($this->once())->method('decode')->willReturnCallback($realSerializer->decode(...));
        $fresh = $this->freshStore($serializer);
        $this->assertTrue($fresh->has('history', 'old-127'));
        $this->assertSame(1, $fresh->countPending('history'));
        $this->assertEquals([$current], $fresh->pending('history'));
        $this->assertFalse($fresh->has('history', 'missing'));
    }

    public function testCorruptPendingPayloadRefusesRatherThanEmptyingMailbox(): void
    {
        $store = self::getContainer()->get(CommandStoreInterface::class);
        $store->enqueue(new PendingCommand('corrupt', 'continue', 'key'));
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE run_command SET payload = ? WHERE run_id = ?', ['broken', 'corrupt']);
        $this->assertTrue($store->has('corrupt', 'key'));
        $this->assertSame(1, $store->countPending('corrupt'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Pending command payload is missing or corrupt.');
        $store->pending('corrupt');
    }

    public function testDatabaseFailurePreservesPendingAndReleasesOwnership(): void
    {
        $store = self::getContainer()->get(CommandStoreInterface::class);
        $command = new PendingCommand('failure', 'continue', 'key');
        $store->enqueue($command);
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement("CREATE TRIGGER command_failure BEFORE UPDATE ON run_command WHEN OLD.run_id = 'failure' BEGIN SELECT RAISE(ABORT, 'command write refused'); END");
        try {
            try {
                $store->markApplied('failure', 'key');
                $this->fail('The failed durable write must propagate.');
            } catch (Exception $exception) {
                $this->assertStringContainsString('command write refused', $exception->getMessage());
            }
            $independent = self::getContainer()->get(LockFactory::class)->createLock('hatfield-command-failure', ttl: null);
            $this->assertTrue($independent->acquire());
            $independent->release();
            $this->assertEquals([$command], $this->freshStore()->pending('failure'));
        } finally {
            $connection->executeStatement('DROP TRIGGER command_failure');
        }
        $this->freshStore()->markApplied('failure', 'key');
        $this->assertSame([], $store->pending('failure'));
        $this->assertTrue($store->has('failure', 'key'));
    }

    private function freshStore(?SerializerInterface $serializer = null): DoctrineCommandStore
    {
        $container = self::getContainer();

        return new DoctrineCommandStore($container->get(EntityManagerInterface::class), $serializer ?? $container->get('messenger.transport.native_php_serializer'), $container->get(LockFactory::class));
    }
}
