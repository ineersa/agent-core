<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunStore;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Tests for AgentChildRunStore covering CAS, retrieve, runId
 * validation, stale detection, and the critical invariant that child
 * state is stored under the parent artifact path — not as a top-level
 * session directory.
 *
 * Test thesis: The child run store correctly persists and reads
 * RunState at .hatfield/sessions/<parent>/artifacts/agents/<artifact>/state.json
 * with correct CAS semantics, findRunningStaleBefore() only reports the
 * bound child, and no top-level .hatfield/sessions/<agentRunId>/ is
 * ever created.
 */
final class AgentChildRunStoreTest extends TestCase
{
    private string $projectDir;
    private AgentArtifactPathResolver $pathResolver;
    private Serializer $serializer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = TestDirectoryIsolation::createOsTempDir('hatfield-child-runstore');
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

        $this->serializer = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer(
                nameConverter: new CamelCaseToSnakeCaseNameConverter(),
            )],
            [new JsonEncoder()],
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    // ── Basic get / CAS ───────────────────────────────────────────────────

    public function testGetReturnsNullForMissingRun(): void
    {
        $store = $this->createStore('parent-x', 'child-x', 'artifact-x');

        $this->assertNull($store->get('child-x'));
    }

    public function testGetReturnsNullForMismatchedRunId(): void
    {
        $store = $this->createStore('parent-x', 'child-x', 'artifact-x');

        $this->assertNull($store->get('different-run'));
    }

    public function testCompareAndSwapCreatesStateAndCanBeRetrieved(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        $state = new RunState(runId: $agentRunId, status: RunStatus::Queued, version: 1);

        $result = $store->compareAndSwap($state, 0);
        $this->assertTrue($result, 'CAS should succeed for new run');

        $loaded = $store->get($agentRunId);
        $this->assertNotNull($loaded);
        $this->assertSame($agentRunId, $loaded->runId);
        $this->assertSame(RunStatus::Queued, $loaded->status);
        $this->assertSame(1, $loaded->version);
    }

    public function testCompareAndSwapFailsOnVersionMismatch(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        $stateV1 = new RunState(runId: $agentRunId, status: RunStatus::Queued, version: 1);
        $this->assertTrue($store->compareAndSwap($stateV1, 0));

        // Same state applied with wrong expected version
        $this->assertFalse($store->compareAndSwap($stateV1, 0), 'CAS should fail because version is now 1');

        // Correct expected version
        $stateV2 = new RunState(runId: $agentRunId, status: RunStatus::Running, version: 2);
        $this->assertTrue($store->compareAndSwap($stateV2, 1));

        $loaded = $store->get($agentRunId);
        $this->assertNotNull($loaded);
        $this->assertSame(RunStatus::Running, $loaded->status);
        $this->assertSame(2, $loaded->version);
    }

    // ── State stored under parent artifact path ───────────────────────────

    public function testStateStoredUnderParentArtifactPath(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        $state = new RunState(runId: $agentRunId, status: RunStatus::Running, version: 1);
        $store->compareAndSwap($state, 0);

        // Verify state.json exists at the parent-scoped artifact path
        $expectedPath = "{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactId}/state.json";
        $this->assertFileExists($expectedPath);

        // Verify no top-level child session directory was created
        $this->assertDirectoryDoesNotExist("{$this->projectDir}/.hatfield/sessions/{$agentRunId}");
    }

    // ── Embedded runId validation ─────────────────────────────────────────

    public function testCasRejectsMismatchedRunId(): void
    {
        $store = $this->createStore('parent-x', 'child-x', 'artifact-x');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity error');

        $store->compareAndSwap(
            new RunState(runId: 'wrong-id', status: RunStatus::Queued, version: 1),
            0,
        );
    }

    public function testGetRejectsTamperedStateJson(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        // Write a valid state
        $state = new RunState(runId: $agentRunId, status: RunStatus::Running, version: 1);
        $store->compareAndSwap($state, 0);

        // Tamper with the state.json by writing a different runId
        $statePath = "{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactId}/state.json";
        $tampered = $this->serializer->normalize(new RunState(runId: 'tampered-id', status: RunStatus::Queued, version: 1));
        file_put_contents($statePath, json_encode($tampered));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity error');
        $store->get($agentRunId);
    }

    // ── findRunningStaleBefore ────────────────────────────────────────────

    public function testFindRunningStaleBeforeFindsStaleRunningChild(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        $state = new RunState(runId: $agentRunId, status: RunStatus::Running, version: 1);
        $store->compareAndSwap($state, 0);

        // Touch the state.json to have a known modification time
        $statePath = "{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactId}/state.json";
        touch($statePath, time() - 3600); // 1 hour ago

        // Look for runs stale before "now" (which includes our 1-hour-old file)
        $stale = $store->findRunningStaleBefore(new \DateTimeImmutable());
        $this->assertCount(1, $stale);
        $this->assertSame($agentRunId, $stale[0]->runId);
    }

    public function testFindRunningStaleBeforeReturnsEmptyForNoArtifacts(): void
    {
        $store = $this->createStore('parent-x', 'child-x', 'artifact-x');

        $stale = $store->findRunningStaleBefore(new \DateTimeImmutable());
        $this->assertCount(0, $stale);
    }

    public function testFindRunningStaleBeforeReturnsEmptyForNonRunningChild(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        // A completed child is not stale
        $state = new RunState(runId: $agentRunId, status: RunStatus::Completed, version: 1);
        $store->compareAndSwap($state, 0);

        $statePath = "{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactId}/state.json";
        touch($statePath, time() - 3600);

        $stale = $store->findRunningStaleBefore(new \DateTimeImmutable());
        $this->assertCount(0, $stale);
    }

    /**
     * Regression test: the bound store returns only its own stale
     * running state — NOT a sibling artifact's state.
     *
     * Previous implementation incorrectly looped all sibling directories
     * while always reading $this->agentRunId, which returned the same
     * bound child's state for every sibling and duplicated entries.
     */
    public function testFindRunningStaleBeforeOnlyReportsBoundChildNotSiblings(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));

        // Create two artifact directories with state.json files.
        $agentRunIdA = 'child-a';
        $artifactA = 'scout-001';
        $agentRunIdB = 'child-b';
        $artifactB = 'scout-002';

        $storeA = $this->createStore($parentRunId, $agentRunIdA, $artifactA);
        $storeB = $this->createStore($parentRunId, $agentRunIdB, $artifactB);

        // Both children are running and stale.
        $stateA = new RunState(runId: $agentRunIdA, status: RunStatus::Running, version: 1);
        $stateB = new RunState(runId: $agentRunIdB, status: RunStatus::Running, version: 1);

        $storeA->compareAndSwap($stateA, 0);
        $storeB->compareAndSwap($stateB, 0);

        $statePathA = "{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactA}/state.json";
        $statePathB = "{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactB}/state.json";
        touch($statePathA, time() - 7200);
        touch($statePathB, time() - 7200);

        // Store A only reports child A.
        $staleA = $storeA->findRunningStaleBefore(new \DateTimeImmutable());
        $this->assertCount(1, $staleA, 'Store A should report exactly one stale child — its own');
        $this->assertSame($agentRunIdA, $staleA[0]->runId);

        // Store B only reports child B.
        $staleB = $storeB->findRunningStaleBefore(new \DateTimeImmutable());
        $this->assertCount(1, $staleB, 'Store B should report exactly one stale child — its own');
        $this->assertSame($agentRunIdB, $staleB[0]->runId);
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

    // ── State corruption ─────────────────────────────────────────────────

    public function testGetThrowsOnScalarStateJson(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        // Write a valid state first to create the directory, then overwrite with scalar
        $state = new RunState(runId: $agentRunId, status: RunStatus::Running, version: 1);
        $store->compareAndSwap($state, 0);

        $statePath = "{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactId}/state.json";
        file_put_contents($statePath, '42');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not an array');

        $store->get($agentRunId);
    }

    // ── Test isolation: no top-level child session ────────────────────────

    public function testNoTopLevelChildSessionDirectoryCreated(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        $store->compareAndSwap(new RunState(runId: $agentRunId, status: RunStatus::Running, version: 1), 0);

        // Top-level child session directory must not exist
        $this->assertDirectoryDoesNotExist("{$this->projectDir}/.hatfield/sessions/{$agentRunId}");

        // Parent artifacts directory must exist
        $this->assertDirectoryExists("{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactId}");
    }

    // ── Atomic write resilience ──────────────────────────────────────────

    public function testCasUsesTempFileBeforeRename(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        $state = new RunState(runId: $agentRunId, status: RunStatus::Running, version: 1);
        $store->compareAndSwap($state, 0);

        $statePath = "{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactId}/state.json";
        $this->assertFileExists($statePath);

        // Verify no temp files linger
        $artifactDir = \dirname($statePath);
        $tmpFiles = glob($artifactDir.'/*.tmp');
        $this->assertCount(0, false !== $tmpFiles ? $tmpFiles : [], 'No .tmp files should remain after CAS');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function createStore(string $parentRunId, string $agentRunId, string $artifactId): AgentChildRunStore
    {
        return new AgentChildRunStore(
            pathResolver: $this->pathResolver,
            serializer: $this->serializer,
            lockFactory: new LockFactory(new FlockStore()),
            parentRunId: $parentRunId,
            agentRunId: $agentRunId,
            artifactId: $artifactId,
        );
    }
}
