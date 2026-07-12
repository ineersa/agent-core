<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class SessionRunEventStoreSequencingTest extends TestCase
{
    private string $projectDir;
    private SessionRunEventStore $store;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('session-seq-store');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);

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
            logger: new NullLogger(),
            sequenceAllocator: new FileRunSequenceAllocator(),
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testAppendWithNextSeqFromExistingCursorDoesNotReadEventsJsonl(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $eventsPath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
        TestDirectoryIsolation::ensureDirectory(\dirname($eventsPath));
        file_put_contents($eventsPath, '{"schema_version":"1.0","run_id":"'.$runId.'","seq":99,"turn_no":0,"type":"run_started","payload":[]}'."\n");
        $counterPath = FileRunSequenceAllocator::counterPathForEventsLog($eventsPath);
        file_put_contents($counterPath, "5\n");

        $persisted = $this->store->append(new RunEvent(
            runId: $runId,
            seq: 0,
            turnNo: 1,
            type: 'turn_advanced',
            payload: [],
        ));

        $this->assertSame(6, $persisted->seq);
        $this->assertSame("6\n", file_get_contents($counterPath));
        $lines = file($eventsPath) ?: [];
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('"seq":6', $lines[1]);
    }

    public function testMissingCursorBootstrapsFromMaxSeqInLogOnce(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $eventsPath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
        TestDirectoryIsolation::ensureDirectory(\dirname($eventsPath));
        file_put_contents($eventsPath, '{"schema_version":"1.0","run_id":"'.$runId.'","seq":3,"turn_no":0,"type":"run_started","payload":[]}'."\n".'{"schema_version":"1.0","run_id":"'.$runId.'","seq":8,"turn_no":1,"type":"turn_advanced","payload":[]}'."\n");
        $counterPath = FileRunSequenceAllocator::counterPathForEventsLog($eventsPath);
        $this->assertFileDoesNotExist($counterPath);

        $first = $this->store->append(new RunEvent($runId, 0, 2, 'tool_execution_start', []));
        $this->assertSame(9, $first->seq);
        $this->assertFileExists($counterPath);
        $this->assertSame("9\n", file_get_contents($counterPath));

        $second = $this->store->append(new RunEvent($runId, 0, 2, 'tool_execution_end', []));
        $this->assertSame(10, $second->seq);
    }

    public function testAppendManyWithNextSeqReturnsContiguousAssignedSeqs(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $eventsPath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
        TestDirectoryIsolation::ensureDirectory(\dirname($eventsPath));
        file_put_contents(FileRunSequenceAllocator::counterPathForEventsLog($eventsPath), "2\n");

        $persisted = $this->store->appendMany([
            new RunEvent($runId, 0, 1, 'a', []),
            new RunEvent($runId, 0, 1, 'b', []),
            new RunEvent($runId, 0, 1, 'c', []),
        ]);

        $this->assertSame([3, 4, 5], array_map(static fn (RunEvent $e): int => $e->seq, $persisted));
        $this->assertSame("5\n", file_get_contents(FileRunSequenceAllocator::counterPathForEventsLog($eventsPath)));
    }
}
