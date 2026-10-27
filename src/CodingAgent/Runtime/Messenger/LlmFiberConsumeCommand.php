<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\CodingAgent\Config\AppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\ScopedEventDispatcher;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMemoryLimitListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\EventDispatcher\ListenerIntrospectionInterface;

/**
 * Controller-owned execution consumer with same-process Fiber concurrency.
 *
 * Stock messenger:consume --concurrency still uses Amp child processes. This
 * command keeps overlapping execution handlers in one PHP runtime, omits
 * ResetServicesListener so shared services survive overlap, renews sqlite-queue
 * leases with a Revolt timer (not SIGALRM), and relies on
 * LlmFiberConsumeWaitSubscriber for sqlite-queue native idle WAIT.
 */
#[AsCommand(
    name: 'hatfield:messenger:consume-llm',
    description: 'Consume execution transport messages with same-process Fiber concurrency',
)]
final class LlmFiberConsumeCommand extends Command implements SignalableCommandInterface
{
    public const int KEEPALIVE_SECONDS = 5;

    public const string MEMORY_LIMIT = '256M';

    private bool $shouldStop = false;

    private ?Worker $worker = null;

    /**
     * @param EventDispatcherInterface&ListenerIntrospectionInterface $eventDispatcher
     */
    public function __construct(
        private readonly RoutableMessageBus $routableBus,
        private readonly ContainerInterface $receiverLocator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AppConfig $appConfig,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
        // Supervisor keeps these explicit in argv; configure them here so their
        // registration does not depend on the framework's protected hook.
        $this
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, 'Execution role: llm or tool (includes agent)', 'llm')
            ->addOption('memory-limit', 'm', InputOption::VALUE_REQUIRED, 'The memory limit the worker can consume', self::MEMORY_LIMIT)
            ->addOption('keepalive', null, InputOption::VALUE_OPTIONAL, 'Keepalive interval in seconds', self::KEEPALIVE_SECONDS);
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        // SIGALRM is intentionally omitted. Console pcntl_alarm only fires after
        // Amp/Revolt resumes, so leases expire during Fiber HTTP. Keepalive uses
        // EventLoop::repeat below instead.
        return SignalRegistry::isSupported()
            ? [\SIGTERM, \SIGINT, \SIGQUIT]
            : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if (null === $this->worker) {
            return false;
        }

        if ($this->shouldStop && \SIGINT === $signal) {
            return 0 === $previousExitCode ? 128 + $signal : $previousExitCode;
        }

        $this->logger?->info('Received signal {signal}.', [
            'signal' => $signal,
            'transport_names' => $this->worker->getMetadata()->getTransportNames(),
        ]);
        $this->worker->stop();
        $this->shouldStop = true;

        return false;
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        // Do not arm Application::setAlarmInterval here. Under Amp/UvDriver,
        // pcntl_alarm is deferred until the Fiber resumes, so SIGALRM cannot
        // renew leases during provider HTTP. execute() schedules EventLoop::repeat.
        unset($input, $output);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $transport = $input->getOption('transport');
        if (!\in_array($transport, ['llm', 'tool'], true)) {
            throw new \InvalidArgumentException('--transport must be llm or tool.');
        }
        // Keep consuming the durable agent queue after consolidation, including
        // messages accepted by an older controller before its last shutdown.
        $transportNames = 'tool' === $transport ? ['tool', 'agent'] : ['llm'];
        $receivers = [];
        foreach ($transportNames as $name) {
            if (!$this->receiverLocator->has($name)) {
                throw new \RuntimeException(\sprintf('The receiver "%s" does not exist.', $name));
            }
            /** @var ReceiverInterface $receiver */
            $receiver = $this->receiverLocator->get($name);
            $receivers[$name] = $receiver;
        }
        $concurrency = 'tool' === $transport
            ? $this->appConfig->runtime->toolConcurrency
            : $this->appConfig->runtime->llmConcurrency;
        $strategy = new FiberMessageExecutionStrategy($this->routableBus, $concurrency);

        $memoryLimit = (string) ($input->getOption('memory-limit') ?? self::MEMORY_LIMIT);
        $eventDispatcher = new ScopedEventDispatcher($this->eventDispatcher);
        $eventDispatcher->addSubscriber(new StopWorkerOnMemoryLimitListener(
            self::memoryLimitBytes($memoryLimit),
            $this->logger,
        ));
        if ('tool' === $transport) {
            // Bound retained ORM state even under continuous backlog. Native
            // stop drains active Fibers; the supervisor recycles exit-0 workers.
            // Never clear/reset here: siblings may hold managed entities at a yield.
            $eventDispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(256, $this->logger));
            $stopOnClosedManager = function (WorkerMessageHandledEvent|WorkerMessageFailedEvent $event): void {
                // ToolExecutor can turn a failed flush into a handled tool error.
                // A closed shared manager can also invalidate active siblings;
                // draining does not promise to rescue their in-memory changes.
                if (!$this->entityManager->isOpen()) {
                    $message = $event->getEnvelope()->getMessage();
                    $runId = $message instanceof AbstractAgentBusMessage ? $message->runId() : null;
                    $this->logger?->warning('runtime.tool_consumer_doctrine_closed', [
                        'run_id' => $runId,
                        'session_id' => $runId,
                        'component' => 'messenger.worker',
                        'event_type' => 'tool_consumer.doctrine_closed',
                        'receiver' => $event->getReceiverName(),
                    ]);
                    $this->worker?->stop();
                }
            };
            $eventDispatcher->addListener(WorkerMessageHandledEvent::class, $stopOnClosedManager);
            $eventDispatcher->addListener(WorkerMessageFailedEvent::class, $stopOnClosedManager);
        }

        $io = new SymfonyStyle($input, $output);
        $io->success(\sprintf('Consuming messages from transports "%s" with Fiber concurrency %d.', implode(', ', $transportNames), $concurrency));
        $io->comment('Per-message service reset is disabled for overlapping handlers.');
        $io->comment('Quit the worker with CONTROL-C.');

        $this->worker = new Worker(
            $receivers,
            $this->routableBus,
            $eventDispatcher,
            $this->logger,
            [],
            new Clock(),
            $strategy,
            [],
        );

        $keepaliveSeconds = null;
        if ($input->hasParameterOption('--keepalive')) {
            $keepaliveSeconds = (int) ($input->getOption('keepalive') ?? self::KEEPALIVE_SECONDS);
            if ($keepaliveSeconds < 1) {
                throw new \InvalidArgumentException('--keepalive must be a positive integer number of seconds.');
            }
        }

        $keepaliveWatcher = null;
        try {
            if (null !== $keepaliveSeconds) {
                $this->logger?->info('Arming Revolt keepalive timer.', [
                    'interval_seconds' => $keepaliveSeconds,
                    'transport_names' => $transportNames,
                ]);
                // Revolt timer renews while Amp HTTP Fibers are suspended.
                $keepaliveWatcher = EventLoop::repeat((float) $keepaliveSeconds, function () use ($keepaliveSeconds): void {
                    if (null === $this->worker) {
                        return;
                    }
                    // Distinct from Worker::keepalive's "Sending keepalive request." so tests
                    // can prove the Revolt timer fired, not only the native Worker path.
                    $this->logger?->debug('Renewing Fiber execution leases via Revolt timer.', [
                        'transport_names' => $this->worker->getMetadata()->getTransportNames(),
                    ]);
                    $this->worker->keepalive($keepaliveSeconds);
                });
                EventLoop::unreference($keepaliveWatcher);
            }

            // sleep=0: LlmFiberConsumeWaitSubscriber owns idle WAIT (Amp-safe).
            // fetch_size matches admission so the Worker can fill capacity quickly.
            $this->worker->run([
                'sleep' => 0,
                'fetch_size' => $concurrency,
            ]);
        } finally {
            if (null !== $keepaliveWatcher) {
                EventLoop::cancel($keepaliveWatcher);
            }
            $this->worker = null;
            $strategy->shutdown();
        }

        return Command::SUCCESS;
    }

    private static function memoryLimitBytes(string $memoryLimit): int
    {
        // Supervisor always passes Messenger-style shorthand (256M). PHP's
        // ini_parse_quantity accepts that form without copying Symfony's private parser.
        return ini_parse_quantity($memoryLimit);
    }
}
