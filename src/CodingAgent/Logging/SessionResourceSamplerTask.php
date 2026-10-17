<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Logging;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/** Opt-in session resource trends for castor run:agent, not complete process accounting. */
#[AsSchedule('default')]
final class SessionResourceSamplerTask implements ScheduleProviderInterface
{
    public const int INTERVAL_SECONDS = 60;
    public const string METRIC_CPU_PERCENT = 'hatfield.session.cpu_percent';
    public const string METRIC_RSS_BYTES = 'hatfield.session.rss_bytes';
    public const string METRIC_PROCESS_COUNT = 'hatfield.session.process_count';

    public function __construct(
        private readonly bool $enabled,
        private readonly string $sessionId,
        private readonly string $telemetryEnv,
        private readonly string $dogstatsdHost,
        private readonly int $dogstatsdPort,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsMessageHandler]
    public function __invoke(SessionResourceSampleMessage $_sample): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $phase = 'tree';
        try {
            $pids = $this->sessionTree();
            $phase = 'top';
            $output = $this->run(['top', '-b', '-n', '2', '-d', '0.2', '-e', 'k', '-w', '512']);
            // The first refresh is not an interval CPU measurement.
            $frames = preg_split('/^top - /m', $output);
            if (false === $frames || \count($frames) < 3) {
                throw new \RuntimeException('Missing second top refresh.');
            }
            $sample = $this->aggregate($frames[array_key_last($frames)], $pids);
            $phase = 'udp';
            $this->send($sample);
            $this->logger->info('session.resource_sample.sent', [
                'component' => 'session_resource_sampler',
                'event_type' => 'session.resource_sample.sent',
                'run_id' => $this->sessionId,
                'session_id' => $this->sessionId,
            ] + $sample);
        } catch (\Throwable $exception) {
            // Intentional local degradation: telemetry must not interrupt scheduled work.
            $this->logger->warning('session.resource_sample.failed', [
                'component' => 'session_resource_sampler',
                'event_type' => 'session.resource_sample.failed',
                'run_id' => $this->sessionId,
                'session_id' => $this->sessionId,
                'phase' => $phase,
                'exception_class' => $exception::class,
            ]);
        }
    }

    public function getSchedule(): Schedule
    {
        $schedule = new Schedule();
        if ($this->isEnabled()) {
            $schedule->add(RecurringMessage::every(self::INTERVAL_SECONDS, new SessionResourceSampleMessage()));
        }

        return $schedule;
    }

    private function isEnabled(): bool
    {
        return $this->enabled && '' !== $this->sessionId && 'unknown' !== $this->sessionId;
    }

    /** @return array<int, true> */
    private function sessionTree(): array
    {
        $parents = $children = [];
        foreach (explode("\n", $this->run(['ps', '-e', '-o', 'pid=,ppid='])) as $line) {
            if (1 === preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $line, $match)) {
                $pid = (int) $match[1];
                $parent = (int) $match[2];
                $parents[$pid] = $parent;
                $children[$parent][] = $pid;
            }
        }

        // This handler runs in the scheduler consumer: consumer -> controller -> TUI.
        // Stop at the TUI, not its Castor/shell/tmux ancestors shared with other work.
        $controller = $parents[(int) getmypid()] ?? 0;
        $root = $parents[$controller] ?? 0;
        if ($root <= 1) {
            throw new \RuntimeException('Session process ancestry unavailable.');
        }
        $pending = [$root];
        $owned = [];
        while ([] !== $pending) {
            $pid = array_pop($pending);
            if (isset($owned[$pid])) {
                continue;
            }
            $owned[$pid] = true;
            foreach ($children[$pid] ?? [] as $child) {
                $pending[] = $child;
            }
        }

        return $owned;
    }

    /** @param list<string> $command */
    private function run(array $command): string
    {
        // Ignore personal top configuration, including thread mode and altered fields.
        $process = new Process($command, env: [
            'LC_ALL' => 'C',
            'HOME' => '/dev/null',
            'XDG_CONFIG_HOME' => '/dev/null',
        ], timeout: 5);

        return $process->mustRun()->getOutput();
    }

    /**
     * @param array<int, true> $pids
     *
     * @return array{cpu_percent: float, rss_bytes: int, process_count: int}
     */
    private function aggregate(string $frame, array $pids): array
    {
        $columns = $rows = [];
        foreach (explode("\n", $frame) as $line) {
            $fields = preg_split('/\s+/', trim($line));
            if (false === $fields) {
                continue;
            }
            if ('PID' === $fields[0]) {
                $columns = array_flip($fields);
                continue;
            }
            if (!isset($columns['PID'], $columns['RES'], $columns['%CPU'])) {
                continue;
            }
            $pid = (int) ($fields[$columns['PID']] ?? 0);
            if (!isset($pids[$pid])) {
                continue;
            }
            $rss = $fields[$columns['RES']] ?? '';
            $cpu = $fields[$columns['%CPU']] ?? '';
            if (!is_numeric($rss) || !is_numeric($cpu)) {
                throw new \RuntimeException('Unreadable top process measurements.');
            }
            $rows[$pid] = ['cpu' => (float) $cpu, 'rss' => (int) round((float) $rss * 1024)];
        }
        if ([] === $rows) {
            throw new \RuntimeException('No owned processes in top refresh.');
        }

        return [
            'cpu_percent' => round(array_sum(array_column($rows, 'cpu')), 3),
            'rss_bytes' => array_sum(array_column($rows, 'rss')),
            'process_count' => \count($rows),
        ];
    }

    /** @param array{cpu_percent: float, rss_bytes: int, process_count: int} $sample */
    private function send(array $sample): void
    {
        $tag = static fn (string $value): string => preg_replace('/[^A-Za-z0-9_.-]/', '_', $value) ?? '';
        $tags = '|g|#service:hatfield,env:'.$tag($this->telemetryEnv).',session_id:'.$tag($this->sessionId)."\n";
        $payload = self::METRIC_CPU_PERCENT.':'.$sample['cpu_percent'].$tags
            .self::METRIC_RSS_BYTES.':'.$sample['rss_bytes'].$tags
            .self::METRIC_PROCESS_COUNT.':'.$sample['process_count'].$tags;
        $socket = @stream_socket_client(
            \sprintf('udp://%s:%d', $this->dogstatsdHost, $this->dogstatsdPort),
            $errno,
            $error,
            1,
        );
        if (false === $socket) {
            throw new \RuntimeException('DogStatsD socket unavailable.');
        }
        try {
            if (!stream_set_blocking($socket, false) || @fwrite($socket, $payload) !== \strlen($payload)) {
                throw new \RuntimeException('DogStatsD datagram not sent.');
            }
        } finally {
            fclose($socket);
        }
    }
}

/** The scheduler carries no session state; the owning worker supplies its identity. */
final class SessionResourceSampleMessage
{
}
