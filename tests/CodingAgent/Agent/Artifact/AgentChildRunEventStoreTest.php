<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStore;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Tests for AgentChildRunEventStore covering append, retrieve, runId
 * validation, and the critical invariant that child events are stored
 * under the parent artifact path — not as a top-level session directory.
 *
 * Test thesis: The child event store correctly writes and reads events
 * at .hatfield/sessions/<parent>/artifacts/agents/<artifact>/events.jsonl
 * and does NOT create .hatfield/sessions/<agentRunId>/events.jsonl.
 */
final class AgentChildRunEventStoreTest extends TestCase
{
    private string $projectDir;
    private SessionAgentArtifactPathResolver $pathResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = TestDirectoryIsolation::createOsTempDir('hatfield-child-eventstore');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);

        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->projectDir,
            ),
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );

        $this->pathResolver = new SessionAgentArtifactPathResolver($hatfieldSessionStore);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testAppendAndRetrieveSingleEvent(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        $event = new RunEvent(
            runId: $agentRunId,
            seq: 1,
            turnNo: 0,
            type: 'run_started',
            payload: ['prompt' => 'Explore codebase'],
        );

        $store->append($event);

        $events = $store->allFor($agentRunId);
        $this->assertCount(1, $events);
        $this->assertSame($agentRunId, $events[0]->runId);
        $this->assertSame(1, $events[0]->seq);
        $this->assertSame('run_started', $events[0]->type);
        $this->assertSame('Explore codebase', $events[0]->payload['prompt']);
    }

    public function testRangeForStreamsBoundedChildEvents(): void
    {
        $store = $this->createStore('parent-range', 'child-range', 'scout-range');
        $store->append(new RunEvent(runId: 'child-range', seq: 1, turnNo: 0, type: 'run_started'));
        $store->append(new RunEvent(runId: 'child-range', seq: 2, turnNo: 1, type: 'turn_advanced'));
        $store->append(new RunEvent(runId: 'child-range', seq: 3, turnNo: 2, type: 'agent_end'));

        $events = iterator_to_array($store->rangeFor('child-range', 2, 3));

        $this->assertSame([2, 3], array_map(static fn (RunEvent $event): int => $event->seq, $events));
        $this->assertSame([], iterator_to_array($store->rangeFor('other-child', 1, 3)));
    }

    public function testRangeForDoesNotReadMalformedRecordAfterRequestedRange(): void
    {
        $parentRunId = 'parent-range';
        $agentRunId = 'child-range';
        $artifactId = 'scout-range';
        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);
        $store->append(new RunEvent(runId: $agentRunId, seq: 1, turnNo: 0, type: 'run_started'));
        $store->append(new RunEvent(runId: $agentRunId, seq: 2, turnNo: 1, type: 'turn_advanced'));
        file_put_contents(
            "{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactId}/events.jsonl",
            "{\"partial\":\n",
            \FILE_APPEND,
        );

        $events = iterator_to_array($store->rangeFor($agentRunId, 1, 1));

        $this->assertSame([1], array_map(static fn (RunEvent $event): int => $event->seq, $events));
    }

    public function testLatestAndReverseReadNewestTailBeforeMalformedPrefix(): void
    {
        $parentRunId = 'parent-reverse';
        $agentRunId = 'child-reverse';
        $artifactId = 'scout-reverse';
        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);
        $normalizer = new EventPayloadNormalizer();
        $path = "{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactId}/events.jsonl";
        mkdir(\dirname($path), 0775, true);
        file_put_contents($path, "{\"partial\":\n");
        foreach ([7, 8] as $seq) {
            file_put_contents(
                $path,
                json_encode($normalizer->normalize($agentRunId, $seq, $seq, 'turn_advanced', []), \JSON_THROW_ON_ERROR)."\n",
                \FILE_APPEND,
            );
        }

        $this->assertSame(8, $store->latestSequenceFor($agentRunId));

        $events = [];
        foreach ($store->reverseFor($agentRunId) as $event) {
            $events[] = $event->seq;
            if (2 === \count($events)) {
                break;
            }
        }
        $this->assertSame([8, 7], $events);

        $this->assertNull($store->latestSequenceFor('other-child'));
        $this->assertSame([], iterator_to_array($store->reverseFor('other-child')));
    }

    public function testLatestSequenceSkipsTrailingIncompatibleRecord(): void
    {
        $parentRunId = 'parent-latest';
        $agentRunId = 'child-latest';
        $artifactId = 'scout-latest';
        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);
        $last = $store->append(new RunEvent(runId: $agentRunId, seq: 1, turnNo: 0, type: 'run_started'));
        $path = "{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactId}/events.jsonl";
        file_put_contents($path, json_encode([
            'schema_version' => '999.0',
            'run_id' => $agentRunId,
            'seq' => $last->seq + 1,
            'turn_no' => 1,
            'type' => 'future_event',
            'payload' => [],
        ], \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);

        $this->assertSame($last->seq, $store->latestSequenceFor($agentRunId));
    }

    public function testLatestSequenceRejectsTrailingPartialRecord(): void
    {
        $parentRunId = 'parent-latest';
        $agentRunId = 'child-latest';
        $artifactId = 'scout-latest';
        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);
        $store->append(new RunEvent(runId: $agentRunId, seq: 1, turnNo: 0, type: 'run_started'));
        $path = "{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactId}/events.jsonl";
        file_put_contents($path, '{"partial":', \FILE_APPEND);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Corrupt event JSONL line for child run');
        $store->latestSequenceFor($agentRunId);
    }

    public function testEventsStoredUnderParentArtifactPath(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        $store->append(new RunEvent(
            runId: $agentRunId,
            seq: 1,
            turnNo: 0,
            type: 'run_started',
        ));

        // Verify events exist at the parent-scoped artifact path
        $expectedPath = "{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactId}/events.jsonl";
        $this->assertFileExists($expectedPath);

        // Verify no top-level child session directory was created
        $this->assertDirectoryDoesNotExist("{$this->projectDir}/.hatfield/sessions/{$agentRunId}");
    }

    public function testAllForReturnsEmptyForMismatchedRunId(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        $store->append(new RunEvent(
            runId: $agentRunId,
            seq: 1,
            turnNo: 0,
            type: 'run_started',
        ));

        // Different runId returns empty
        $events = $store->allFor('different-run');
        $this->assertCount(0, $events);
    }

    public function testAppendRejectsMismatchedRunId(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity error');

        $store->append(new RunEvent(
            runId: 'wrong-run-id',
            seq: 1,
            turnNo: 0,
            type: 'run_started',
        ));
    }

    public function testAllForReturnsEmptyForMissingEvents(): void
    {
        $store = $this->createStore('parent-x', 'child-x', 'artifact-x');
        $this->assertCount(0, $store->allFor('child-x'));
    }

    public function testAppendManyAndRetrieveSorted(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        $events = [
            new RunEvent(runId: $agentRunId, seq: 3, turnNo: 1, type: 'tool_execution.completed'),
            new RunEvent(runId: $agentRunId, seq: 1, turnNo: 0, type: 'run_started'),
            new RunEvent(runId: $agentRunId, seq: 2, turnNo: 1, type: 'tool_execution.started'),
        ];

        $store->appendMany($events);

        $retrieved = $store->allFor($agentRunId);
        $this->assertCount(3, $retrieved);

        // Events are sorted by seq
        $this->assertSame(1, $retrieved[0]->seq);
        $this->assertSame(2, $retrieved[1]->seq);
        $this->assertSame(3, $retrieved[2]->seq);
    }

    public function testAppendManyRejectsMismatchedRunIdBeforeAllocation(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';
        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        $events = [
            new RunEvent(runId: $agentRunId, seq: 0, turnNo: 0, type: 'run_started'),
            new RunEvent(runId: 'other-child', seq: 0, turnNo: 1, type: 'tool_execution.started'),
        ];

        try {
            $store->appendMany($events);
            $this->fail('Expected RuntimeException for mismatched runId');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('does not match bound agentRunId', $exception->getMessage());
        }

        $this->assertCount(0, $store->allFor($agentRunId));
    }

    public function testMultipleChildrenDoNotInterfere(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $storeA = $this->createStore($parentRunId, 'child-a', 'scout-001');
        $storeB = $this->createStore($parentRunId, 'child-b', 'scout-002');

        $storeA->append(new RunEvent(runId: 'child-a', seq: 1, turnNo: 0, type: 'run_started'));
        $storeB->append(new RunEvent(runId: 'child-b', seq: 1, turnNo: 0, type: 'run_started'));

        // Each store only returns its own events
        $this->assertCount(1, $storeA->allFor('child-a'));
        $this->assertCount(0, $storeA->allFor('child-b'));
        $this->assertCount(1, $storeB->allFor('child-b'));
        $this->assertCount(0, $storeB->allFor('child-a'));
    }

    // ── Constructor path validation ──────────────────────────────────────

    public function testConstructorRejectsEmptyParentRunId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        $this->createStore('', 'child-x', 'artifact-x');
    }

    public function testConstructorRejectsPathSeparatorsInParentRunId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path separators');

        $this->createStore('a/b', 'child-x', 'artifact-x');
    }

    public function testConstructorRejectsDotInArtifactId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be "."');

        $this->createStore('parent-x', 'child-x', '.');
    }

    public function testConstructorRejectsDotDotInArtifactId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be ".."');

        $this->createStore('parent-x', 'child-x', '..');
    }

    public function testReadAfterSeqReturnsOnlyEventsAfterCursorAndAcceptsSequenceHoles(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-hole';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);
        $normalizer = new EventPayloadNormalizer();
        $path = "{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactId}/events.jsonl";
        mkdir(\dirname($path), 0775, true);

        foreach ([
            $normalizer->normalize($agentRunId, 1, 0, 'run_started', []),
            $normalizer->normalize($agentRunId, 3, 1, 'turn_advanced', ['turn_no' => 1]),
        ] as $line) {
            file_put_contents($path, json_encode($line, \JSON_THROW_ON_ERROR).'
', \FILE_APPEND);
        }

        $tail = $store->readAfterSeq(1);
        $this->assertCount(1, $tail);
        $this->assertSame(3, $tail[0]->seq);
        $this->assertSame('turn_advanced', $tail[0]->type);
    }

    public function testReadAfterSeqRejectsRunIdMismatch(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-mismatch';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);
        $normalizer = new EventPayloadNormalizer();
        $path = "{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactId}/events.jsonl";
        mkdir(\dirname($path), 0775, true);
        $bad = $normalizer->normalize('other-child', 2, 0, 'run_started', []);
        file_put_contents($path, json_encode($bad, \JSON_THROW_ON_ERROR).'
', \FILE_APPEND);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity error');
        $store->readAfterSeq(0);
    }

    public function testAppendBootstrapsSeqFromExistingEventsJsonl(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-bootstrap';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);
        $normalizer = new EventPayloadNormalizer();
        $path = "{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactId}/events.jsonl";
        mkdir(\dirname($path), 0775, true);
        file_put_contents($path, json_encode($normalizer->normalize($agentRunId, 99, 0, 'run_started', []), \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);

        $persisted = $store->append(new RunEvent(runId: $agentRunId, seq: 0, turnNo: 1, type: 'agent_start'));
        $this->assertSame(100, $persisted->seq);

        $events = $store->allFor($agentRunId);
        $this->assertSame([99, 100], array_map(static fn (RunEvent $e): int => $e->seq, $events));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function createStore(string $parentRunId, string $agentRunId, string $artifactId): AgentChildRunEventStore
    {
        return new AgentChildRunEventStore(
            pathResolver: $this->pathResolver,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: new NullLogger(),
            sequenceAllocator: new FileRunSequenceAllocator(),
            parentRunId: $parentRunId,
            agentRunId: $agentRunId,
            artifactId: $artifactId,
        );
    }
}
