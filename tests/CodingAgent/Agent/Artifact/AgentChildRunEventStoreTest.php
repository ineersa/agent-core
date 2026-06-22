<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStore;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
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
    private HatfieldSessionStore $hatfieldSessionStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir().'/hatfield-child-eventstore-'.getmypid();
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
        $this->hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
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

    // ── Helpers ───────────────────────────────────────────────────────────

    private function createStore(string $parentRunId, string $agentRunId, string $artifactId): AgentChildRunEventStore
    {
        return new AgentChildRunEventStore(
            hatfieldSessionStore: $this->hatfieldSessionStore,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: new NullLogger(),
            parentRunId: $parentRunId,
            agentRunId: $agentRunId,
            artifactId: $artifactId,
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

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                @chmod($file->getPathname(), 0644);
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
