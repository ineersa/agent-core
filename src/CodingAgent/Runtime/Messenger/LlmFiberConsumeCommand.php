<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeStandaloneRepliesService;
use Ineersa\SqliteQueue\Messenger\Transport;
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
use Symfony\Component\Messenger\EventListener\StopWorkerOnMemoryLimitListener;
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

    private ?Transport $replyReceiver = null;

    /**
     * @param EventDispatcherInterface&ListenerIntrospectionInterface $eventDispatcher
     */
    public function __construct(
        private readonly RoutableMessageBus $routableBus,
        private readonly ContainerInterface $receiverLocator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly AppConfig $appConfig,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?CodeModeStandaloneRepliesService $codeModeReplies = null,
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

    public function replyReceiver(): ?Transport
    {
        return $this->replyReceiver;
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
        $transportNames = 'tool' === $transport ? ['tool', 'agent', 'code_mode_requests'] : ['llm'];
        $receivers = [];
        foreach ($transportNames as $name) {
            if (!$this->receiverLocator->has($name)) {
                throw new \RuntimeException(\sprintf('The receiver "%s" does not exist.', $name));
            }
            /** @var ReceiverInterface $receiver */
            $receiver = $this->receiverLocator->get($name);
            $receivers[$name] = $receiver;
        }
        $slot = getenv('HATFIELD_CODE_MODE_REPLY_SLOT');
        if ('tool' === $transport && false !== $slot && null !== $this->codeModeReplies) {
            $this->replyReceiver = $this->codeModeReplies->open($slot);
            $receivers = [CodeModeStandaloneRepliesService::RECEIVER => $this->replyReceiver] + $receivers;
        }
        $concurrency = 'tool' === $transport
            ? null
            : $this->appConfig->runtime->llmConcurrency;
        // Symfony stops receiving before its forced drain. Unblock reply waiters
        // then, but keep the transport open for in-flight acknowledgements.
        $strategy = new FiberMessageExecutionStrategy($this->routableBus, $concurrency,
            beforeForcedDrain: null === $this->replyReceiver ? null : $this->codeModeReplies->stopWaiting(...));

        $memoryLimit = (string) ($input->getOption('memory-limit') ?? self::MEMORY_LIMIT);
        $eventDispatcher = new ScopedEventDispatcher($this->eventDispatcher);
        $eventDispatcher->addSubscriber(new StopWorkerOnMemoryLimitListener(
            self::memoryLimitBytes($memoryLimit),
            $this->logger,
        ));
        $io = new SymfonyStyle($input, $output);
        $io->success(\sprintf('Consuming messages from transports "%s" with Fiber concurrency %s.', implode(', ', $transportNames), null === $concurrency ? 'unlimited' : (string) $concurrency));
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
            // LLM fetch size matches admission. Tools use Worker's finite default
            // fetch size without imposing a limit on in-flight handlers.
            $options = ['sleep' => 0];
            if (null !== $concurrency) {
                $options['fetch_size'] = $concurrency;
            }
            $this->worker->run($options);
        } finally {
            if (null !== $keepaliveWatcher) {
                EventLoop::cancel($keepaliveWatcher);
            }
            $this->worker = null;
            $strategy->shutdown();
            if (null !== $this->replyReceiver) {
                $this->codeModeReplies?->close();
                $this->replyReceiver = null;
            }
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
