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

/**
 * Opt-in once-per-minute session resource sample for castor run:agent.
 *
 * When enabled, registers one recurring job on the existing default schedule.
 * The job aggregates CPU/RSS/process count for processes that carry the owning
 * HATFIELD_SESSION_ID (plus their ancestors, so the TUI root is included) and
 * publishes DogStatsD gauges over UDP. Unavailable telemetry degrades locally.
 */
#[AsSchedule('default')]
final class SessionResourceSamplerTask implements ScheduleProviderInterface
{
    public const int INTERVAL_SECONDS = 60;

    public const string METRIC_CPU_PERCENT = 'hatfield.session.cpu_percent';

    public const string METRIC_RSS_BYTES = 'hatfield.session.rss_bytes';

    public const string METRIC_PROCESS_COUNT = 'hatfield.session.process_count';

    private const int TOP_PID_LIMIT = 20;

    private const float TOP_DELAY_SECONDS = 0.2;

    private const int TOP_TIMEOUT_SECONDS = 5;

    private const int UDP_CONNECT_TIMEOUT_SECONDS = 1;

    public function __construct(
        private readonly bool $enabled,
        private readonly string $sessionId,
        private readonly string $telemetryEnv,
        private readonly string $dogstatsdHost,
        private readonly int $dogstatsdPort,
        private readonly LoggerInterface $logger,
        private readonly string $procRoot = '/proc',
        private readonly string $topBinary = '/usr/bin/top',
    ) {
    }

    public function getSchedule(): Schedule
    {
        $schedule = new Schedule();
        if (!$this->enabled || !$this->hasUsableSessionId()) {
            return $schedule;
        }

        return $schedule->add(RecurringMessage::every(
            self::INTERVAL_SECONDS,
            new SessionResourceSampleMessage(),
        ));
    }

    #[AsMessageHandler]
    public function __invoke(SessionResourceSampleMessage $_sample): void
    {
        if (!$this->enabled || !$this->hasUsableSessionId()) {
            return;
        }

        try {
            $pids = $this->discoverSessionPids();
            if ([] === $pids) {
                $this->logger->info('session.resource_sample.skipped', [
                    'component' => 'session_resource_sampler',
                    'event_type' => 'session.resource_sample.skipped',
                    'session_id' => $this->sessionId,
                    'reason' => 'no_matching_processes',
                ]);

                return;
            }

            $sample = $this->sampleWithTop($pids);
            if (null === $sample) {
                return;
            }

            $this->publishGauges($sample);
            $this->logger->info('session.resource_sample.published', [
                'component' => 'session_resource_sampler',
                'event_type' => 'session.resource_sample.published',
                'session_id' => $this->sessionId,
                'process_count' => $sample['process_count'],
                'sampled_process_count' => $sample['sampled_process_count'],
                'cpu_percent' => $sample['cpu_percent'],
                'rss_bytes' => $sample['rss_bytes'],
            ]);
        } catch (\Throwable $exception) {
            // Intentional local degradation: sampling must not interrupt scheduler work.
            $this->logger->warning('session.resource_sample.failed', [
                'component' => 'session_resource_sampler',
                'event_type' => 'session.resource_sample.failed',
                'session_id' => $this->sessionId,
                'exception_class' => $exception::class,
            ]);
        }
    }

    private function hasUsableSessionId(): bool
    {
        return '' !== $this->sessionId && 'unknown' !== $this->sessionId;
    }

    /**
     * @return list<int>
     */
    private function discoverSessionPids(): array
    {
        $marker = 'HATFIELD_SESSION_ID='.$this->sessionId;
        $matched = [];
        $parents = [];

        foreach ($this->listProcPids() as $pid) {
            $environ = $this->readProcFile($pid, 'environ');
            if (null === $environ) {
                continue;
            }
            if (!$this->environContainsMarker($environ, $marker)) {
                continue;
            }
            $matched[$pid] = true;
            $ppid = $this->readPpid($pid);
            if (null !== $ppid) {
                $parents[$pid] = $ppid;
            }
        }

        if ([] === $matched) {
            return [];
        }

        $included = $matched;
        foreach (array_keys($matched) as $pid) {
            $current = $pid;
            for ($depth = 0; $depth < 64; ++$depth) {
                $ppid = $parents[$current] ?? $this->readPpid($current);
                if (null === $ppid || $ppid <= 1) {
                    break;
                }
                $parents[$current] = $ppid;
                if (isset($included[$ppid])) {
                    break;
                }
                $included[$ppid] = true;
                $current = $ppid;
            }
        }

        /** @var list<int> $pids */
        $pids = array_map(static fn (int|string $pid): int => (int) $pid, array_keys($included));
        sort($pids);

        return $pids;
    }

    /**
     * @return list<int>
     */
    private function listProcPids(): array
    {
        $entries = @scandir($this->procRoot);
        if (false === $entries) {
            return [];
        }

        $pids = [];
        foreach ($entries as $entry) {
            if (1 !== preg_match('/^\d+$/', $entry)) {
                continue;
            }
            $pids[] = (int) $entry;
        }

        return $pids;
    }

    private function environContainsMarker(string $environ, string $marker): bool
    {
        foreach (explode("\0", $environ) as $entry) {
            if ($entry === $marker) {
                return true;
            }
        }

        return false;
    }

    private function readPpid(int $pid): ?int
    {
        $stat = $this->readProcFile($pid, 'stat');
        if (null === $stat) {
            return null;
        }

        $closeParen = strrpos($stat, ')');
        if (false === $closeParen) {
            return null;
        }

        $rest = trim(substr($stat, $closeParen + 1));
        $fields = preg_split('/\s+/', $rest);
        if (false === $fields) {
            return null;
        }
        // After ")": state ppid ...
        if (!isset($fields[1]) || !is_numeric($fields[1])) {
            return null;
        }

        return (int) $fields[1];
    }

    private function readProcFile(int $pid, string $name): ?string
    {
        $path = $this->procRoot.'/'.$pid.'/'.$name;
        $contents = @file_get_contents($path);
        if (false === $contents) {
            return null;
        }

        return $contents;
    }

    /**
     * @param list<int> $pids
     *
     * @return array{
     *     cpu_percent: float,
     *     rss_bytes: int,
     *     process_count: int,
     *     discovered_process_count: int,
     *     sampled_process_count: int
     * }|null
     */
    private function sampleWithTop(array $pids): ?array
    {
        $cpuPercent = 0.0;
        $rssBytes = 0;
        $sampled = 0;
        $chunks = array_chunk($pids, self::TOP_PID_LIMIT);

        foreach ($chunks as $chunk) {
            $frame = $this->runTopFrame($chunk);
            if (null === $frame) {
                return null;
            }

            foreach ($this->parseTopFrame($frame) as $row) {
                $cpuPercent += $row['cpu_percent'];
                $rssBytes += $row['rss_bytes'];
                ++$sampled;
            }
        }

        return [
            'cpu_percent' => round($cpuPercent, 3),
            'rss_bytes' => $rssBytes,
            'process_count' => $sampled,
            'discovered_process_count' => \count($pids),
            'sampled_process_count' => $sampled,
        ];
    }

    /**
     * @param list<int> $pids
     */
    private function runTopFrame(array $pids): ?string
    {
        if ([] === $pids) {
            return '';
        }

        $process = new Process([
            $this->topBinary,
            '-b',
            '-n',
            '2',
            '-d',
            (string) self::TOP_DELAY_SECONDS,
            '-w',
            '512',
            '-p',
            implode(',', array_map(static fn (int $pid): string => (string) $pid, $pids)),
        ]);
        $process->setTimeout(self::TOP_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            $this->logger->warning('session.resource_sample.top_failed', [
                'component' => 'session_resource_sampler',
                'event_type' => 'session.resource_sample.top_failed',
                'session_id' => $this->sessionId,
                'exception_class' => $exception::class,
            ]);

            return null;
        }

        if (!$process->isSuccessful()) {
            $this->logger->warning('session.resource_sample.top_failed', [
                'component' => 'session_resource_sampler',
                'event_type' => 'session.resource_sample.top_failed',
                'session_id' => $this->sessionId,
                'exit_code' => $process->getExitCode(),
            ]);

            return null;
        }

        return $this->secondTopFrame($process->getOutput());
    }

    private function secondTopFrame(string $output): ?string
    {
        $split = preg_split('/(?=^top - )/m', trim($output));
        $frames = [];
        if (false !== $split) {
            foreach ($split as $frame) {
                if ('' !== trim($frame)) {
                    $frames[] = $frame;
                }
            }
        }
        if (\count($frames) < 2) {
            $this->logger->warning('session.resource_sample.top_failed', [
                'component' => 'session_resource_sampler',
                'event_type' => 'session.resource_sample.top_failed',
                'session_id' => $this->sessionId,
                'reason' => 'missing_interval_frame',
            ]);

            return null;
        }

        return $frames[array_key_last($frames)];
    }

    /**
     * @return list<array{pid: int, cpu_percent: float, rss_bytes: int}>
     */
    private function parseTopFrame(string $frame): array
    {
        $rows = [];
        $headerSeen = false;
        $lines = preg_split('/\R/', $frame);
        if (false === $lines) {
            return [];
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            if (!$headerSeen) {
                if (str_starts_with($line, 'PID ') || str_starts_with($line, 'PID USER')) {
                    $headerSeen = true;
                }
                continue;
            }

            if (1 !== preg_match(
                '/^(?P<pid>\d+)\s+\S+\s+\S+\s+\S+\s+\S+\s+(?P<res>\S+)\s+\S+\s+\S+\s+(?P<cpu>\S+)\s+/',
                $line,
                $matches,
            )) {
                continue;
            }

            $rssBytes = $this->parseTopMemoryToBytes($matches['res']);
            if (null === $rssBytes) {
                continue;
            }
            if (!is_numeric($matches['cpu'])) {
                continue;
            }

            $rows[] = [
                'pid' => (int) $matches['pid'],
                'cpu_percent' => (float) $matches['cpu'],
                'rss_bytes' => $rssBytes,
            ];
        }

        return $rows;
    }

    private function parseTopMemoryToBytes(string $value): ?int
    {
        if (1 === preg_match('/^(?P<number>\d+(?:\.\d+)?)(?P<suffix>[kKmMgGtT])?$/', $value, $matches)) {
            $number = (float) $matches['number'];
            $suffix = strtolower($matches['suffix'] ?? '');
            $multiplier = match ($suffix) {
                '' => 1024.0, // top RES defaults to KiB when unscaled
                'k' => 1024.0,
                'm' => 1024.0 ** 2,
                'g' => 1024.0 ** 3,
                't' => 1024.0 ** 4,
                default => null,
            };
            if (null === $multiplier) {
                return null;
            }

            return (int) round($number * $multiplier);
        }

        return null;
    }

    /**
     * @param array{
     *     cpu_percent: float,
     *     rss_bytes: int,
     *     process_count: int,
     *     discovered_process_count: int,
     *     sampled_process_count: int
     * } $sample
     */
    private function publishGauges(array $sample): void
    {
        $tags = implode(',', [
            'service:hatfield',
            'env:'.$this->sanitizeTagValue($this->telemetryEnv),
            'session_id:'.$this->sanitizeTagValue($this->sessionId),
        ]);

        $payload = implode("\n", [
            self::METRIC_CPU_PERCENT.':'.$sample['cpu_percent'].'|g|#'.$tags,
            self::METRIC_RSS_BYTES.':'.$sample['rss_bytes'].'|g|#'.$tags,
            self::METRIC_PROCESS_COUNT.':'.$sample['process_count'].'|g|#'.$tags,
        ])."\n";

        $address = sprintf('udp://%s:%d', $this->dogstatsdHost, $this->dogstatsdPort);
        $socket = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            self::UDP_CONNECT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
        );
        if (false === $socket) {
            $this->logger->warning('session.resource_sample.udp_failed', [
                'component' => 'session_resource_sampler',
                'event_type' => 'session.resource_sample.udp_failed',
                'session_id' => $this->sessionId,
                'errno' => $errno,
            ]);

            return;
        }

        stream_set_blocking($socket, false);
        $written = @fwrite($socket, $payload);
        fclose($socket);

        if (false === $written || $written < \strlen($payload)) {
            $this->logger->warning('session.resource_sample.udp_failed', [
                'component' => 'session_resource_sampler',
                'event_type' => 'session.resource_sample.udp_failed',
                'session_id' => $this->sessionId,
                'reason' => 'short_write',
            ]);
        }
    }

    private function sanitizeTagValue(string $value): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9_.-]/', '_', $value) ?? '';

        return '' !== $sanitized ? $sanitized : 'unknown';
    }
}

/**
 * Marker message for the opt-in session resource sample.
 *
 * Kept in the same production file so schedule registration and handling stay
 * one class surface for this Castor-only sampler.
 */
final class SessionResourceSampleMessage
{
}
