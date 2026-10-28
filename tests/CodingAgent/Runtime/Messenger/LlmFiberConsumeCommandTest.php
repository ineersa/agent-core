<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\Retry\AmpRetryClock;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\DTO\RuntimeConfigDTO;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Runtime\Messenger\FiberMessageExecutionStrategy;
use Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeCommand;
use Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeWaitSubscriber;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueueBrokerSupervisor;
use Ineersa\CodingAgent\Runtime\Messenger\SessionSqliteQueuePaths;
use Ineersa\CodingAgent\Runtime\Process\AppExecutableLocator;
use Ineersa\CodingAgent\Runtime\Process\RuntimeProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\SqliteQueue\Client;
use Ineersa\SqliteQueue\Messenger\TransportFactory;
use PHPUnit\Framework\Assert;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Contracts\Service\ServiceProviderInterface;

use function Amp\async;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeCommand
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\LlmFiberConsumeWaitSubscriber
 */
final class LlmFiberConsumeCommandTest extends IsolatedKernelTestCase
{
    public function testToolConsumerBoundsCompletedProcessRetentionAndDrainsManagedSibling(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $store = self::getContainer()->get(ProcessStore::class);
        $release = new DeferredFuture();
        $entered = new DeferredFuture();
        $held = null;
        $calls = 0;
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerMessageHandledEvent::class, static function (WorkerMessageHandledEvent $event) use ($manager, &$held, $release): void {
            if (256 === $event->getEnvelope()->getMessage()->id) {
                self::assertInstanceOf(BackgroundProcess::class, $held);
                self::assertTrue($manager->contains($held), 'A completing must not detach B across its yield.');
                $release->complete();
            }
        });
        $acknowledged = $this->runDoctrineConsumer(300, 2, $dispatcher, static function (int $id) use ($store, $manager, $release, $entered, &$held, &$calls): void {
            ++$calls;
            $record = $store->insertRecord(['pid' => $id, 'session_id' => 'orm-retention', 'started_at' => new \DateTimeImmutable()]);
            $entity = $store->fetchByRecordId($record);
            self::assertInstanceOf(BackgroundProcess::class, $entity);
            if (255 === $id) {
                $held = $entity;
                $entered->complete();
                $release->getFuture()->await();
                self::assertTrue($manager->contains($entity));
                $entity->command = 'SIBLING_WRITE';
            } elseif (256 === $id) {
                $entered->getFuture()->await();
            }
            $entity->finishedAt = new \DateTimeImmutable();
            $store->flush();
        });
        $this->assertSame(256, $calls);
        $this->assertSame(256, $acknowledged);
        $this->assertCount(256, $manager->getUnitOfWork()->getIdentityMap()[BackgroundProcess::class]);
        $this->assertSame('SIBLING_WRITE', $manager->getConnection()->fetchOne('SELECT command FROM background_process WHERE id = ?', [$held->id]));
    }

    public function testCaughtFlushFailureDrainsWithoutResettingActiveSiblingThenManagerCanBeReplaced(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $registry = self::getContainer()->get(ManagerRegistry::class);
        $store = self::getContainer()->get(ProcessStore::class);
        $connection = $manager->getConnection();
        $connection->executeStatement("CREATE TEMP TRIGGER reject_tool_record BEFORE INSERT ON background_process WHEN NEW.pid = 2 BEGIN SELECT RAISE(ABORT, 'tool flush failure'); END");
        $release = new DeferredFuture();
        $entered = new DeferredFuture();
        $failures = [];
        $calls = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerMessageHandledEvent::class, static function (WorkerMessageHandledEvent $event) use ($release): void {
            if (2 === $event->getEnvelope()->getMessage()->id) {
                $release->complete();
            }
        });
        try {
            $acknowledged = $this->runDoctrineConsumer(3, 2, $dispatcher, static function (int $id) use ($manager, $store, $entered, $release, &$failures, &$calls): void {
                $calls[] = $id;
                try {
                    if (1 === $id) {
                        $record = $store->insertRecord(['pid' => 1, 'started_at' => new \DateTimeImmutable()]);
                        $held = $store->fetchByRecordId($record);
                        $entered->complete();
                        $release->getFuture()->await();
                        self::assertFalse($manager->isOpen(), 'Do not replace the failed manager underneath the active sibling.');
                        self::assertFalse($manager->contains($held), 'Doctrine itself detached state on failed flush; it cannot be rescued silently.');
                    } else {
                        $entered->getFuture()->await();
                    }
                    $store->insertRecord(['pid' => $id, 'started_at' => new \DateTimeImmutable()]);
                } catch (\Doctrine\DBAL\Exception|\Doctrine\ORM\Exception\EntityManagerClosed $exception) {
                    // Model ToolExecutor converting ORM failure into a handled tool error.
                    $failures[$id] = $exception::class;
                }
            });
            $this->assertSame([1, 2], $calls, 'A poisoned consumer must not admit subsequent work.');
            $this->assertSame(2, $acknowledged);
            $this->assertCount(2, $failures);
            $this->assertFalse($manager->isOpen());
        } finally {
            $connection->executeStatement('DROP TRIGGER reject_tool_record');
            // Test cleanup also verifies Doctrine's actual native-lazy reference behavior.
            // Production replaces the drained process, not its active services.
            $registry->resetManager();
        }
        $this->assertSame($manager, $registry->getManager());
        $this->assertTrue($manager->isOpen());
        $this->assertGreaterThan(0, $store->insertRecord(['pid' => 3, 'started_at' => new \DateTimeImmutable()]));
    }

    public function testSharedConsumerRenewsActiveLeasesAndDrainsBeforeSettlingOnStop(): void
    {
        // Real broker expiry and native Worker settlement cannot be proved by an
        // in-memory receiver. The 8s cancellation is a safety cap, not a delay.
        $directory = TestDirectoryIsolation::createProjectTempDir('fiber-leases');
        $console = \dirname(__DIR__, 4).'/bin/console';
        $executable = $this->createStub(AppExecutableLocator::class);
        $executable->method('path')->willReturn($console);
        $executable->method('command')->willReturn([\PHP_BINARY, __DIR__.'/Fixtures/short-lease-broker.php', $console]);
        $logger = new TestLogger();
        $broker = new SessionSqliteQueueBrokerSupervisor($logger, new RuntimeProcessConfig($executable, $directory));
        $paths = SessionSqliteQueuePaths::forSession($directory.'/.hatfield/sessions', 'leases');
        $release = new DeferredFuture();
        $entered = new DeferredFuture();
        $calls = [];
        $handled = [];
        $client = null;
        $tool = null;
        $agent = null;
        $command = null;
        $running = null;
        $cancel = new TimeoutCancellation(8);

        try {
            $broker->start($paths);
            $factory = new TransportFactory();
            $tool = $factory->createTransport('sqlite-queue://tool?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $agent = $factory->createTransport('sqlite-queue://agent?endpoint='.rawurlencode($paths->endpointPath), [], new PhpSerializer());
            $tool->send(new Envelope((object) ['label' => 'tool']));
            $agent->send(new Envelope((object) ['label' => 'agent']));
            $bus = new class($release, $entered, $calls) implements MessageBusInterface {
                /** @param list<string> $calls */
                public function __construct(private DeferredFuture $release, private DeferredFuture $entered, private array &$calls)
                {
                }

                public function dispatch(object $message, array $stamps = []): Envelope
                {
                    $envelope = Envelope::wrap($message, $stamps);
                    $this->calls[] = $envelope->getMessage()->label;
                    if (2 === \count($this->calls)) {
                        $this->entered->complete();
                    }
                    $this->release->getFuture()->await();

                    return $envelope;
                }
            };
            $receivers = new ServiceLocator(['tool' => static fn () => $tool, 'agent' => static fn () => $agent]);
            $dispatcher = new EventDispatcher();
            $dispatcher->addListener(WorkerMessageHandledEvent::class, static function (WorkerMessageHandledEvent $event) use (&$handled): void {
                $handled[] = $event->getReceiverName();
            });
            $buses = new ServiceLocator(['default' => static fn () => $bus]);
            $config = clone self::getContainer()->get(AppConfig::class);
            $config->runtime = new RuntimeConfigDTO(toolConcurrency: 2);
            $command = new LlmFiberConsumeCommand(new RoutableMessageBus($buses, $bus), $receivers, $dispatcher, $config, self::getContainer()->get(EntityManagerInterface::class), $logger);
            $input = new ArrayInput(['--transport' => 'tool', '--keepalive' => 1]);
            $input->bind($command->getDefinition());
            $running = async(static fn () => $command->run($input, new BufferedOutput()));
            $entered->getFuture()->await($cancel);

            // This unrenewed receipt is claimed AFTER both handlers entered.
            // Broker WAIT wakes at its expiry, hence after both original leases.
            $client = Client::connect($paths->endpointPath);
            $client->send('expiry', 'clock');
            $clock = $client->receive('expiry');
            $this->assertNotNull($clock);
            $command->handleSignal(\SIGTERM);
            $this->assertFalse($running->isComplete(), 'Stop must drain suspended handlers, not abandon them.');
            $this->assertTrue($client->wait('expiry', 4_000, $cancel));
            $this->assertNull($client->receive('tool'), 'Renewed tool receipt must not be reclaimable after its original lease.');
            $this->assertNull($client->receive('agent'), 'Renewed agent receipt must not be reclaimable after its original lease.');
            $this->assertTrue($broker->supervise(), 'The expiry witness must come from the live broker.');
            $this->assertNotEmpty(array_filter($logger->records, static fn (array $record): bool => 'Renewing Fiber execution leases via Revolt timer.' === $record['message']));

            $tool->send(new Envelope((object) ['label' => 'after-stop']));
            $this->assertSame([], $handled, 'Neither blocked handler has settled early.');
            $release->complete();
            $this->assertSame(0, $running->await($cancel));
            $this->assertEqualsCanonicalizing(['tool', 'agent'], $calls);
            $this->assertEqualsCanonicalizing(['tool', 'agent'], $handled);
            $queued = $client->receive('tool');
            $this->assertNotNull($queued, 'Stopping must leave newly queued work for a replacement consumer.');
            $this->assertStringContainsString('after-stop', $queued->body);
            $client->acknowledge($queued->receipt);
            $this->assertNull($client->receive('tool'), 'Drained tool delivery must be acknowledged.');
            $this->assertNull($client->receive('agent'), 'Drained agent delivery must be acknowledged.');
        } finally {
            if (!$release->isComplete()) {
                $release->complete();
            }
            $command?->handleSignal(\SIGTERM);
            try {
                $running?->await(new TimeoutCancellation(3));
            } finally {
                $tool?->close();
                $agent?->close();
                $client?->close();
                $broker->shutdownUntil(microtime(true) + 5.0);
                TestDirectoryIsolation::removeDirectory($directory);
            }
        }
    }

    public function testCompiledContainerRegistersFiberLlmConsumeCommandAndWaitSubscriber(): void
    {
        $container = self::getContainer();

        /** @var LlmFiberConsumeCommand $command */
        $command = $container->get(LlmFiberConsumeCommand::class);
        /** @var AppConfig $appConfig */
        $appConfig = $container->get(AppConfig::class);
        $runtime = $appConfig->runtime;
        $this->assertSame(10, $runtime->llmConcurrency);
        $this->assertSame(RuntimeConfigDTO::DEFAULT_LLM_CONCURRENCY, $runtime->llmConcurrency);

        $platform = $container->get(LlmPlatformAdapter::class);
        $retryClock = (new \ReflectionProperty($platform, 'clock'))->getValue($platform);
        $this->assertInstanceOf(AmpRetryClock::class, $retryClock, 'LLM retries must yield instead of using the global NativeClock.');

        $attribute = (new \ReflectionClass($command))->getAttributes(AsCommand::class)[0] ?? null;
        $this->assertNotNull($attribute);
        $this->assertSame('hatfield:messenger:consume-llm', $attribute->newInstance()->name);

        $definition = $command->getDefinition();
        $this->assertTrue($definition->hasOption('memory-limit'));
        $this->assertTrue($definition->hasOption('keepalive'));
        $this->assertFalse($definition->hasOption('sleep'));
        $this->assertFalse($definition->hasOption('bus'));
        $this->assertFalse($definition->hasOption('concurrency'));

        if (\Symfony\Component\Console\SignalRegistry\SignalRegistry::isSupported()) {
            $signals = $command->getSubscribedSignals();
            $this->assertContains(\SIGTERM, $signals);
            $this->assertContains(\SIGINT, $signals);
            $this->assertContains(\SIGQUIT, $signals);
            $this->assertNotContains(
                \SIGALRM,
                $signals,
                'Amp/UvDriver defers pcntl_alarm; Fiber llm keepalive must use Revolt EventLoop::repeat.',
            );
        }

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get('event_dispatcher');
        $found = false;
        foreach ($dispatcher->getListeners() as $eventListeners) {
            foreach ($eventListeners as $listener) {
                if (\is_array($listener) && ($listener[0] ?? null) instanceof LlmFiberConsumeWaitSubscriber) {
                    $found = true;
                    break 2;
                }
            }
        }
        $this->assertTrue($found, 'LlmFiberConsumeWaitSubscriber must be registered on the event dispatcher.');
    }

    public function testExecuteUsesRuntimeConcurrencyForStrategy(): void
    {
        $concurrency = 6;
        $receiver = new class implements ReceiverInterface {
            public function get(): iterable
            {
                return [];
            }

            public function ack(Envelope $envelope): void
            {
            }

            public function reject(Envelope $envelope): void
            {
            }
        };

        $bus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return Envelope::wrap($message, $stamps);
            }
        };

        $receivers = new class($receiver) implements ContainerInterface {
            public function __construct(private ReceiverInterface $receiver)
            {
            }

            public function get(string $id): mixed
            {
                if ('llm' !== $id) {
                    throw new \InvalidArgumentException($id);
                }

                return $this->receiver;
            }

            public function has(string $id): bool
            {
                return 'llm' === $id;
            }
        };

        $buses = new class($bus) implements ServiceProviderInterface {
            public function __construct(private MessageBusInterface $bus)
            {
            }

            public function get(string $id): mixed
            {
                return $this->bus;
            }

            public function has(string $id): bool
            {
                return true;
            }

            public function getProvidedServices(): array
            {
                return ['default' => MessageBusInterface::class];
            }
        };

        $dispatcher = new EventDispatcher();
        $capturedLimit = null;
        $dispatcher->addListener(
            WorkerStartedEvent::class,
            static function (WorkerStartedEvent $event) use (&$capturedLimit): void {
                $worker = $event->getWorker();
                $strategyRef = new \ReflectionProperty($worker, 'messageExecutionStrategy');
                $strategy = $strategyRef->getValue($worker);
                self::assertInstanceOf(FiberMessageExecutionStrategy::class, $strategy);
                $limitRef = new \ReflectionProperty($strategy, 'concurrencyLimit');
                $capturedLimit = $limitRef->getValue($strategy);
                $worker->stop();
            },
        );

        $appConfig = clone self::getContainer()->get(AppConfig::class);
        $appConfig->runtime = new RuntimeConfigDTO(llmConcurrency: $concurrency);
        $command = new LlmFiberConsumeCommand(
            new RoutableMessageBus($buses, $bus),
            $receivers,
            $dispatcher,
            $appConfig,
            self::getContainer()->get(EntityManagerInterface::class),
        );

        $output = new BufferedOutput();
        $input = new ArrayInput([
            '--memory-limit' => '256M',
        ]);
        $input->bind($command->getDefinition());

        $status = $command->run($input, $output);

        $this->assertSame(0, $status);
        $this->assertSame($concurrency, $capturedLimit);
        $this->assertStringContainsString(
            \sprintf('Fiber concurrency %d', $concurrency),
            $output->fetch(),
        );
    }

    private function runDoctrineConsumer(int $messages, int $concurrency, EventDispatcher $dispatcher, \Closure $handle): int
    {
        $receiver = new class($messages) implements ReceiverInterface {
            public int $acknowledged = 0;
            private int $next = 1;

            public function __construct(private int $messages)
            {
            }

            public function get(): iterable
            {
                if ($this->next <= $this->messages) {
                    yield new Envelope((object) ['id' => $this->next++]);
                }
            }

            public function ack(Envelope $envelope): void
            {
                ++$this->acknowledged;
            }

            public function reject(Envelope $envelope): void
            {
                Assert::fail('Unexpected rejection');
            }
        };
        $bus = new class($handle) implements MessageBusInterface {
            public function __construct(private \Closure $handle)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = Envelope::wrap($message, $stamps);
                ($this->handle)($envelope->getMessage()->id);

                return $envelope;
            }
        };
        // Finite-source safety: a missing recycle policy must fail assertions,
        // not leave a test consumer running after its source is exhausted.
        $dispatcher->addListener(WorkerRunningEvent::class, static function (WorkerRunningEvent $event): void {
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();
            }
        });
        $config = clone self::getContainer()->get(AppConfig::class);
        $config->runtime = new RuntimeConfigDTO(toolConcurrency: $concurrency);
        $receivers = new ServiceLocator(['tool' => static fn () => $receiver, 'agent' => static fn () => new class implements ReceiverInterface {
            public function get(): iterable
            {
                return [];
            }

            public function ack(Envelope $envelope): void
            {
            }

            public function reject(Envelope $envelope): void
            {
            }
        }]);
        $buses = new ServiceLocator(['default' => static fn () => $bus]);
        $command = new LlmFiberConsumeCommand(new RoutableMessageBus($buses, $bus), $receivers, $dispatcher, $config, self::getContainer()->get(EntityManagerInterface::class));
        $input = new ArrayInput(['--transport' => 'tool']);
        $input->bind($command->getDefinition());
        $this->assertSame(0, $command->run($input, new BufferedOutput()));

        return $receiver->acknowledged;
    }
}
