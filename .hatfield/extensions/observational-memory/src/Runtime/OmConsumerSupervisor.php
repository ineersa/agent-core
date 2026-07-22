<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

use Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Extension-owned supervisor for one OM consumer process.
 *
 * One supervisor instance per owning HeadlessController. Does not use Hatfield
 * ConsumerSupervisor or messenger:consume transports.
 *
 * Callers store the launch specification via start() and then call the
 * argument-free supervise() periodically from the controller Revolt loop.
 */
final class OmConsumerSupervisor
{
    private const int SHUTDOWN_GRACE_SECONDS = 5;

    private const int MAX_RESTARTS = 3;

    private const int RESTART_WINDOW_SECONDS = 60;

    private ?Process $process = null;

    private bool $shuttingDown = false;

    private bool $started = false;

    /** @var list<string> */
    private array $applicationCommand = [];

    private string $runtimeCwd = '';

    private string $sessionId = '';

    private string $databasePath = '';

    /** @var list<float> */
    private array $restartTimestamps = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $applicationCommand
     */
    public function start(
        array $applicationCommand,
        string $runtimeCwd,
        string $sessionId,
        string $databasePath,
    ): void {
        if (null !== $this->process && $this->process->isRunning()) {
            return;
        }

        $this->applicationCommand = $applicationCommand;
        $this->runtimeCwd = $runtimeCwd;
        $this->sessionId = $sessionId;
        $this->databasePath = $databasePath;
        $this->started = true;
        $this->shuttingDown = false;
        $this->launch();
    }

    public function stop(): void
    {
        $this->shuttingDown = true;
        $process = $this->process;
        $this->process = null;

        if (null === $process) {
            return;
        }

        if (!$process->isRunning()) {
            return;
        }

        $pid = $process->getPid();
        $this->logger->info('om.supervisor.stop', [
            'component' => 'observational_memory',
            'event_type' => 'om.supervisor.stop',
            'session_id' => $this->sessionId,
            'pid' => $pid,
        ]);

        // Process::stop() sends SIGTERM, waits up to the grace period, then
        // escalates to SIGKILL internally when the process is still alive.
        $process->stop(self::SHUTDOWN_GRACE_SECONDS);
    }

    /**
     * Periodic health check using the launch specification stored by start().
     */
    public function supervise(): void
    {
        if ($this->shuttingDown || !$this->started) {
            return;
        }

        $process = $this->process;
        if (null === $process) {
            return;
        }

        if ($process->isRunning()) {
            return;
        }

        $exitCode = $process->getExitCode();
        $this->process = null;

        if (0 === $exitCode) {
            $this->logger->info('om.supervisor.recycle', [
                'component' => 'observational_memory',
                'event_type' => 'om.supervisor.recycle',
                'session_id' => $this->sessionId,
                'exit_code' => $exitCode,
            ]);
            $this->restartTimestamps = [];
            $this->launch();

            return;
        }

        $this->logger->warning('om.supervisor.abnormal_exit', [
            'component' => 'observational_memory',
            'event_type' => 'om.supervisor.abnormal_exit',
            'session_id' => $this->sessionId,
            'exit_code' => $exitCode,
        ]);

        if (!$this->mayRestart()) {
            $this->logger->error('om.supervisor.abandoned', [
                'component' => 'observational_memory',
                'event_type' => 'om.supervisor.abandoned',
                'session_id' => $this->sessionId,
            ]);

            return;
        }

        $this->restartTimestamps[] = microtime(true);
        $this->launch();
    }

    private function launch(): void
    {
        $command = [
            ...$this->applicationCommand,
            'extension:run',
            ObservationalMemoryExtension::class,
            ObservationalMemoryExtension::ENTRYPOINT_CONSUME,
            '--no-interaction',
        ];

        $env = $_ENV;
        $env['HATFIELD_OM_CONSUMER'] = '1';
        $env['HATFIELD_OM_DATABASE_PATH'] = $this->databasePath;
        // Owning controller PID so the child can exit if the parent dies
        // (SIGKILL / abrupt death) without an ordered RuntimeStoppingEvent.
        $env['HATFIELD_OM_PARENT_PID'] = (string) getmypid();
        // Consumer must not emit Hatfield messenger stdout protocol noise.
        $env['HATFIELD_CONSUMER_STDOUT_EVENTS'] = '0';

        try {
            $process = new Process(
                $command,
                cwd: $this->runtimeCwd,
                env: $env,
                timeout: null,
            );
            $process->start();
        } catch (\Throwable $e) {
            $this->logger->error('om.supervisor.launch_failed', [
                'component' => 'observational_memory',
                'event_type' => 'om.supervisor.launch_failed',
                'session_id' => $this->sessionId,
                'exception_class' => $e::class,
            ]);

            return;
        }

        $this->process = $process;
        $this->logger->info('om.supervisor.launched', [
            'component' => 'observational_memory',
            'event_type' => 'om.supervisor.launched',
            'session_id' => $this->sessionId,
            'pid' => $process->getPid(),
            'parent_pid' => getmypid(),
        ]);
    }

    private function mayRestart(): bool
    {
        $now = microtime(true);
        $this->restartTimestamps = array_values(array_filter(
            $this->restartTimestamps,
            static fn (float $ts): bool => ($now - $ts) <= self::RESTART_WINDOW_SECONDS,
        ));

        return \count($this->restartTimestamps) < self::MAX_RESTARTS;
    }
}
