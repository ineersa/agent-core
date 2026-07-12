<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Schema\SchemaVersion;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class SessionRunEventStoreTest extends TestCase
{
    private string $projectDir = '';
    private SessionRunEventStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir().'/hatfield-session-eventstore-'.getmypid();
        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
        mkdir($this->projectDir, 0777, true);
        mkdir($this->projectDir.'/.hatfield/sessions', 0777, true);

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );

        $this->store = new SessionRunEventStore(
            hatfieldSessionStore: $hatfieldSessionStore,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: new \Psr\Log\NullLogger(),
            sequenceAllocator: new FileRunSequenceAllocator(),
        );
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

    public function testEventsSurviveStoreRecreation(): void
    {
        // Simulate process restart: write events, create new store, read back
        $runId = 'run-'.bin2hex(random_bytes(4));
        $this->store->append(RunEvent::forAppend(
            runId: $runId,
            turnNo: 0,
            type: 'agent_start',
            payload: [],
        ));

        // New store instance (simulates recreating services after restart)
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );
        $newStore = new SessionRunEventStore(
            hatfieldSessionStore: $hatfieldSessionStore,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: new \Psr\Log\NullLogger(),
            sequenceAllocator: new FileRunSequenceAllocator(),
        );

        $events = $newStore->allFor($runId);
        $this->assertCount(1, $events, 'Events must survive store recreation');
        $this->assertSame('agent_start', $events[0]->type);
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
        $this->store->append(RunEvent::forAppend(runId: $runB, turnNo: 0, type: 'agent_start'));

        $eventsA = $this->store->allFor($runA);
        $eventsB = $this->store->allFor($runB);

        $this->assertCount(1, $eventsA);
        $this->assertSame('run_started', $eventsA[0]->type);
        $this->assertSame($runA, $eventsA[0]->runId);

        $this->assertCount(1, $eventsB);
        $this->assertSame('agent_start', $eventsB[0]->type);
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
