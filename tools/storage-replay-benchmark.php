<?php

declare(strict_types=1);

use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Session\History\HistoryReplayFilter;
use Symfony\Component\Filesystem\Filesystem;

require dirname(__DIR__).'/vendor/autoload.php';

/**
 * Read-only, opt-in canonical replay measurement.
 *
 * Usage: tools/storage-replay-benchmark.php /absolute/path/to/.hatfield
 *
 * Output intentionally contains only fixed labels, scopes, statuses, and integer
 * aggregates. Candidate paths and identities are used transiently and never
 * cross the subprocess or report boundary. Each candidate runs in a fresh PHP
 * process, so peak-memory measurements are isolated from previous attempts.
 */

const BENCHMARK_SCHEMA = 'benchmark=1 privacy=aggregate-only measurement=container_boot_before_baseline_parse_filter_reduce_retained_state peak=memory_get_peak_usage_true_minus_baseline_after_memory_reset_peak_usage';

/** @return list<array{path: string, bytes: int}> */
function candidates(string $root, string $scope): array
{
    $sessions = $root.'/sessions';
    if (!is_dir($sessions)) {
        return [];
    }

    $candidates = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sessions, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || 'events.jsonl' !== $file->getFilename()) {
            continue;
        }
        $path = $file->getPathname();
        $isChild = str_contains($path, DIRECTORY_SEPARATOR.'artifacts'.DIRECTORY_SEPARATOR)
            && str_contains($path, DIRECTORY_SEPARATOR.'agents'.DIRECTORY_SEPARATOR);
        if (($isChild ? 'child' : 'parent') !== $scope) {
            continue;
        }
        $candidates[] = ['path' => $path, 'bytes' => $file->getSize()];
    }

    usort($candidates, static fn (array $left, array $right): int => $right['bytes'] <=> $left['bytes']);

    return $candidates;
}

/** @param list<array{path: string, bytes: int}> $candidates */
function datasetLine(string $scope, array $candidates): void
{
    $bytes = array_sum(array_column($candidates, 'bytes'));
    printf("DATASET scope=%s event_files=%d event_bytes=%d\n", $scope, count($candidates), $bytes);
}

/** @return array{events: int, duration_ms: int, peak_memory_delta_bytes: int}|null */
function replayCandidate(string $path): ?array
{
    $command = [PHP_BINARY, __FILE__, '--replay-candidate', $path];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return null;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if (0 !== $exitCode || !is_string($stdout) || 1 !== preg_match('/^events=(\d+) duration_ms=(\d+) peak_memory_delta_bytes=(\d+)\n$/D', $stdout, $matches)) {
        return null;
    }

    return [
        'events' => (int) $matches[1],
        'duration_ms' => (int) $matches[2],
        'peak_memory_delta_bytes' => (int) $matches[3],
    ];
}

function benchmark(string $root): int
{
    printf("SCHEMA %s\n", BENCHMARK_SCHEMA);
    foreach (['parent', 'child'] as $scope) {
        $scopeCandidates = candidates($root, $scope);
        datasetLine($scope, $scopeCandidates);
        $skipped = 0;
        foreach ($scopeCandidates as $candidate) {
            $result = replayCandidate($candidate['path']);
            if (null === $result) {
                ++$skipped;
                continue;
            }
            printf(
                "REPLAY_RESULT scope=%s status=measured candidates_skipped=%d events=%d duration_ms=%d peak_memory_delta_bytes=%d\n",
                $scope,
                $skipped,
                $result['events'],
                $result['duration_ms'],
                $result['peak_memory_delta_bytes'],
            );
            continue 2;
        }
        printf("REPLAY_RESULT scope=%s status=skipped_unreplayable candidates_skipped=%d events=0\n", $scope, $skipped);
    }

    return 0;
}

function replayOneCandidate(string $path): int
{
    $cacheDir = dirname(__DIR__).'/var/tmp/storage-replay-benchmark-'.getmypid();
    $filesystem = new Filesystem();
    $filesystem->mkdir($cacheDir);
    putenv('APP_ENV=test');
    putenv('APP_DEBUG=0');
    putenv('HATFIELD_CACHE_DIR='.$cacheDir);

    $kernel = null;
    $status = 1;
    try {
        $kernel = new Kernel('test', false);
        $kernel->boot();
        $container = $kernel->getContainer();
        if ($container->has('test.service_container')) {
            $container = $container->get('test.service_container');
        }
        /** @var EventPayloadNormalizer $normalizer */
        $normalizer = $container->get(EventPayloadNormalizer::class);
        /** @var ReplayEventPreparer $preparer */
        $preparer = $container->get(ReplayEventPreparer::class);
        /** @var HistoryReplayFilter $historyFilter */
        $historyFilter = $container->get(HistoryReplayFilter::class);
        /** @var RunStateReducer $reducer */
        $reducer = $container->get(RunStateReducer::class);

        gc_collect_cycles();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $baseline = memory_get_usage(true);
        $startedAt = hrtime(true);

        $events = [];
        $runId = null;
        $handle = fopen($path, 'rb');
        if (false === $handle) {
            throw new RuntimeException();
        }
        try {
            while (false !== ($line = fgets($handle))) {
                if ('' === trim($line)) {
                    continue;
                }
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    throw new RuntimeException();
                }
                $event = $normalizer->denormalizeRunEvent($decoded);
                if (!$event instanceof RunEvent) {
                    throw new RuntimeException();
                }
                if (null === $runId) {
                    $runId = $event->runId;
                }
                if ($runId !== $event->runId) {
                    throw new RuntimeException();
                }
                $events[] = $event;
            }
        } finally {
            fclose($handle);
        }
        if (null === $runId || [] === $events) {
            throw new RuntimeException();
        }
        $sorted = $preparer->sortBySequence($events);
        if ([] !== $preparer->duplicateSequences($sorted)) {
            throw new RuntimeException();
        }
        $filtered = $historyFilter->filter($sorted);
        $state = $reducer->replay(RunState::queued($runId), $filtered);
        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $peakDelta = max(0, memory_get_peak_usage(true) - $baseline);
        if (!$state instanceof RunState) {
            throw new RuntimeException();
        }
        printf("events=%d duration_ms=%d peak_memory_delta_bytes=%d\n", count($events), $durationMs, $peakDelta);
        $status = 0;
    } catch (Throwable) {
        $status = 1;
    } finally {
        if ($kernel instanceof Kernel) {
            $kernel->shutdown();
        }
        $filesystem->remove($cacheDir);
    }

    return $status;
}

$args = $_SERVER['argv'];
if (3 === count($args) && '--replay-candidate' === $args[1]) {
    exit(replayOneCandidate($args[2]));
}
if (2 !== count($args) || '.hatfield' !== basename($args[1]) || !is_dir($args[1])) {
    exit(2);
}
exit(benchmark($args[1]));
