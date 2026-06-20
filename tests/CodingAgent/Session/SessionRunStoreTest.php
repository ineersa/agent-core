<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

final class SessionRunStoreTest extends TestCase
{
    private string $projectDir = '';
    private SessionRunStore $store;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir().'/hatfield-session-runstore-'.getmypid();
        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
        mkdir($this->projectDir, 0777, true);
        mkdir($this->projectDir.'/.hatfield/sessions', 0777, true);

        $this->entityManager = $this->createStub(EntityManagerInterface::class);

        $serializer = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer()],
            [new JsonEncoder()],
        );

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->entityManager,
        );

        $this->store = new SessionRunStore(
            hatfieldSessionStore: $hatfieldSessionStore,
            serializer: $serializer,
            lockFactory: new LockFactory(new FlockStore()),
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
    }

    public function testGetReturnsNullForMissingRun(): void
    {
        $this->assertNull($this->store->get('nonexistent'));
    }

    public function testCompareAndSwapCreatesStateAndCanBeRetrieved(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $state = new RunState(runId: $runId, status: RunStatus::Queued, version: 1);

        $result = $this->store->compareAndSwap($state, 0);
        $this->assertTrue($result, 'CAS should succeed for new run');

        $loaded = $this->store->get($runId);
        $this->assertNotNull($loaded);
        $this->assertSame($runId, $loaded->runId);
        $this->assertSame(RunStatus::Queued, $loaded->status);
        $this->assertSame(1, $loaded->version);

        // Verify state.json exists on disk
        $statePath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/state.json';
        $this->assertFileExists($statePath);
    }

    public function testCompareAndSwapFailsOnVersionMismatch(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $stateV1 = new RunState(runId: $runId, status: RunStatus::Queued, version: 1);

        // First CAS: version 0 → creates
        $result1 = $this->store->compareAndSwap($stateV1, 0);
        $this->assertTrue($result1);

        // Same state applied with wrong expected version
        $result2 = $this->store->compareAndSwap($stateV1, 0);
        $this->assertFalse($result2, 'CAS should fail because version is now 1');

        // Correct expected version
        $stateV2 = new RunState(runId: $runId, status: RunStatus::Running, version: 2);
        $result3 = $this->store->compareAndSwap($stateV2, 1);
        $this->assertTrue($result3);

        $loaded = $this->store->get($runId);
        $this->assertNotNull($loaded);
        $this->assertSame(RunStatus::Running, $loaded->status);
        $this->assertSame(2, $loaded->version);
    }

    public function testGetAfterRecreationSurvives(): void
    {
        // Simulate process restart by creating a new store instance
        $runId = 'run-'.bin2hex(random_bytes(4));

        // First store writes state
        $state = new RunState(runId: $runId, status: RunStatus::Running, version: 3, turnNo: 2);
        $this->store->compareAndSwap($state, 0);

        // New store instance (simulates recreating services after restart)
        $serializer = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer()],
            [new JsonEncoder()],
        );
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->entityManager,
        );
        $newStore = new SessionRunStore(
            hatfieldSessionStore: $hatfieldSessionStore,
            serializer: $serializer,
            lockFactory: new LockFactory(new FlockStore()),
        );

        $loaded = $newStore->get($runId);
        $this->assertNotNull($loaded, 'State must survive store recreation');
        $this->assertSame($runId, $loaded->runId);
        $this->assertSame(RunStatus::Running, $loaded->status);
        $this->assertSame(3, $loaded->version);
        $this->assertSame(2, $loaded->turnNo);
    }

    public function testGetReturnsNullForEmptyFile(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));

        // Pre-create the session directory with an empty state.json (same as
        // HatfieldSessionStore::createSession() does for brand-new sessions).
        $dir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/state.json', '');

        // Empty file should be treated as "no state yet", not corrupt.
        $loaded = $this->store->get($runId);
        $this->assertNull($loaded, 'Empty state.json must return null, not throw');

        // CAS should still work starting from version 0
        $state = new RunState(runId: $runId, status: RunStatus::Running, version: 1);
        $result = $this->store->compareAndSwap($state, 0);
        $this->assertTrue($result, 'CAS should succeed starting from version 0 on empty file');

        // Verify state was written and can be read back
        $loaded = $this->store->get($runId);
        $this->assertNotNull($loaded);
        $this->assertSame(RunStatus::Running, $loaded->status);
        $this->assertSame(1, $loaded->version);
    }

    public function testGetReturnsNullForWhitespaceOnlyFile(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));

        $dir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/state.json', "\n\n  \n");

        // Whitespace-only file should also be treated as "no state yet"
        $loaded = $this->store->get($runId);
        $this->assertNull($loaded, 'Whitespace-only state.json must return null');
    }

    public function testEmbeddedRunIdMustMatchDirectory(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $state = new RunState(runId: $runId, status: RunStatus::Queued, version: 1);

        $this->store->compareAndSwap($state, 0);

        // Write a tampered state.json with a different embedded runId
        $statePath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/state.json';
        $tampered = json_encode(['runId' => 'wrong-id', 'status' => 'queued', 'version' => 1]);
        file_put_contents($statePath, $tampered);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity error');
        $this->store->get($runId);
    }

    public function testFindRunningStaleBefore(): void
    {
        // Create a running run
        $runId = 'run-'.bin2hex(random_bytes(4));
        $state = new RunState(runId: $runId, status: RunStatus::Running, version: 1);
        $this->store->compareAndSwap($state, 0);

        // Run is recent, should not be stale
        $future = new \DateTimeImmutable('+10 minutes');
        $stale = $this->store->findRunningStaleBefore($future);
        $this->assertNotEmpty($stale);
        $this->assertSame($runId, $stale[0]->runId);

        // Completed runs are not returned as stale
        $completedState = new RunState(runId: $runId, status: RunStatus::Completed, version: 2);
        $this->store->compareAndSwap($completedState, 1);

        $staleAfterComplete = $this->store->findRunningStaleBefore($future);
        $this->assertEmpty($staleAfterComplete, 'Completed runs should not be returned as stale');
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
