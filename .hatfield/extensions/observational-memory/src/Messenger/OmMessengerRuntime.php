<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Messenger;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Handler\BuildCompactionMemoryHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Handler\ObserveBoundaryHandler;
use Ineersa\HatfieldExt\ObservationalMemory\Message\BuildCompactionMemoryMessage;
use Ineersa\HatfieldExt\ObservationalMemory\Message\ObserveBoundaryMessage;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\CompactionRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\ObservationRepository;
use Ineersa\HatfieldExt\ObservationalMemory\Storage\OmDatabase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection as DoctrineMessengerConnection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\EventListener\DispatchPcntlSignalListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

/**
 * Private programmatic Symfony Messenger runtime for OM.
 *
 * Owns its own bus, Doctrine transports, retry/failure listeners, and Worker.
 * Never uses Hatfield buses or messenger-transport.sqlite.
 */
final class OmMessengerRuntime
{
    public const QUEUE_OBSERVATION = 'om_observation';

    public const QUEUE_COMPACTION = 'om_compaction';

    public const QUEUE_FAILED = 'om_failed';

    private function __construct(
        private readonly MessageBusInterface $bus,
        private readonly DoctrineTransport $observationTransport,
        private readonly DoctrineTransport $compactionTransport,
        private readonly DoctrineTransport $failureTransport,
        private readonly EventDispatcher $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function create(
        OmDatabase $database,
        ExtensionApiInterface $api,
        LoggerInterface $logger,
    ): self {
        // $api is retained for OM-03/OM-04 model calls inside handlers later.
        unset($api);

        $connection = $database->connection();
        $serializer = new PhpSerializer();

        $observationTransport = self::createTransport($connection, self::QUEUE_OBSERVATION, $serializer);
        $compactionTransport = self::createTransport($connection, self::QUEUE_COMPACTION, $serializer);
        $failureTransport = self::createTransport($connection, self::QUEUE_FAILED, $serializer);

        // Explicit setup even though migrator already created the table.
        $observationTransport->setup();

        $observationRepository = new ObservationRepository($connection);
        $compactionRepository = new CompactionRepository($connection);

        $handlers = new HandlersLocator([
            ObserveBoundaryMessage::class => [new ObserveBoundaryHandler($observationRepository, $logger)],
            BuildCompactionMemoryMessage::class => [new BuildCompactionMemoryHandler($compactionRepository, $logger)],
        ]);

        $sendersMap = [
            ObserveBoundaryMessage::class => ['om_observation'],
            BuildCompactionMemoryMessage::class => ['om_compaction'],
        ];
        $sendersLocator = new SendersLocator(
            $sendersMap,
            self::serviceLocator([
                'om_observation' => static fn (): DoctrineTransport => $observationTransport,
                'om_compaction' => static fn (): DoctrineTransport => $compactionTransport,
            ]),
        );

        $bus = new MessageBus([
            new SendMessageMiddleware($sendersLocator),
            new HandleMessageMiddleware($handlers),
        ]);

        $eventDispatcher = new EventDispatcher();

        $retryStrategy = new MultiplierRetryStrategy(
            maxRetries: 3,
            delayMilliseconds: 1000,
            multiplier: 2.0,
            maxDelayMilliseconds: 60_000,
        );

        $retrySenders = self::serviceLocator([
            'om_observation' => static fn (): DoctrineTransport => $observationTransport,
            'om_compaction' => static fn (): DoctrineTransport => $compactionTransport,
        ]);
        $retryStrategies = self::serviceLocator([
            'om_observation' => static fn (): MultiplierRetryStrategy => $retryStrategy,
            'om_compaction' => static fn (): MultiplierRetryStrategy => $retryStrategy,
        ]);
        $failureSenders = self::serviceLocator([
            'om_observation' => static fn (): DoctrineTransport => $failureTransport,
            'om_compaction' => static fn (): DoctrineTransport => $failureTransport,
        ]);

        $eventDispatcher->addSubscriber(new SendFailedMessageForRetryListener(
            $retrySenders,
            $retryStrategies,
            $logger,
            $eventDispatcher,
        ));
        $eventDispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener(
            $failureSenders,
            $logger,
        ));
        $eventDispatcher->addSubscriber(new DispatchPcntlSignalListener());

        return new self(
            $bus,
            $observationTransport,
            $compactionTransport,
            $failureTransport,
            $eventDispatcher,
            $logger,
        );
    }

    public function bus(): MessageBusInterface
    {
        return $this->bus;
    }

    /**
     * Failure transport is not consumed by the Worker; used only as the
     * SendFailedMessageToFailureTransportListener target.
     */
    public function failureTransport(): DoctrineTransport
    {
        return $this->failureTransport;
    }

    public function run(): void
    {
        // Compaction queue first so catch-up/compaction work is preferred.
        $worker = new Worker(
            [
                'om_compaction' => $this->compactionTransport,
                'om_observation' => $this->observationTransport,
            ],
            $this->bus,
            $this->eventDispatcher,
            $this->logger,
        );

        if (\function_exists('pcntl_async_signals') && \function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            $stop = static function () use ($worker): void {
                $worker->stop();
            };
            pcntl_signal(\SIGTERM, $stop);
            pcntl_signal(\SIGINT, $stop);
        }

        $this->logger->info('om.worker.run', [
            'component' => 'observational_memory',
            'event_type' => 'om.worker.run',
            'receivers' => ['om_compaction', 'om_observation'],
        ]);

        $worker->run([
            'sleep' => 1_000_000,
        ]);
    }

    private static function createTransport(
        \Doctrine\DBAL\Connection $connection,
        string $queueName,
        PhpSerializer $serializer,
    ): DoctrineTransport {
        $configuration = [
            'table_name' => 'messenger_messages',
            'queue_name' => $queueName,
            'redeliver_timeout' => 3600,
            'auto_setup' => false,
        ];

        $messengerConnection = new DoctrineMessengerConnection($configuration, $connection);

        return new DoctrineTransport($messengerConnection, $serializer);
    }

    /**
     * @param array<string, callable(): mixed> $factories
     */
    private static function serviceLocator(array $factories): ContainerInterface
    {
        return new class($factories) implements ContainerInterface {
            /**
             * @param array<string, callable(): mixed> $factories
             */
            public function __construct(
                private array $factories,
            ) {
            }

            public function get(string $id): mixed
            {
                if (!$this->has($id)) {
                    throw new \RuntimeException(\sprintf('OM Messenger service "%s" was not found.', $id));
                }

                return ($this->factories[$id])();
            }

            public function has(string $id): bool
            {
                return \array_key_exists($id, $this->factories);
            }
        };
    }
}
