<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\Storage;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Infrastructure\Storage\SessionRunEventStore;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
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

        $this->store = new SessionRunEventStore(
            projectDir: $this->projectDir,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
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
        self::assertCount(0, $events);
    }

    public function testAppendAndRetrieveSingleEvent(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $event = new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: 'run_started',
            payload: ['prompt' => 'hello'],
        );

        $this->store->append($event);

        $events = $this->store->allFor($runId);
        self::assertCount(1, $events);
        self::assertSame($runId, $events[0]->runId);
        self::assertSame(1, $events[0]->seq);
        self::assertSame('run_started', $events[0]->type);
        self::assertSame('hello', $events[0]->payload['prompt']);

        // Verify events.jsonl exists on disk
        $eventsPath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
        self::assertFileExists($eventsPath);
    }

    public function testAppendManyAndRetrieveSorted(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $event1 = new RunEvent(runId: $runId, seq: 3, turnNo: 1, type: 'tool_execution_end');
        $event2 = new RunEvent(runId: $runId, seq: 1, turnNo: 0, type: 'run_started');
        $event3 = new RunEvent(runId: $runId, seq: 2, turnNo: 1, type: 'tool_execution_start');

        $this->store->appendMany([$event1, $event2, $event3]);

        $events = $this->store->allFor($runId);
        self::assertCount(3, $events);

        // Must be sorted by seq
        self::assertSame(1, $events[0]->seq);
        self::assertSame(2, $events[1]->seq);
        self::assertSame(3, $events[2]->seq);
        self::assertSame('run_started', $events[0]->type);
        self::assertSame('tool_execution_start', $events[1]->type);
        self::assertSame('tool_execution_end', $events[2]->type);
    }

    public function testEventsSurviveStoreRecreation(): void
    {
        // Simulate process restart: write events, create new store, read back
        $runId = 'run-'.bin2hex(random_bytes(4));
        $event = new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: 'agent_start',
            payload: [],
        );
        $this->store->append($event);

        // New store instance (simulates recreating services after restart)
        $newStore = new SessionRunEventStore(
            projectDir: $this->projectDir,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
        );

        $events = $newStore->allFor($runId);
        self::assertCount(1, $events, 'Events must survive store recreation');
        self::assertSame('agent_start', $events[0]->type);
    }

    public function testEmbeddedRunIdMustMatchDirectory(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $event = new RunEvent(runId: $runId, seq: 1, turnNo: 0, type: 'run_started');
        $this->store->append($event);

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

        $this->store->append(new RunEvent(runId: $runA, seq: 1, turnNo: 0, type: 'run_started'));
        $this->store->append(new RunEvent(runId: $runB, seq: 1, turnNo: 0, type: 'agent_start'));

        $eventsA = $this->store->allFor($runA);
        $eventsB = $this->store->allFor($runB);

        self::assertCount(1, $eventsA);
        self::assertSame('run_started', $eventsA[0]->type);
        self::assertSame($runA, $eventsA[0]->runId);

        self::assertCount(1, $eventsB);
        self::assertSame('agent_start', $eventsB[0]->type);
        self::assertSame($runB, $eventsB[0]->runId);
    }

    public function testSetSessionsBasePathOverridesDefault(): void
    {
        $customDir = sys_get_temp_dir().'/hatfield-custom-eventstore-'.getmypid();
        if (is_dir($customDir)) {
            $this->rmDir($customDir);
        }
        mkdir($customDir, 0777, true);

        try {
            $this->store->setSessionsBasePath($customDir);

            $runId = 'run-'.bin2hex(random_bytes(4));
            $event = new RunEvent(
                runId: $runId,
                seq: 1,
                turnNo: 0,
                type: 'run_started',
                payload: ['prompt' => 'hello'],
            );

            $this->store->append($event);

            // Verify events.jsonl was written to the custom directory
            $customEventsPath = $customDir.'/'.$runId.'/events.jsonl';
            self::assertFileExists($customEventsPath, 'events.jsonl must be written to the custom sessions base path');

            // Verify NOT written to the default directory
            $defaultEventsPath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
            self::assertFileDoesNotExist($defaultEventsPath, 'events.jsonl must not be written to the default projectDir');

            // Verify retrieval from custom path
            $events = $this->store->allFor($runId);
            self::assertCount(1, $events);
            self::assertSame($runId, $events[0]->runId);
            self::assertSame('hello', $events[0]->payload['prompt']);
        } finally {
            if (is_dir($customDir)) {
                $this->rmDir($customDir);
            }
        }
    }

    public function testSetSessionsBasePathRunIsolationStillWorks(): void
    {
        $customDir = sys_get_temp_dir().'/hatfield-custom-eventstore-iso-'.getmypid();
        if (is_dir($customDir)) {
            $this->rmDir($customDir);
        }
        mkdir($customDir, 0777, true);

        try {
            $this->store->setSessionsBasePath($customDir);

            $runA = 'run-'.bin2hex(random_bytes(2));
            $runB = 'run-'.bin2hex(random_bytes(2));

            $this->store->append(new RunEvent(runId: $runA, seq: 1, turnNo: 0, type: 'run_started'));
            $this->store->append(new RunEvent(runId: $runB, seq: 1, turnNo: 0, type: 'agent_start'));

            $eventsA = $this->store->allFor($runA);
            $eventsB = $this->store->allFor($runB);

            self::assertCount(1, $eventsA);
            self::assertSame('run_started', $eventsA[0]->type);
            self::assertSame($runA, $eventsA[0]->runId);

            self::assertCount(1, $eventsB);
            self::assertSame('agent_start', $eventsB[0]->type);
            self::assertSame($runB, $eventsB[0]->runId);
        } finally {
            if (is_dir($customDir)) {
                $this->rmDir($customDir);
            }
        }
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
