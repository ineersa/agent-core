<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Ineersa\SqliteQueue\Exception\TransportException as ClientTransportException;
use Ineersa\SqliteQueue\Messenger\Transport;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleSignalEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Worker;

/**
 * Amp-safe idle WAIT for both roles of hatfield:messenger:consume-llm.
 *
 * NativeConsumeWaitSubscriber only arms stock messenger:consume. The Fiber
 * llm command hardcodes sleep=0, so without this subscriber the Worker would
 * busy-spin. Transport::wait yields through Amp and lets pending Fibers make
 * progress while the queue is empty.
 */
final class LlmFiberConsumeWaitSubscriber implements EventSubscriberInterface
{
    private const int DEFAULT_WAIT_BUDGET_MILLISECONDS = 1_000;

    /** @var \WeakMap<Command, true> */
    private \WeakMap $armedCommands;

    /** @var \WeakMap<Worker, DeferredCancellation> */
    private \WeakMap $stopsByWorker;

    /** @var \WeakMap<Command, DeferredCancellation> */
    private \WeakMap $stopsByCommand;

    /** @var \WeakMap<Worker, \Closure(int, Cancellation): bool> */
    private \WeakMap $waitsByWorker;

    public function __construct(
        private readonly ContainerInterface $receiverLocator,
    ) {
        $this->armedCommands = new \WeakMap();
        $this->stopsByWorker = new \WeakMap();
        $this->stopsByCommand = new \WeakMap();
        $this->waitsByWorker = new \WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onConsoleCommand', 0],
            ConsoleEvents::SIGNAL => ['onConsoleSignal', 0],
            ConsoleEvents::TERMINATE => ['onConsoleTerminate', 0],
            ConsoleEvents::ERROR => ['onConsoleError', 0],
            WorkerStartedEvent::class => ['onWorkerStarted', 0],
            WorkerRunningEvent::class => ['onWorkerRunning', -10],
            WorkerStoppedEvent::class => ['onWorkerStopped', 0],
        ];
    }

    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        if (!$command instanceof LlmFiberConsumeCommand) {
            return;
        }

        $this->armedCommands[$command] = true;
    }

    public function onWorkerStarted(WorkerStartedEvent $event): void
    {
        $command = $this->takeArmedCommand();
        if (null === $command) {
            return;
        }

        $names = $event->getWorker()->getMetadata()->getTransportNames();
        if (!\in_array($names, [['llm'], ['tool', 'agent']], true)) {
            return;
        }
        $owner = null;
        $queues = [];
        foreach ($names as $name) {
            $receiver = $this->receiverLocator->get($name);
            if (!$receiver instanceof Transport) {
                return;
            }
            if (null !== $owner && $owner->brokerEndpoint() !== $receiver->brokerEndpoint()) {
                throw new \LogicException('Fiber execution receivers must share one queue broker.');
            }
            $owner ??= $receiver;
            $queues[] = $receiver->queueName();
        }

        $stop = new DeferredCancellation();
        $this->stopsByWorker[$event->getWorker()] = $stop;
        $this->stopsByCommand[$command] = $stop;
        $this->waitsByWorker[$event->getWorker()] = static fn (int $timeoutMilliseconds, Cancellation $cancellation): bool => $owner->waitAny($queues, $timeoutMilliseconds, $cancellation);
    }

    public function onWorkerRunning(WorkerRunningEvent $event): void
    {
        $wait = $this->waitsByWorker[$event->getWorker()] ?? null;
        $stop = $this->stopsByWorker[$event->getWorker()] ?? null;
        if (null === $wait || null === $stop) {
            return;
        }
        if (!$event->isWorkerIdle()) {
            return;
        }
        if ($stop->isCancelled()) {
            return;
        }

        try {
            $wait(self::DEFAULT_WAIT_BUDGET_MILLISECONDS, $stop->getCancellation());
        } catch (CancelledException) {
            return;
        } catch (TransportException|ClientTransportException $error) {
            // Transport::wait wraps Amp cancellation as TransportException.
            if ($error->getPrevious() instanceof CancelledException) {
                return;
            }

            throw $error instanceof TransportException ? $error : new TransportException('Queue wait failed.', 0, $error);
        }
    }

    public function onConsoleSignal(ConsoleSignalEvent $event): void
    {
        if (\defined('SIGALRM') && \SIGALRM === $event->getHandlingSignal()) {
            return;
        }

        $command = $event->getCommand();
        if (null === $command) {
            return;
        }
        $stop = $this->stopsByCommand[$command] ?? null;
        $stop?->cancel();
    }

    public function onWorkerStopped(WorkerStoppedEvent $event): void
    {
        $stop = $this->stopsByWorker[$event->getWorker()] ?? null;
        if (null === $stop) {
            return;
        }
        unset($this->stopsByWorker[$event->getWorker()], $this->waitsByWorker[$event->getWorker()]);
        foreach ($this->stopsByCommand as $command => $commandStop) {
            if ($commandStop === $stop) {
                unset($this->stopsByCommand[$command]);
            }
        }
        $stop->cancel();
    }

    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        $this->releaseCommand($event->getCommand());
    }

    public function onConsoleError(ConsoleErrorEvent $event): void
    {
        $this->releaseCommand($event->getCommand());
    }

    private function takeArmedCommand(): ?Command
    {
        foreach ($this->armedCommands as $command => $_) {
            unset($this->armedCommands[$command]);

            return $command;
        }

        return null;
    }

    private function releaseCommand(?Command $command): void
    {
        if (null === $command) {
            foreach ($this->armedCommands as $armed => $_) {
                unset($this->armedCommands[$armed]);
            }
            foreach ($this->stopsByCommand as $sessionCommand => $stop) {
                unset($this->stopsByCommand[$sessionCommand]);
                $stop->cancel();
            }

            return;
        }

        unset($this->armedCommands[$command]);
        $stop = $this->stopsByCommand[$command] ?? null;
        if (null === $stop) {
            return;
        }
        unset($this->stopsByCommand[$command]);
        foreach ($this->stopsByWorker as $worker => $workerStop) {
            if ($workerStop === $stop) {
                unset($this->stopsByWorker[$worker], $this->waitsByWorker[$worker]);
            }
        }
        $stop->cancel();
    }
}
