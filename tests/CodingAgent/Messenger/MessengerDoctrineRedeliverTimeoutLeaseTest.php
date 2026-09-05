<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Messenger;

use Doctrine\DBAL\DriverManager;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Messenger\Doctrine\ClaimOnlyDoctrineConnection;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\Repair\SessionRepairService;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineMessengerConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Thesis: ClaimOnlyDoctrineConnection never reclaims a claimed row by age, while
 * a fresh unclaimed row remains receivable. Ack/reject delete the claimed row.
 * Explicit SessionRepairService redrive enqueues a fresh receivable envelope and
 * leaves the abandoned claimed row untouched. No keepalive or wall-clock wait.
 *
 * Uses the test container's messenger_transport SQLite path (same DB family as
 * production controller DSNs) with a fresh DBAL connection so DAMA's outer
 * transaction wrapper does not nest under Messenger BEGIN IMMEDIATE.
 *
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\Doctrine\ClaimOnlyDoctrineConnection
 */
final class MessengerDoctrineRedeliverTimeoutLeaseTest extends IsolatedKernelTestCase
{
    public function testClaimedRowStaysUnreceivableAfterAgingWhileAckRejectAndFreshRowsWork(): void
    {
        [$transport, $fresh, $queueName] = $this->openClaimOnlyTransport('lease');

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

        $transport->send(new Envelope(new \stdClass()));
        $toReject = iterator_to_array($transport->get());
        $this->assertCount(1, $toReject);
        $rejectId = $this->envelopeId($toReject[0]);
        $transport->reject($toReject[0]);
        $this->assertSame(
            0,
            (int) $fresh->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE id = ?', [$rejectId]),
            'Reject must delete the claimed row.',
        );

        $this->assertSame(
            1,
            (int) $fresh->fetchOne(
                'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ? AND delivered_at IS NOT NULL',
                [$queueName],
            ),
            'The original aged claimed row must remain until explicitly deleted.',
        );

        $fresh->executeStatement('DELETE FROM messenger_messages WHERE queue_name = ?', [$queueName]);
        $fresh->close();
    }

    public function testRepairRedriveEnqueuesFreshReceivableEnvelopeWithoutClearingClaimedRow(): void
    {
        $runId = 'repair-claim-'.bin2hex(random_bytes(3));
        $stepId = 'advance-after-tools-repair';
        $key = hash('sha256', $runId.'|llm|1|'.$stepId);
        $queueName = 'llm_'.$runId;

        [$transport, $fresh] = $this->openClaimOnlyTransport($runId, $queueName);

        $abandoned = new ExecuteLlmStep($runId, 1, $stepId, 1, $key, \sprintf('toolset:run:%s:turn:1', $runId));
        $transport->send(new Envelope($abandoned));
        $claimed = iterator_to_array($transport->get());
        $this->assertCount(1, $claimed);
        $abandonedId = $this->envelopeId($claimed[0]);

        $projectDir = $this->isolatedCwd();
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $projectDir,
        );
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );
        $lockDir = $projectDir.'/.hatfield/locks';
        TestDirectoryIsolation::ensureDirectory($lockDir);
        $eventStore = new SessionRunEventStore(
            hatfieldSessionStore: $hatfieldSessionStore,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore($lockDir)),
            logger: new NullLogger(),
            sequenceAllocator: new FileRunSequenceAllocator(),
        );

        $factory = new EventFactory();
        foreach ($factory->eventsFromSpecs($runId, 1, 1, [
            ['type' => RunEventTypeEnum::RunStarted->value, 'payload' => ['payload' => ['messages' => []]]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => [
                'turn_no' => 1,
                'step_id' => $stepId,
                'operation_attempt' => 1,
                'operation_idempotency_key' => $key,
            ]],
        ]) as $event) {
            $eventStore->append($event);
        }

        $active = new TestActiveRunContext();
        $active->remember(new RunState(
            runId: $runId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 2,
            activeStepId: $stepId,
        ));

        $executionBus = new TestMessageBus();
        $commandBus = new TestMessageBus();
        $repair = new SessionRepairService(
            eventStore: $eventStore,
            activeRunContext: $active,
            runStateReducer: new RunStateReducer(
                AttributeSerializerValidatorTestFactory::denormalizer(),
                new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()),
            ),
            replayEventPreparer: new ReplayEventPreparer(),
            eventFactory: new EventFactory(),
            toolCallSequenceValidator: new AgentMessageToolCallSequenceValidator(),
            lockManager: new RunLockManager(new LockFactory(new FlockStore($lockDir))),
            logger: new NullLogger(),
            stepDispatcher: new StepDispatcher($commandBus, $executionBus),
            toolBatchStore: $this->createStub(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class),
            serializer: AttributeSerializerValidatorTestFactory::create()[0],
            commandBus: $commandBus,
        );

        $result = $repair->repair($runId, true);
        $this->assertSame(1, $result->activeOperationsRedriven);
        $this->assertCount(1, $executionBus->messages);
        $this->assertInstanceOf(ExecuteLlmStep::class, $executionBus->messages[0]);
        $this->assertSame($key, $executionBus->messages[0]->idempotencyKey());

        $transport->send(new Envelope($executionBus->messages[0]));
        $this->assertSame(
            1,
            (int) $fresh->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE id = ?', [$abandonedId]),
            'Repair must not clear the abandoned claimed row.',
        );

        $redriven = iterator_to_array($transport->get());
        $this->assertCount(1, $redriven, 'Repair redrive must yield a fresh receivable envelope.');
        $redrivenId = $this->envelopeId($redriven[0]);
        $this->assertNotSame($abandonedId, $redrivenId);
        $this->assertInstanceOf(ExecuteLlmStep::class, $redriven[0]->getMessage());
        $this->assertSame($key, $redriven[0]->getMessage()->idempotencyKey());

        $transport->ack($redriven[0]);
        $this->assertSame(
            1,
            (int) $fresh->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE id = ?', [$abandonedId]),
            'Ack of the redriven envelope must leave the abandoned claimed row in place.',
        );

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
            'doctrine://messenger_transport?queue_name=claim_only_wiring',
            [],
            new PhpSerializer(),
        );
        $this->assertInstanceOf(DoctrineTransport::class, $transport);

        $reflection = new \ReflectionClass($transport);
        $connectionProperty = $reflection->getProperty('connection');
        $connection = $connectionProperty->getValue($transport);
        $this->assertInstanceOf(ClaimOnlyDoctrineConnection::class, $connection);
        $this->assertSame(3600, (int) $connection->getConfiguration()['redeliver_timeout']);
    }

    /**
     * @return array{0: DoctrineTransport, 1: \Doctrine\DBAL\Connection, 2: string}
     */
    private function openClaimOnlyTransport(string $suffix, ?string $queueName = null): array
    {
        $queueName ??= 'llm_'.$suffix.'-'.bin2hex(random_bytes(3));

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
            \sprintf('doctrine://messenger_transport?queue_name=%s', $queueName),
        );
        $this->assertSame(3600, (int) $configuration['redeliver_timeout']);
        $this->assertSame($queueName, $configuration['queue_name']);

        $connection = new ClaimOnlyDoctrineConnection($configuration, $fresh);
        $transport = new DoctrineTransport($connection, new PhpSerializer());

        return [$transport, $fresh, $queueName];
    }

    private function envelopeId(Envelope $envelope): string
    {
        $stamp = $envelope->last(TransportMessageIdStamp::class);
        $this->assertInstanceOf(TransportMessageIdStamp::class, $stamp);
        $id = $stamp->getId();
        $this->assertTrue(\is_string($id) || \is_int($id));

        return (string) $id;
    }
}
