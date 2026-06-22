<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunStore;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Tests for AgentChildRunStore covering CAS, retrieve, runId
 * validation, and the critical invariant that child state is stored
 * under the parent artifact path — not as a top-level session directory.
 *
 * Test thesis: The child run store correctly persists and reads
 * RunState at .hatfield/sessions/<parent>/artifacts/agents/<artifact>/state.json
 * with correct CAS semantics and does NOT create a top-level
 * .hatfield/sessions/<agentRunId>/state.json.
 */
final class AgentChildRunStoreTest extends TestCase
{
    private string $projectDir;
    private HatfieldSessionStore $hatfieldSessionStore;
    private Serializer $serializer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir().'/hatfield-child-runstore-'.getmypid();
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

        $this->serializer = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer()],
            [new JsonEncoder()],
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
    }

    // ── Basic get / CAS ───────────────────────────────────────────────────

    public function testGetReturnsNullForMissingRun(): void
    {
        $store = $this->createStore('parent-x', 'child-x', 'artifact-x');

        self::assertNull($store->get('child-x'));
    }

    public function testGetReturnsNullForMismatchedRunId(): void
    {
        $store = $this->createStore('parent-x', 'child-x', 'artifact-x');

        self::assertNull($store->get('different-run'));
    }

    public function testCompareAndSwapCreatesStateAndCanBeRetrieved(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        $state = new RunState(runId: $agentRunId, status: RunStatus::Queued, version: 1);

        $result = $store->compareAndSwap($state, 0);
        self::assertTrue($result, 'CAS should succeed for new run');

        $loaded = $store->get($agentRunId);
        self::assertNotNull($loaded);
        self::assertSame($agentRunId, $loaded->runId);
        self::assertSame(RunStatus::Queued, $loaded->status);
        self::assertSame(1, $loaded->version);
    }

    public function testCompareAndSwapFailsOnVersionMismatch(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $agentRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'scout-001';

        $store = $this->createStore($parentRunId, $agentRunId, $artifactId);

        $stateV1 = new RunState(runId: $agentRunId, status: RunStatus::Queued, version: 1);
        self::assertTrue($store->compareAndSwap($stateV1, 0));

        // Same state applied with wrong expected version
        self::assertFalse($store->compareAndSwap($stateV1, 0), 'CAS should fail because version is now 1');

        // Correct expected version
        $stateV2 = new RunState(runId: $agentRunId, status: RunStatus::Running, version: 2);
        self::assertTrue($store->compareAndSwap($stateV2, 1));

        $loaded = $store->get($agentRunId);
        self::assertNotNull($loaded);
        self::assertSame(RunStatus::Running, $loaded->status);
        self::assertSame(2, $loaded->version);
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
        self::assertFileExists($expectedPath);

        // Verify no top-level child session directory was created
        self::assertDirectoryDoesNotExist("{$this->projectDir}/.hatfield/sessions/{$agentRunId}");
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
        self::assertCount(1, $stale);
        self::assertSame($agentRunId, $stale[0]->runId);
    }

    public function testFindRunningStaleBeforeReturnsEmptyForNoArtifacts(): void
    {
        $store = $this->createStore('parent-x', 'child-x', 'artifact-x');

        $stale = $store->findRunningStaleBefore(new \DateTimeImmutable());
        self::assertCount(0, $stale);
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
        self::assertDirectoryDoesNotExist("{$this->projectDir}/.hatfield/sessions/{$agentRunId}");

        // Parent artifacts directory must exist
        self::assertDirectoryExists("{$this->projectDir}/.hatfield/sessions/{$parentRunId}/artifacts/agents/{$artifactId}");
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function createStore(string $parentRunId, string $agentRunId, string $artifactId): AgentChildRunStore
    {
        return new AgentChildRunStore(
            hatfieldSessionStore: $this->hatfieldSessionStore,
            serializer: $this->serializer,
            lockFactory: new LockFactory(new FlockStore()),
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
