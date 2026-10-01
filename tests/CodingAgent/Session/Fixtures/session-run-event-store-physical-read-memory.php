<?php

declare(strict_types=1);

/**
 * Independent strict-128M peak probes for SessionRunEventStore allFor memory shape.
 *
 * One process measures exactly one mode so peaks are not cumulative.
 * Both retained modes build the same normalized RunEvent graph.
 *
 * Modes:
 * - prepare: write a shared multi-turn JSONL archive under HATFIELD_EVENTSTORE_PROBE_DIR
 * - allfor-production: SessionRunEventStore::allFor() and retain the returned list
 * - allfor-wholefile-dup: counterfactual of old allFor (file_get_contents+explode+denormalize)
 * - range-stream: rangeFor consumer that does not retain decoded events
 */

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Schema\SchemaVersion;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

require dirname(__DIR__, 4).'/vendor/autoload.php';

ini_set('memory_limit', '128M');

$mode = $argv[1] ?? '';
$eventCount = 1800;
$turns = 60;
$runId = 'run-physical-read';
$payloadMarker = str_repeat('m', 1400);

$projectDir = getenv('HATFIELD_EVENTSTORE_PROBE_DIR');
if (!is_string($projectDir) || '' === $projectDir) {
    fwrite(\STDERR, "HATFIELD_EVENTSTORE_PROBE_DIR is required\n");
    exit(2);
}

$eventsPath = $projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';

if ('prepare' === $mode) {
    TestDirectoryIsolation::ensureDirectory(dirname($eventsPath));
    $handle = fopen($eventsPath, 'wb');
    if (false === $handle) {
        fwrite(\STDERR, "failed to open events path\n");
        exit(2);
    }

    for ($seq = 1; $seq <= $eventCount; ++$seq) {
        $turnNo = (int) ceil($seq / ($eventCount / $turns));
        $line = json_encode([
            'schema_version' => SchemaVersion::CURRENT,
            'run_id' => $runId,
            'seq' => $seq,
            'turn_no' => $turnNo,
            'type' => 0 === $seq % 30 ? 'turn_advanced' : 'tool_execution_end',
            'payload' => [
                'marker' => $payloadMarker,
                'turn_no' => $turnNo,
                'seq' => $seq,
            ],
            'ts' => '2026-01-01T00:00:00+00:00',
        ], \JSON_THROW_ON_ERROR)."\n";
        fwrite($handle, $line);
    }
    fclose($handle);

    $archiveBytes = filesize($eventsPath);
    if (false === $archiveBytes) {
        fwrite(\STDERR, "failed to size archive\n");
        exit(2);
    }

    echo json_encode([
        'mode' => $mode,
        'archive_bytes' => $archiveBytes,
        'event_count' => $eventCount,
        'turn_count' => $turns,
        'events_path' => $eventsPath,
    ], \JSON_THROW_ON_ERROR), "\n";
    exit(0);
}

if (!is_file($eventsPath)) {
    fwrite(\STDERR, "missing prepared archive at {$eventsPath}\n");
    exit(2);
}

$archiveBytes = filesize($eventsPath);
if (false === $archiveBytes) {
    fwrite(\STDERR, "failed to size archive\n");
    exit(2);
}

$entityManagerFactory = new class('physical-read-fixture') extends TestCase {
    public function entityManager(): EntityManagerInterface
    {
        return $this->createStub(EntityManagerInterface::class);
    }
};

$createStore = static function (?TestLogger $logger = null) use ($projectDir, $entityManagerFactory): SessionRunEventStore {
    return new SessionRunEventStore(
        hatfieldSessionStore: new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $projectDir,
            ),
            entityManager: $entityManagerFactory->entityManager(),
            dispatcher: new EventDispatcher(),
        ),
        eventPayloadNormalizer: new EventPayloadNormalizer(),
        lockFactory: new LockFactory(new FlockStore()),
        logger: $logger ?? new TestLogger(),
        sequenceAllocator: new FileRunSequenceAllocator(),
    );
};

gc_collect_cycles();
$peakBefore = memory_get_peak_usage(true);
$decodedCount = 0;
$retained = null;
$archiveBytesRead = null;
$fullScan = null;
$earlyExit = null;

switch ($mode) {
    case 'allfor-production':
        $logger = new TestLogger();
        $store = $createStore($logger);
        $retained = $store->allFor($runId);
        $decodedCount = count($retained);
        $records = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'session.event_store.physical_read' === ($record['context']['event_type'] ?? null)
                && 'allFor' === ($record['context']['method'] ?? null),
        ));
        if (1 !== count($records)) {
            fwrite(\STDERR, 'expected one allFor physical-read record, got '.count($records)."\n");
            exit(3);
        }
        $archiveBytesRead = $records[0]['context']['archive_bytes_read'];
        $fullScan = $records[0]['context']['full_scan'];
        $earlyExit = $records[0]['context']['early_exit'];
        break;

    case 'allfor-wholefile-dup':
        // Counterfactual of the pre-Change1 allFor path: whole-file text duplication
        // plus the same normalized RunEvent graph retained by the current API.
        $normalizer = new EventPayloadNormalizer();
        $contents = file_get_contents($eventsPath);
        if (false === $contents) {
            fwrite(\STDERR, "whole-file read failed\n");
            exit(2);
        }
        $events = [];
        foreach (explode("\n", $contents) as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }
            $payload = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                continue;
            }
            $event = $normalizer->denormalizeRunEvent($payload);
            if ($event instanceof RunEvent) {
                $events[] = $event;
            }
        }
        unset($contents);
        usort($events, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);
        $retained = $events;
        $decodedCount = count($retained);
        $archiveBytesRead = $archiveBytes;
        $fullScan = true;
        $earlyExit = false;
        break;

    case 'range-stream':
        $logger = new TestLogger();
        $store = $createStore($logger);
        foreach ($store->rangeFor($runId, 1, $eventCount) as $event) {
            if (!$event instanceof RunEvent) {
                throw new RuntimeException('unexpected event type');
            }
            ++$decodedCount;
        }
        $records = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'session.event_store.physical_read' === ($record['context']['event_type'] ?? null)
                && 'rangeFor' === ($record['context']['method'] ?? null),
        ));
        if (1 !== count($records)) {
            fwrite(\STDERR, 'expected one rangeFor physical-read record, got '.count($records)."\n");
            exit(3);
        }
        $archiveBytesRead = $records[0]['context']['archive_bytes_read'];
        $fullScan = $records[0]['context']['full_scan'];
        $earlyExit = $records[0]['context']['early_exit'];
        break;

    default:
        fwrite(\STDERR, "unknown mode: {$mode}\n");
        exit(2);
}

$peakAfter = memory_get_peak_usage(true);
$retainedCount = is_array($retained) ? count($retained) : 0;
if (is_array($retained) && $decodedCount !== $retainedCount) {
    fwrite(\STDERR, "retained count mismatch\n");
    exit(3);
}

// Keep the retained graph live through the peak sample for retained modes.
$result = [
    'mode' => $mode,
    'archive_bytes' => $archiveBytes,
    'event_count' => $eventCount,
    'turn_count' => $turns,
    'decoded_count' => $decodedCount,
    'peak_before_bytes' => $peakBefore,
    'peak_after_bytes' => $peakAfter,
    'peak_delta_bytes' => max(0, $peakAfter - $peakBefore),
    'archive_bytes_read' => $archiveBytesRead,
    'full_scan' => $fullScan,
    'early_exit' => $earlyExit,
    'memory_limit' => ini_get('memory_limit'),
    'retained_first_seq' => is_array($retained) ? ($retained[0]->seq ?? null) : null,
    'retained_last_seq' => is_array($retained) && $retainedCount > 0 ? ($retained[$retainedCount - 1]->seq ?? null) : null,
];

echo json_encode($result, \JSON_THROW_ON_ERROR), "\n";

// Explicitly keep retained reference until after JSON emission.
unset($retained);
