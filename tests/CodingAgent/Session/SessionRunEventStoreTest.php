<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class SessionRunEventStoreTest extends TestCase
{
    private string $projectDir = '';
    private SessionRunEventStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = TestDirectoryIsolation::createOsTempDir('hatfield-session-eventstore');
        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
        mkdir($this->projectDir, 0777, true);
        mkdir($this->projectDir.'/.hatfield/sessions', 0777, true);

        $this->store = $this->createStore();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
    }

    public function testAllForReturnsEmptyForMissingRun(): void
    {
        $events = $this->store->allFor('nonexistent');
        $this->assertCount(0, $events);
    }

    public function testAppendAndRetrieveSingleEvent(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $persisted = $this->store->append(RunEvent::forAppend(
            runId: $runId,
            turnNo: 0,
            type: 'run_started',
            payload: ['prompt' => 'hello'],
        ));

        $events = $this->store->allFor($runId);
        $this->assertCount(1, $events);
        $this->assertSame($runId, $events[0]->runId);
        $this->assertSame($persisted->seq, $events[0]->seq);
        $this->assertSame(1, $events[0]->seq);
        $this->assertSame('run_started', $events[0]->type);
        $this->assertSame('hello', $events[0]->payload['prompt']);

        // Verify events.jsonl exists on disk
        $eventsPath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
        $this->assertFileExists($eventsPath);
    }

    public function testAppendManyAndRetrieveSorted(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $persisted = $this->store->appendMany([
            RunEvent::forAppend(runId: $runId, turnNo: 1, type: 'tool_execution_end'),
            RunEvent::forAppend(runId: $runId, turnNo: 0, type: 'run_started'),
            RunEvent::forAppend(runId: $runId, turnNo: 1, type: 'tool_execution_start'),
        ]);

        $events = $this->store->allFor($runId);
        $this->assertCount(3, $events);

        $this->assertSame([1, 2, 3], array_map(static fn (RunEvent $e): int => $e->seq, $events));
        $this->assertSame(
            ['tool_execution_end', 'run_started', 'tool_execution_start'],
            array_map(static fn (RunEvent $e): string => $e->type, $persisted),
        );
        $this->assertSame('tool_execution_end', $events[0]->type);
        $this->assertSame('run_started', $events[1]->type);
        $this->assertSame('tool_execution_start', $events[2]->type);
    }

    public function testRangeForStreamsInclusiveOrderedBoundsAcrossHolesWithoutMaterializingAllFor(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $this->store->append(RunEvent::forAppend(runId: $runId, turnNo: 0, type: 'run_started'));
        $this->store->append(RunEvent::forAppend(runId: $runId, turnNo: 1, type: 'turn_advanced'));
        $this->store->allFor($runId);

        $eventsPath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
        file_put_contents($eventsPath, json_encode([
            'schema_version' => SchemaVersion::CURRENT,
            'run_id' => $runId,
            'seq' => 5,
            'turn_no' => 2,
            'type' => 'agent_end',
            'payload' => [],
            'ts' => '2026-01-01T00:00:00+00:00',
        ], \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);

        $events = iterator_to_array($this->store->rangeFor($runId, 2, 5));

        $this->assertSame([2, 5], array_map(static fn (RunEvent $event): int => $event->seq, $events));
        $this->assertSame(['turn_advanced', 'agent_end'], array_map(static fn (RunEvent $event): string => $event->type, $events));
    }

    public function testRangeForDoesNotReadMalformedRecordAfterRequestedRange(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $this->store->append(RunEvent::forAppend(runId: $runId, turnNo: 0, type: 'run_started'));
        $this->store->append(RunEvent::forAppend(runId: $runId, turnNo: 1, type: 'turn_advanced'));
        file_put_contents(
            $this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl',
            "{\"partial\":\n",
            \FILE_APPEND,
        );

        $events = iterator_to_array($this->store->rangeFor($runId, 1, 1));

        $this->assertSame([1], array_map(static fn (RunEvent $event): int => $event->seq, $events));
    }

    public function testRangeForReturnsEmptyForInvalidRangeAndMissingRun(): void
    {
        $this->assertSame([], iterator_to_array($this->store->rangeFor('missing', 1, 1)));
        $this->assertSame([], iterator_to_array($this->store->rangeFor('missing', 0, 1)));
        $this->assertSame([], iterator_to_array($this->store->rangeFor('missing', 2, 1)));
    }

    public function testFirstAndLatestReadCanonicalHeadAndTail(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $first = $this->store->append(RunEvent::forAppend(runId: $runId, turnNo: 0, type: 'run_started'));
        $last = $this->store->append(RunEvent::forAppend(runId: $runId, turnNo: 1, type: 'turn_advanced'));

        $this->assertSame($first->seq, $this->store->firstFor($runId)?->seq);
        $this->assertSame($last->seq, $this->store->latestSequenceFor($runId));
    }

    public function testLatestSequenceSkipsTrailingIncompatibleRecord(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $last = $this->store->append(RunEvent::forAppend(runId: $runId, turnNo: 0, type: 'run_started'));
        file_put_contents($this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl', json_encode([
            'schema_version' => '999.0',
            'run_id' => $runId,
            'seq' => $last->seq + 1,
            'turn_no' => 1,
            'type' => 'future_event',
            'payload' => [],
        ], \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);

        $this->assertSame($last->seq, $this->store->latestSequenceFor($runId));
    }

    public function testReverseForReadsNewestRelevantTailBeforeLargePrefix(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $path = $this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
        mkdir(\dirname($path), 0777, true);
        file_put_contents($path, str_repeat("{\"ignored\":true}\n", 20000));
        file_put_contents($path, json_encode([
            'schema_version' => SchemaVersion::CURRENT,
            'run_id' => $runId,
            'seq' => 7,
            'turn_no' => 1,
            'type' => 'turn_advanced',
            'payload' => [],
            'ts' => '2026-01-01T00:00:00+00:00',
        ], \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);

        foreach ($this->store->reverseFor($runId) as $event) {
            $this->assertSame(7, $event->seq);
            break;
        }
    }

    public function testLatestSequenceRejectsTrailingPartialRecordLikeAllFor(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $this->store->append(RunEvent::forAppend(runId: $runId, turnNo: 0, type: 'run_started'));
        file_put_contents($this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl', '{"partial":', \FILE_APPEND);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not parseable as JSON');
        $this->store->latestSequenceFor($runId);
    }

    public function testEventsSurviveStoreRecreation(): void
    {
        // Simulate process restart: write events, create new store, read back
        $runId = 'run-'.bin2hex(random_bytes(4));
        $this->store->append(RunEvent::forAppend(
            runId: $runId,
            turnNo: 0,
            type: 'agent_end',
            payload: [],
        ));

        // New store instance (simulates recreating services after restart)
        $newStore = $this->createStore();

        $events = $newStore->allFor($runId);
        $this->assertCount(1, $events, 'Events must survive store recreation');
        $this->assertSame('agent_end', $events[0]->type);
    }

    public function testEmbeddedRunIdMustMatchDirectory(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $this->store->append(RunEvent::forAppend(runId: $runId, turnNo: 0, type: 'run_started'));

        // Tamper with the JSONL to have wrong runId
        $eventsPath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
        $tampered = '{"schema_version":"1.0","run_id":"wrong-id","seq":1,"turn_no":0,"type":"run_started","payload":[]}'."\n";
        file_put_contents($eventsPath, $tampered);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity error');
        $this->store->allFor($runId);
    }

    public function testRunIsolation(): void
    {
        // Events for different runs must not leak across
        $runA = 'run-'.bin2hex(random_bytes(2));
        $runB = 'run-'.bin2hex(random_bytes(2));

        $this->store->append(RunEvent::forAppend(runId: $runA, turnNo: 0, type: 'run_started'));
        $this->store->append(RunEvent::forAppend(runId: $runB, turnNo: 0, type: 'agent_end'));

        $eventsA = $this->store->allFor($runA);
        $eventsB = $this->store->allFor($runB);

        $this->assertCount(1, $eventsA);
        $this->assertSame('run_started', $eventsA[0]->type);
        $this->assertSame($runA, $eventsA[0]->runId);

        $this->assertCount(1, $eventsB);
        $this->assertSame('agent_end', $eventsB[0]->type);
        $this->assertSame($runB, $eventsB[0]->runId);
    }

    public function testCorruptJsonLineWithMissingRequiredFieldsThrows(): void
    {
        // Write a valid event then inject a corrupt line with no schema_version
        // and missing required fields — should throw, not silently skip.
        $runId = 'run-'.bin2hex(random_bytes(4));
        $this->store->append(RunEvent::forAppend(runId: $runId, turnNo: 0, type: 'run_started', payload: []));

        $eventsPath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
        // Append a corrupt line (missing required fields, no schema_version)
        file_put_contents($eventsPath, '{"run_id":"'.$runId.'","seq":null}'."\n", \FILE_APPEND);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Corrupt event JSONL for run');
        $this->store->allFor($runId);
    }

    public function testCorruptJsonLineWithCompatibleSchemaAndMissingRequiredFieldsThrows(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $this->store->append(RunEvent::forAppend(runId: $runId, turnNo: 0, type: 'run_started', payload: []));

        $eventsPath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
        file_put_contents($eventsPath, '{"schema_version":"'.SchemaVersion::CURRENT.'","run_id":"'.$runId.'","seq":null}'."\n", \FILE_APPEND);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Corrupt event JSONL for run');
        $this->store->allFor($runId);
    }

    public function testIncompatibleSchemaVersionIsSkippedWithDiagnosticPolicy(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $this->store->append(RunEvent::forAppend(runId: $runId, turnNo: 0, type: 'run_started', payload: []));

        $eventsPath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
        // Append an old-format event with incompatible schema version.
        file_put_contents($eventsPath, '{"schema_version":"0.1","run_id":"'.$runId.'","seq":2,"turn_no":1,"type":"old_event","payload":[]}'."\n", \FILE_APPEND);

        // Should succeed — incompatible schema follows the documented
        // compatibility policy and the original event is returned.
        $events = $this->store->allFor($runId);
        $this->assertCount(1, $events);
        $this->assertSame(1, $events[0]->seq);
        $this->assertSame('run_started', $events[0]->type);
    }

    public function testAllForSkipsIncompatibleSchemaOnEachRead(): void
    {
        $logger = new TestLogger();
        $store = $this->createStore($logger);
        $runId = 'run-'.bin2hex(random_bytes(4));
        $store->append(RunEvent::forAppend(runId: $runId, turnNo: 0, type: 'run_started', payload: ['marker' => 'once']));

        $eventsPath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
        file_put_contents(
            $eventsPath,
            '{"schema_version":"0.1","run_id":"'.$runId.'","seq":2,"turn_no":1,"type":"old_event","payload":[]}'."\n",
            \FILE_APPEND,
        );

        $first = $store->allFor($runId);
        $second = $store->allFor($runId);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame('run_started', $first[0]->type);
        $this->assertSame('once', $first[0]->payload['marker']);
        $this->assertSame('run_started', $second[0]->type);

        $skipped = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'session.incompatible_schema_skipped' === ($record['context']['event_type'] ?? null),
        ));
        $this->assertCount(
            2,
            $skipped,
            'allFor must re-read and re-decode from disk on every call',
        );
    }

    public function testExternalAppendIsVisibleOnNextAllForEvenWithSameMtime(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $this->store->append(RunEvent::forAppend(runId: $runId, turnNo: 0, type: 'run_started', payload: []));

        $first = $this->store->allFor($runId);
        $this->assertCount(1, $first);

        $eventsPath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
        clearstatcache(true, $eventsPath);
        $mtime = filemtime($eventsPath);
        $this->assertNotFalse($mtime);

        $external = [
            'schema_version' => SchemaVersion::CURRENT,
            'run_id' => $runId,
            'seq' => 2,
            'turn_no' => 1,
            'type' => 'agent_end',
            'payload' => ['source' => 'external'],
            'ts' => '2026-01-01T00:00:00+00:00',
        ];
        file_put_contents($eventsPath, json_encode($external, \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);
        // Size change must invalidate even when mtime is forced equal (same-second append).
        touch($eventsPath, $mtime);

        $second = $this->store->allFor($runId);
        $this->assertCount(2, $second);
        $this->assertSame(['run_started', 'agent_end'], array_map(static fn (RunEvent $e): string => $e->type, $second));
        $this->assertSame('external', $second[1]->payload['source']);
    }

    public function testStoreOwnedAppendIsVisibleOnNextAllFor(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $this->store->append(RunEvent::forAppend(runId: $runId, turnNo: 0, type: 'run_started', payload: []));

        $first = $this->store->allFor($runId);
        $this->assertCount(1, $first);

        $this->store->append(RunEvent::forAppend(runId: $runId, turnNo: 1, type: 'agent_end', payload: ['via' => 'store']));

        $second = $this->store->allFor($runId);
        $this->assertCount(2, $second);
        $this->assertSame(['run_started', 'agent_end'], array_map(static fn (RunEvent $e): string => $e->type, $second));
        $this->assertSame('store', $second[1]->payload['via']);
    }

    private function createStore(?LoggerInterface $logger = null): SessionRunEventStore
    {
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );

        return new SessionRunEventStore(
            hatfieldSessionStore: $hatfieldSessionStore,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: $logger ?? new NullLogger(),
            sequenceAllocator: new FileRunSequenceAllocator(),
        );
    }

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir((string) $item);
            } else {
                unlink((string) $item);
            }
        }
        rmdir($dir);
    }
}
