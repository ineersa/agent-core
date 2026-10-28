<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\BackgroundProcess;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashLifecycleFailureSubscriber;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashProcessActionEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationClient;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationReplyPublisher;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationService;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationStatusEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRequesterRoleEnum;
use Ineersa\CodingAgent\Application\BackgroundProcess\DecideBashProcessHandler;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessCommandDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationReplyDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationResultDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessCommandDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\RegisterBashProcessHandler;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
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
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\SqliteQueue\Messenger\TransportFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
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
use function Amp\delay;

final class BashRegistrationClientTest extends IsolatedKernelTestCase
{
    /** @return iterable<string, array{bool}> */
    public static function stopRequesterModes(): iterable
    {
        yield 'live waiter with lost reply' => [false];
        yield 'orphaned request without requester continuation' => [true];
    }

    #[DataProvider('stopRequesterModes')]
    public function testControllerAdoptsCommittedStopWhileFirstReplyIsLost(bool $orphaned): void
    {
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner) use ($orphaned): void {
            $directory = TestDirectoryIsolation::createProjectTempDir('bash-stop-adoption');
            $config = new BackgroundProcessConfig(storageDir: $directory, stopGraceSeconds: 5);
            $logger = new TestLogger();
            $store = self::getContainer()->get(ProcessStore::class);
            $lifecycle = new ProcessLifecycle($config, $logger, new LockFactory(new FlockStore()));
            $manager = new BackgroundProcessManager($store, $lifecycle, $config, $logger);
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $sender = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $locator = new SendersLocator([BashProcessCommandDTO::class => ['run_control']], new ServiceLocator(['run_control' => static fn () => $sender]));
            $bus = new MessageBus([new SendMessageMiddleware(new SerializedBrokerSendersLocator($locator))]);
            $serializer = self::getContainer()->get(SerializerInterface::class);
            $validator = self::getContainer()->get(ValidatorInterface::class);
            $lost = new DeferredFuture();
            $allowReplay = new DeferredFuture();
            $dropFirst = true;
            $failingSerializer = $this->createStub(SerializerInterface::class);
            $failingSerializer->method('serialize')->willReturnCallback(static function (mixed $data, string $format, array $context = []) use ($serializer, &$dropFirst): string {
                if ($data instanceof BashRegistrationReplyDTO && BashRequesterRoleEnum::Tool === $data->role && $dropFirst) {
                    $dropFirst = false;
                    throw new \RuntimeException('injected first stop reply loss');
                }

                return $serializer->serialize($data, $format, $context);
            });
            $failingSerializer->method('deserialize')->willReturnCallback($serializer->deserialize(...));
            $publisher = new BashRegistrationReplyPublisher($paths->endpointPath, $owner, $failingSerializer);
            $handler = new DecideBashProcessHandler($store, $lifecycle, self::getContainer()->get(RunLockManager::class), $publisher, $validator, $owner);
            $client = new BashRegistrationClient($bus, $paths->endpointPath, $owner, $serializer, $validator);
            $adopter = new BackgroundProcessManager($store, $lifecycle, $config, $logger);
            $adopter->useProcessOwner(new BashRegistrationClient($bus, $paths->endpointPath, $owner, $serializer, $validator, BashRequesterRoleEnum::Controller));
            $pipes = [];
            foreach (['work', 'term-release', 'peer'] as $name) {
                $this->assertTrue(posix_mkfifo($directory.'/'.$name, 0600));
                $pipes[$name] = fopen($directory.'/'.$name, 'r+b');
                $this->assertIsResource($pipes[$name]);
            }
            try {
                $trap = ': > '.escapeshellarg($directory.'/term').'; IFS= read -r release < '.escapeshellarg($directory.'/term-release').'; exit 0';
                $target = $manager->start('trap '.escapeshellarg($trap).' TERM; : > '.escapeshellarg($directory.'/ready').'; IFS= read -r work < '.escapeshellarg($directory.'/work'), $owner);
                $manager->markBackgroundedForRecord($target->id, $owner);
                $statusPath = $store->decide($target->id, $owner, BashProcessActionEnum::Observe, $lifecycle)->statusPath;
                $peer = $manager->start(': > '.escapeshellarg($directory.'/peer-ready').'; IFS= read -r peer < '.escapeshellarg($directory.'/peer'), $owner);
                $manager->markBackgroundedForRecord($peer->id, $owner);
                $deadline = hrtime(true) + 3_000_000_000;
                while ((!is_file($directory.'/ready') || !is_file($directory.'/peer-ready')) && hrtime(true) < $deadline) {
                    $this->assertTrue($lifecycle->isAlive($target->pid));
                    $this->assertTrue($lifecycle->isAlive($peer->pid));
                    delay(0.01);
                }
                $this->assertFileExists($directory.'/ready');
                $this->assertFileExists($directory.'/peer-ready');
                $ownerTask = async(static function () use ($requests, $handler, $lost, $allowReplay, $logger): void {
                    $firstCorrelation = null;
                    while (true) {
                        $requests->wait(1000, new TimeoutCancellation(8));
                        foreach ($requests->get() as $delivery) {
                            $command = $delivery->getMessage();
                            if ($command->correlation === $firstCorrelation && !$allowReplay->isComplete()) {
                                continue; // Keep the request retryable while adoption runs.
                            }
                            try {
                                $handler($command);
                            } catch (\RuntimeException $error) {
                                if ('injected first stop reply loss' !== $error->getMessage()) {
                                    throw $error;
                                }
                                $logger->info('test.intentional_first_reply_loss');
                                $firstCorrelation = $command->correlation;
                                $lost->complete();
                                continue;
                            }
                            $requests->ack($delivery);
                            if ($command->correlation === $firstCorrelation) {
                                return;
                            }
                        }
                    }
                });
                $continuations = 0;
                $requester = null;
                if ($orphaned) {
                    // Model the durable boundary after a requester has vanished:
                    // there is deliberately no waiter or native continuation.
                    $bus->dispatch(new BashProcessCommandDTO($target->id, $owner, BashProcessActionEnum::Stop, str_repeat('a', 32), str_repeat('b', 32), BashRequesterRoleEnum::Tool));
                } else {
                    $requester = async(static function () use ($client, $target, $owner, &$continuations): mixed {
                        $result = $client->decide($target->id, $owner, BashProcessActionEnum::Stop);
                        ++$continuations;

                        return $result;
                    });
                }
                $lost->getFuture()->await(new TimeoutCancellation(8));
                $this->assertFalse($requester?->isComplete() ?? false);
                $this->assertFileDoesNotExist($directory.'/term', 'Metadata alone never signals.');
                $adopter->refreshEntities($store->fetchBackgrounded($owner));
                $deadline = hrtime(true) + 3_000_000_000;
                while (!is_file($directory.'/term') && hrtime(true) < $deadline) {
                    $this->assertTrue($lifecycle->isAlive($target->pid));
                    delay(0.01);
                }
                $this->assertFileExists($directory.'/term', 'Adoption issues TERM before starting its own grace.');
                $this->assertTrue($lifecycle->isAlive($target->pid), 'The trap holds the target inside normal graceful cleanup.');
                $this->assertTrue($lifecycle->isAlive($peer->pid), 'The unrelated sibling remains held.');
                $this->assertFalse($requester?->isComplete() ?? false, 'Adoption cannot depend on first-reply redelivery or requester continuation.');
                fwrite($pipes['term-release'], "exit\n");
                fflush($pipes['term-release']);
                $deadline = hrtime(true) + 3_000_000_000;
                while (null === $lifecycle->readStatusFile($statusPath) && hrtime(true) < $deadline) {
                    delay(0.01);
                }
                $this->assertSame(0, $lifecycle->readStatusFile($statusPath), 'The target exits through TERM cleanup, not KILL.');
                $allowReplay->complete();
                if (null !== $requester) {
                    $this->assertTrue($requester->await(new TimeoutCancellation(8))->finished);
                }
                $ownerTask->await(new TimeoutCancellation(8));
                $this->assertSame($orphaned ? 0 : 1, $continuations);
                $row = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchAssociative('SELECT * FROM background_process WHERE id = ?', [$target->id]);
                $this->assertSame(1, (int) $row['stopped_by_user']);
                $this->assertNotNull($row['completion_notified_at']);
                $this->assertTrue($lifecycle->isAlive($peer->pid));
            } finally {
                if (isset($ownerTask)) {
                    $ownerTask->ignore();
                }
                if (isset($requester)) {
                    $requester->ignore();
                }
                foreach ($pipes as $pipe) {
                    fwrite($pipe, "cleanup\n");
                    fclose($pipe);
                }
                $manager->shutdownCleanup();
                $requests->close();
                $sender->close();
                $publisher->reset();
                TestDirectoryIsolation::removeDirectory($directory);
            }
        });
    }

    public function testSimultaneousRolesReceiveOnlyTheirRepliesAndOwnerProcessDecisions(): void
    {
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner, RegisterBashProcessHandler $handler, BashRegistrationReplyPublisher $publisher): void {
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $sender = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $bothSent = new DeferredFuture();
            $sent = 0;
            $events = new EventDispatcher();
            $events->addListener(MessageSentToTransportsEvent::class, static function (MessageSentToTransportsEvent $event) use (&$sent, $bothSent, $publisher, $owner): void {
                $command = $event->getEnvelope()->getMessage();
                if ($command instanceof RegisterBashProcessCommandDTO) {
                    // A stale incarnation is discarded only within its own role.
                    $publisher->publish(new BashRegistrationReplyDTO($owner, str_repeat('f', 32), $command->correlation, new BashRegistrationResultDTO(BashRegistrationStatusEnum::SessionMissing), role: $command->role));
                    if (2 === ++$sent) {
                        $bothSent->complete();
                    }
                }
            });
            $locator = new SendersLocator([RegisterBashProcessCommandDTO::class => ['run_control'], BashProcessCommandDTO::class => ['run_control']], new ServiceLocator(['run_control' => static fn () => $sender]));
            $bus = new MessageBus([new SendMessageMiddleware(new SerializedBrokerSendersLocator($locator), $events)]);
            $serializer = self::getContainer()->get(SerializerInterface::class);
            $validator = self::getContainer()->get(ValidatorInterface::class);
            $decisions = new DecideBashProcessHandler(self::getContainer()->get(ProcessStore::class), self::getContainer()->get(ProcessLifecycle::class), self::getContainer()->get(RunLockManager::class), $publisher, $validator, $owner);
            $ownerEvents = new EventDispatcher();
            $worker = new Worker(['run_control' => $requests], new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RegisterBashProcessCommandDTO::class => [$handler], BashProcessCommandDTO::class => [$decisions]]))]), $ownerEvents);
            $handled = 0;
            $ownerEvents->addListener(WorkerMessageHandledEvent::class, static function () use (&$handled, $worker): void {
                if (4 === ++$handled) {
                    $worker->stop();
                }
            });
            $ownerTask = async(static function () use ($bothSent, $worker): void {
                $bothSent->getFuture()->await(new TimeoutCancellation(8));
                $worker->run(['sleep' => 0]);
            });
            $tasks = [];
            try {
                foreach ([BashRequesterRoleEnum::Tool, BashRequesterRoleEnum::Controller] as $i => $role) {
                    $client = new BashRegistrationClient($bus, $paths->endpointPath, $owner, $serializer, $validator, $role);
                    $tasks[] = async(function () use ($client, $owner, $i): int {
                        $registered = $client->register($this->request($owner, $i + 1));
                        $this->assertSame(BashRegistrationStatusEnum::Registered, $registered->status);
                        $snapshot = $client->decide($registered->recordId, $owner, BashProcessActionEnum::Stop);
                        $this->assertTrue($snapshot->finished, 'The fixture registers no real process.');
                        $this->assertFalse($snapshot->signalRequired);

                        return $snapshot->id;
                    });
                }
                $ids = array_map(static fn ($task): int => $task->await(new TimeoutCancellation(8)), $tasks);
                $ownerTask->await(new TimeoutCancellation(8));
                $this->assertNotSame($ids[0], $ids[1]);
                $this->assertSame(4, $handled);
                $this->assertSame([], iterator_to_array($requests->get()));
            } finally {
                $worker->stop();
                $requests->close();
                $sender->close();
            }
        });
    }

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
            $replies = $factory->createTransport('sqlite-queue://bash_registration_reply_tool?endpoint='.rawurlencode($paths->endpointPath), [], new Serializer(self::getContainer()->get(SerializerInterface::class)));
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

    /** @return iterable<string, array{BashRequesterRoleEnum, bool}> */
    public static function requesterRoles(): iterable
    {
        yield 'tool registration' => [BashRequesterRoleEnum::Tool, false];
        yield 'controller registration' => [BashRequesterRoleEnum::Controller, false];
        yield 'tool stop' => [BashRequesterRoleEnum::Tool, true];
        yield 'controller stop' => [BashRequesterRoleEnum::Controller, true];
    }

    #[DataProvider('requesterRoles')]
    public function testExhaustedOwnerRequestReportsUnknownOutcomeInsteadOfLeavingWaiterPending(BashRequesterRoleEnum $role, bool $decision): void
    {
        $this->withBroker(function (SessionSqliteQueuePaths $paths, string $owner, RegisterBashProcessHandler $handler, BashRegistrationReplyPublisher $publisher) use ($role, $decision): void {
            $requests = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $sender = (new TransportFactory())->createTransport('sqlite-queue://run_control?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $locator = new SendersLocator([RegisterBashProcessCommandDTO::class => ['run_control'], BashProcessCommandDTO::class => ['run_control']], new ServiceLocator(['run_control' => static fn () => $sender]));
            $client = new BashRegistrationClient(new MessageBus([new SendMessageMiddleware($locator)]), $paths->endpointPath, $owner, self::getContainer()->get(SerializerInterface::class), self::getContainer()->get(ValidatorInterface::class), $role);
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
                    if ($decision) {
                        $client->decide(1, $owner, BashProcessActionEnum::Stop);
                    } else {
                        $client->register($this->request($owner, 1));
                    }
                    $this->fail('A terminal owner error must not report a successful lifecycle decision.');
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
