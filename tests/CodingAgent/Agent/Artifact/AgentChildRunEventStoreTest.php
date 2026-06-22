<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStore;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
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
    private AgentArtifactPathResolver $pathResolver;

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

        $this->pathResolver = new AgentArtifactPathResolver($hatfieldSessionStore);
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
        self::assertCount(1, $events);
        self::assertSame($agentRunId, $events[0]->runId);
        self::assertSame(1, $events[0]->seq);
        self::assertSame('run_started', $events[0]->type);
        self::assertSame('Explore codebase', $events[0]->payload['prompt']);
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
        self::assertFileExists($expectedPath);

        // Verify no top-level child session directory was created
        self::assertDirectoryDoesNotExist("{$this->projectDir}/.hatfield/sessions/{$agentRunId}");
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
        self::assertCount(0, $events);
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
        self::assertCount(0, $store->allFor('child-x'));
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
        self::assertCount(3, $retrieved);

        // Events are sorted by seq
        self::assertSame(1, $retrieved[0]->seq);
        self::assertSame(2, $retrieved[1]->seq);
        self::assertSame(3, $retrieved[2]->seq);
    }

    public function testMultipleChildrenDoNotInterfere(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        $storeA = $this->createStore($parentRunId, 'child-a', 'scout-001');
        $storeB = $this->createStore($parentRunId, 'child-b', 'scout-002');

        $storeA->append(new RunEvent(runId: 'child-a', seq: 1, turnNo: 0, type: 'run_started'));
        $storeB->append(new RunEvent(runId: 'child-b', seq: 1, turnNo: 0, type: 'run_started'));

        // Each store only returns its own events
        self::assertCount(1, $storeA->allFor('child-a'));
        self::assertCount(0, $storeA->allFor('child-b'));
        self::assertCount(1, $storeB->allFor('child-b'));
        self::assertCount(0, $storeB->allFor('child-a'));
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

    // ── Helpers ───────────────────────────────────────────────────────────

    private function createStore(string $parentRunId, string $agentRunId, string $artifactId): AgentChildRunEventStore
    {
        return new AgentChildRunEventStore(
            pathResolver: $this->pathResolver,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: new NullLogger(),
            parentRunId: $parentRunId,
            agentRunId: $agentRunId,
            artifactId: $artifactId,
        );
    }
}
