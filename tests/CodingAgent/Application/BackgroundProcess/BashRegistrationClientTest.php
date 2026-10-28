<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\BackgroundProcess;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashLifecycleFailureSubscriber;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationClient;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationReplyPublisher;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationService;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationStatusEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationReplyDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationResultDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessCommandDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\RegisterBashProcessHandler;
use Ineersa\CodingAgent\Runtime\Messenger\FiberMessageExecutionStrategy;
use Ineersa\CodingAgent\Runtime\Messenger\RunControlDoctrineFailureResetSubscriber;
use Ineersa\CodingAgent\Runtime\Messenger\SerializedBrokerSendersLocator;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerSupervisor;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueuePaths;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\SqliteQueue\Messenger\TransportFactory;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\MessageSentToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function Amp\async;

final class BashRegistrationClientTest extends IsolatedKernelTestCase
{
    public function testSixteenOccupiedToolSlotsReceiveCommittedRepliesWithoutAdmissionSlots(): void
    {
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner, RegisterBashProcessHandler $handler, BashRegistrationReplyPublisher $publisher): void {
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $sent = 0;
            $saturated = new DeferredFuture();
            $events = new EventDispatcher();
            $events->addListener(MessageSentToTransportsEvent::class, static function (MessageSentToTransportsEvent $event) use (&$sent, $saturated, $publisher, $owner): void {
                if (1 === ++$sent) {
                    $command = $event->getEnvelope()->getMessage();
                    self::assertInstanceOf(RegisterBashProcessCommandDTO::class, $command);
                    // A predecessor reply with a live correlation must not
                    // resolve this worker's waiter. It is ACKed as stale.
                    $publisher->publish(new BashRegistrationReplyDTO($owner, str_repeat('f', 32), $command->correlation, new BashRegistrationResultDTO(BashRegistrationStatusEnum::SessionMissing)));
                }
                if (16 === $sent) {
                    $saturated->complete();
                }
            });
            $locator = new SendersLocator([RegisterBashProcessCommandDTO::class => ['run_control']], new ServiceLocator(['run_control' => static fn () => $requests]));
            $bus = new MessageBus([new SendMessageMiddleware(new SerializedBrokerSendersLocator($locator), $events)]);
            $client = new BashRegistrationClient($bus, $paths->endpointPath, $owner, self::getContainer()->get(SerializerInterface::class), self::getContainer()->get(ValidatorInterface::class));
            $results = [];
            $toolBus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([\stdClass::class => [function (\stdClass $message) use ($client, $owner, &$results): void {
                $results[] = $client->register($this->request($owner, $message->id));
            }]]))]);
            $strategy = new FiberMessageExecutionStrategy($toolBus, 16);
            $handled = 0;
            $ownerEvents = new EventDispatcher();
            $ownerEvents->addListener(WorkerMessageHandledEvent::class, static function () use (&$handled): void { ++$handled; });
            $worker = new Worker(['run_control' => $requests], new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RegisterBashProcessCommandDTO::class => [$handler]]))]), $ownerEvents);
            $ownerTask = async(static function () use ($saturated, $strategy, $worker): void {
                $saturated->getFuture()->await(new TimeoutCancellation(8));
                self::assertTrue($strategy->shouldPauseConsumption(), 'Every tool slot must be occupied before owner replies begin.');
                $worker->run(['sleep' => 0, 'stop_when_empty' => true]);
            });
            try {
                for ($id = 1; $id <= 16; ++$id) {
                    $strategy->execute(new Envelope((object) ['id' => $id]), 'tool', static function (): void {});
                }
                $settled = 0;
                $onHandled = static function (Envelope $envelope, string $transport, bool $acked, ?\Throwable $error) use (&$settled): void {
                    if (null !== $error) {
                        throw $error;
                    }
                    ++$settled;
                };
                while ($strategy->wait($onHandled)) {
                }
                $ownerTask->await(new TimeoutCancellation(8));
                $this->assertSame(16, $sent);
                $this->assertSame(16, $settled);
                $this->assertSame(16, $handled);
                $this->assertCount(16, $results);
                $this->assertCount(16, array_unique(array_map(static fn ($result) => $result->recordId, $results)));
                foreach ($results as $result) {
                    $this->assertSame(BashRegistrationStatusEnum::Registered, $result->status);
                }
                $this->assertSame([], iterator_to_array($requests->get()), 'Native Worker ACKs all committed requests.');
            } finally {
                $worker->stop();
                $requests->close();
                $publisher->reset();
            }
        });
    }

    public function testLostCommittedReplyRedeliversOriginalOutcomeAfterProcessCleanup(): void
    {
        // Native broker lease expiry is the unique external contract here. WAIT
        // blocks on eligibility; the 8s cancellation is only a failure cap.
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner, RegisterBashProcessHandler $handler, BashRegistrationReplyPublisher $publisher): void {
            $factory = new TransportFactory();
            $requests = $factory->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $replies = $factory->createTransport('sqlite-queue://bash_registration_reply?endpoint='.rawurlencode($paths->endpointPath), [], new Serializer(self::getContainer()->get(SerializerInterface::class)));
            $cancel = new TimeoutCancellation(8);
            try {
                $request = new RegisterBashProcessCommandDTO($this->request($owner, 1), str_repeat('b', 32), str_repeat('c', 32));
                $requests->send(new Envelope($request));
                $delivery = iterator_to_array($requests->get())[0];
                $handler($delivery->getMessage());
                $first = iterator_to_array($replies->get())[0];
                $outcome = $first->getMessage();
                $this->assertInstanceOf(BashRegistrationReplyDTO::class, $outcome);
                // Lose the committed response and leave the original request
                // unacknowledged, as if its worker died before request settlement.
                $replies->ack($first);
                self::getContainer()->get(ProcessStore::class)->deleteById($outcome->result->recordId);
                $requests->wait(3000, $cancel);
                $redelivery = iterator_to_array($requests->get())[0];
                $handler($redelivery->getMessage());
                $second = iterator_to_array($replies->get())[0];
                $replayed = $second->getMessage();
                $this->assertInstanceOf(BashRegistrationReplyDTO::class, $replayed);
                $this->assertSame($outcome->result->recordId, $replayed->result->recordId);
                $this->assertFalse($replayed->result->processExists);
                $this->assertNull(self::getContainer()->get(ProcessStore::class)->fetchByRecordId($outcome->result->recordId));
                $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
                $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM bash_registration_receipt'));
                $replies->ack($second);
                $requests->ack($redelivery);
            } finally {
                $requests->close();
                $replies->close();
                $publisher->reset();
            }
        });
    }

    public function testOwnerRecoversAfterActualFlushFailureBeforeNextRegistration(): void
    {
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner, RegisterBashProcessHandler $handler): void {
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $manager = self::getContainer()->get(EntityManagerInterface::class);
            $connection = $manager->getConnection();
            $connection->executeStatement("CREATE TEMP TRIGGER reject_bash_registration BEFORE INSERT ON background_process WHEN NEW.pid = 2147483601 BEGIN SELECT RAISE(ABORT, 'registration flush failure'); END");
            $failed = $handled = 0;
            $events = new EventDispatcher();
            $events->addSubscriber(self::getContainer()->get(RunControlDoctrineFailureResetSubscriber::class));
            $events->addListener(WorkerMessageFailedEvent::class, static function () use (&$failed): void { ++$failed; });
            $events->addListener(WorkerMessageHandledEvent::class, static function () use (&$handled): void { ++$handled; });
            try {
                for ($id = 1; $id <= 2; ++$id) {
                    $requests->send(new Envelope(new RegisterBashProcessCommandDTO($this->request($owner, $id), str_repeat('b', 32), \sprintf('%032x', $id))));
                }
                $worker = new Worker(['run_control' => $requests], new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RegisterBashProcessCommandDTO::class => [$handler]]))]), $events);
                $worker->run(['sleep' => 0, 'stop_when_empty' => true]);
                $this->assertSame(1, $failed);
                $this->assertSame(1, $handled);
                $this->assertTrue($manager->isOpen());
                $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM bash_registration_receipt'));
                $this->assertSame(2147483602, (int) $connection->fetchOne('SELECT pid FROM background_process'));
            } finally {
                $connection->executeStatement('DROP TRIGGER reject_bash_registration');
                $requests->close();
            }
        });
    }

    public function testExhaustedOwnerRequestReportsUnknownOutcomeInsteadOfLeavingWaiterPending(): void
    {
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner, RegisterBashProcessHandler $handler, BashRegistrationReplyPublisher $publisher): void {
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $sender = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $locator = new SendersLocator([RegisterBashProcessCommandDTO::class => ['run_control']], new ServiceLocator(['run_control' => static fn () => $sender]));
            $client = new BashRegistrationClient(new MessageBus([new SendMessageMiddleware($locator)]), $paths->endpointPath, $owner, self::getContainer()->get(SerializerInterface::class), self::getContainer()->get(ValidatorInterface::class));
            $subscriber = new BashLifecycleFailureSubscriber(new ServiceLocator([BashRegistrationReplyPublisher::class => static fn () => $publisher]), $owner);
            $ownerTask = async(static function () use ($requests, $subscriber): void {
                $requests->wait(3000, new TimeoutCancellation(8));
                foreach ($requests->get() as $delivery) {
                    $retry = new WorkerMessageFailedEvent($delivery, 'run_control', new \RuntimeException('owner write failed'));
                    $retry->setForRetry();
                    $subscriber->onFailed($retry);
                    $subscriber->onFailed(new WorkerMessageFailedEvent($delivery, 'run_control', new \RuntimeException('owner retries exhausted')));
                    $requests->reject($delivery);
                }
            });
            try {
                try {
                    $client->register($this->request($owner, 1));
                    $this->fail('A terminal owner error must not report successful registration.');
                } catch (\RuntimeException $error) {
                    $this->assertStringContainsString('committed outcome is unknown', $error->getMessage());
                }
                $ownerTask->await(new TimeoutCancellation(8));
            } finally {
                $requests->close();
                $sender->close();
            }
        });
    }

    /** @param callable(SessionSqliteQueuePaths, string, RegisterBashProcessHandler, BashRegistrationReplyPublisher): void $proof */
    private function withBroker(callable $proof): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('bash-registration');
        $console = \dirname(__DIR__, 4).'/bin/console';
        $executable = $this->createStub(AppExecutableLocator::class);
        $executable->method('path')->willReturn($console);
        $executable->method('command')->willReturn([\PHP_BINARY, \dirname(__DIR__, 2).'/Runtime/Messenger/Fixtures/short-lease-broker.php', $console]);
        $broker = new SessionSqliteQueueBrokerSupervisor(new TestLogger(), new RuntimeProcessConfig($executable, $directory));
        $owner = self::getContainer()->get(HatfieldSessionStore::class)->createSession();
        $paths = SessionSqliteQueuePaths::forSession($directory.'/.hatfield/sessions', $owner);
        $serializer = self::getContainer()->get(SerializerInterface::class);
        $validator = self::getContainer()->get(ValidatorInterface::class);
        $locks = self::getContainer()->get(RunLockManager::class);
        $registration = new BashRegistrationService(self::getContainer()->get(EntityManagerInterface::class), self::getContainer()->get(ProcessStore::class), $locks, $serializer, $validator);
        $publisher = new BashRegistrationReplyPublisher($paths->endpointPath, $owner, $serializer);
        $handler = new RegisterBashProcessHandler($registration, $publisher, $locks, $validator, $owner);
        try {
            $broker->start($paths);
            // Bound the proof at its test-owned future, not through a client API.
            // On failure, broker teardown below releases transport waiters.
            $proofTask = async($proof, $paths, $owner, $handler, $publisher);
            try {
                $proofTask->await(new TimeoutCancellation(8));
            } finally {
                // The await already reports failure; a late transport error
                // during broker teardown must not become an unhandled future.
                $proofTask->ignore();
            }
        } finally {
            $publisher->reset();
            $broker->shutdownUntil(microtime(true) + 5.0);
            if (isset($proofTask) && !$proofTask->isComplete()) {
                try {
                    // Broker disconnect releases blocked client waiters. Join
                    // their finally blocks before PHPUnit destroys the kernel.
                    $proofTask->await(new TimeoutCancellation(1));
                } catch (\Throwable $error) {
                    // The original proof deadline already failed the test.
                    fwrite(\STDERR, 'Expected interrupted proof cleanup: '.$error->getMessage()."\n");
                }
            }
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    private function request(string $owner, int $id): RegisterBashProcessDTO
    {
        return new RegisterBashProcessDTO(\sprintf('%032x', $id), $owner, $owner, 2147483600 + $id, 2147483600 + $id, 'not launched', null, null, false, '/not-launched/'.$id.'.log', '/not-launched/'.$id.'.status', new \DateTimeImmutable('2026-10-27T10:00:00+00:00'));
    }
}
