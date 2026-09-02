<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Discards failed run_control Doctrine state before retry or terminal handling.
 *
 * Messenger's retry listener runs at priority 100. This listener runs afterward
 * so retry publication completes first, but before WorkerFailedEventSubscriber at
 * priority 0 needs a clean connection to persist a terminal agent_end.
 */
final readonly class RunControlDoctrineFailureResetSubscriber implements EventSubscriberInterface
{
    private const int PRIORITY = 50;

    public function __construct(
        private ManagerRegistry $managerRegistry,
        #[Autowire(service: 'doctrine.dbal.default_connection')]
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => ['onWorkerMessageFailed', self::PRIORITY],
        ];
    }

    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ('run_control' !== $event->getReceiverName()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        $runId = $message instanceof AbstractAgentBusMessage ? $message->runId() : null;

        try {
            // close() drops the PDO handle and resets DBAL transaction nesting.
            // Reset the manager afterward so repositories no longer retain an
            // EntityManager closed by a failed wrapInTransaction().
            $this->connection->close();
            $this->managerRegistry->resetManager();

            $this->logger->info('agent_loop.worker_failed_doctrine_reset', [
                'run_id' => $runId,
                'message_type' => $message::class,
                'receiver' => $event->getReceiverName(),
                'component' => 'messenger.worker',
                'event_type' => 'worker_failed.doctrine_reset',
            ]);
        } catch (\Throwable $exception) {
            // Failure processing must continue so Messenger can reject the
            // original delivery and the terminal subscriber can still try.
            $this->logger->error('agent_loop.worker_failed_doctrine_reset_error', [
                'run_id' => $runId,
                'message_type' => $message::class,
                'receiver' => $event->getReceiverName(),
                'component' => 'messenger.worker',
                'event_type' => 'worker_failed.doctrine_reset_error',
                'exception' => $exception,
            ]);
        }
    }
}
