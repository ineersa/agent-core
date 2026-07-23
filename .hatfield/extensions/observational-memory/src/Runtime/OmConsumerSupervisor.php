<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Extension-owned supervisor for one OM package messenger:consume process.
 *
 * Launches the package's own bin/console — never Hatfield's console or
 * extension:run. One supervisor instance per owning interactive agent process.
 */
final class OmConsumerSupervisor
{
    private const int SHUTDOWN_GRACE_SECONDS = 5;

    private const int MAX_RESTARTS = 3;

    private const int RESTART_WINDOW_SECONDS = 60;

    private ?Process $process = null;

    private bool $shuttingDown = false;

    private bool $started = false;

    private string $consolePath = '';

    private string $packageRoot = '';

    private string $sessionId = '';

    private string $databasePath = '';

    /** @var list<float> */
    private array $restartTimestamps = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function start(
        string $consolePath,
        string $packageRoot,
        string $sessionId,
        string $databasePath,
    ): void {
        if (null !== $this->process && $this->process->isRunning()) {
            return;
        }

        $this->consolePath = $consolePath;
        $this->packageRoot = $packageRoot;
        $this->sessionId = $sessionId;
        $this->databasePath = $databasePath;
        $this->started = true;
        $this->shuttingDown = false;

        $this->prepareDatabase();
        $this->launch();
    }

    public function stop(): void
    {
        $this->shuttingDown = true;
        $process = $this->process;
        $this->process = null;

        if (null === $process || !$process->isRunning()) {
            return;
        }

        $this->logger->info('om.supervisor.stop', [
            'component' => 'observational_memory',
            'event_type' => 'om.supervisor.stop',
            'session_id' => $this->sessionId,
            'pid' => $process->getPid(),
        ]);

        $process->stop(self::SHUTDOWN_GRACE_SECONDS);
    }

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

    /**
     * @return list<string>
     */
    public function consumerCommand(): array
    {
        return [
            $this->phpBinary(),
            $this->consolePath,
            'messenger:consume',
            'om_compaction',
            'om_observation',
            '--time-limit=3600',
            '--memory-limit=256M',
            '--no-interaction',
        ];
    }

    private function prepareDatabase(): void
    {
        $dataDir = \dirname($this->databasePath);
        if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
            throw new \RuntimeException(\sprintf('Unable to create OM data directory: %s', $dataDir));
        }

        $env = $this->childEnv();
        $php = $this->phpBinary();

        $migrate = new Process(
            [$php, $this->consolePath, 'om:migrate', '--no-interaction'],
            cwd: $this->packageRoot,
            env: $env,
            timeout: 60,
        );
        $migrate->run();
        if (!$migrate->isSuccessful()) {
            throw new \RuntimeException(\sprintf('OM om:migrate failed (exit %s).', (string) $migrate->getExitCode()));
        }

        $setup = new Process(
            [$php, $this->consolePath, 'messenger:setup-transports', '--no-interaction'],
            cwd: $this->packageRoot,
            env: $env,
            timeout: 60,
        );
        $setup->run();
        if (!$setup->isSuccessful()) {
            // No-op setups must exit 0; nonzero is always a hard failure.
            throw new \RuntimeException(\sprintf('OM messenger:setup-transports failed (exit %s).', (string) $setup->getExitCode()));
        }
    }

    private function launch(): void
    {
        try {
            $process = new Process(
                $this->consumerCommand(),
                cwd: $this->packageRoot,
                env: $this->childEnv(),
                timeout: null,
            );
            // Long-running messenger:consume is never drained programmatically.
            // disableOutput prevents unbounded pipe buffering over --time-limit.
            $process->disableOutput();
            $process->start();
        } catch (\Throwable $e) {
            // Structured diagnostics only — no raw process output (disabled).
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
            'console' => $this->consolePath,
        ]);
    }

    /**
     * Build env overrides for OM package children.
     *
     * Symfony Process merges the OS default environment after this array
     * (Process::start → getDefaultEnv). Entries set to false are omitted from
     * the final env block and therefore remove inherited variables — unset()
     * alone cannot suppress OS-inherited Hatfield process-role markers.
     *
     * Credentials and model-provider config are intentionally preserved for
     * later OM Observer/Reflector model work.
     *
     * @return array<string, string|false>
     */
    private function childEnv(): array
    {
        $env = [];
        foreach ($_ENV as $key => $value) {
            if (\is_string($key) && (\is_string($value) || \is_int($value) || \is_float($value) || \is_bool($value))) {
                $env[$key] = (string) $value;
            }
        }

        $env['APP_ENV'] = $env['APP_ENV'] ?? 'prod';
        $env['APP_DEBUG'] = $env['APP_DEBUG'] ?? '0';
        $env['OM_DATABASE_PATH'] = $this->databasePath;
        $env['OM_PARENT_PID'] = (string) getmypid();
        // Isolate compiled container/cache per project database — packageRoot/var/cache
        // would bake the first OM_DATABASE_PATH into a shared container dump.
        $dataDir = \dirname($this->databasePath);
        $env['OM_CACHE_DIR'] = $dataDir.'/cache';
        $env['OM_LOG_DIR'] = $dataDir.'/log';

        // Strip Hatfield process-role/consumer markers from the standalone OM
        // kernel. false removes the key after Process merges OS defaults.
        $env['HATFIELD_CONSUMER_STDOUT_EVENTS'] = false;
        $env['HATFIELD_OM_CONSUMER'] = false;

        return $env;
    }

    private function phpBinary(): string
    {
        $finder = new PhpExecutableFinder();
        $php = $finder->find(false);
        if (false === $php || '' === $php) {
            return \PHP_BINARY;
        }

        return $php;
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
