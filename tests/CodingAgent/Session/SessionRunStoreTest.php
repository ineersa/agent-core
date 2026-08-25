<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
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

        $this->projectDir = TestDirectoryIsolation::createOsTempDir('hatfield-session-runstore');
        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
        mkdir($this->projectDir, 0777, true);
        mkdir($this->projectDir.'/.hatfield/sessions', 0777, true);

        $this->entityManager = $this->createStub(EntityManagerInterface::class);

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
            serializer: $this->createRunStateSerializer(),
            lockFactory: new LockFactory(new FlockStore()),
            filesystem: new Filesystem(),
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
        $state = new RunState(runId: $runId, status: RunStatus::Queued, version: 1, model: 'test-model');

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
        $stateV1 = new RunState(runId: $runId, status: RunStatus::Queued, version: 1, model: 'test-model');

        // First CAS: version 0 → creates
        $result1 = $this->store->compareAndSwap($stateV1, 0);
        $this->assertTrue($result1);

        // Same state applied with wrong expected version
        $result2 = $this->store->compareAndSwap($stateV1, 0);
        $this->assertFalse($result2, 'CAS should fail because version is now 1');

        // Correct expected version
        $stateV2 = new RunState(runId: $runId, status: RunStatus::Running, version: 2, model: 'test-model');
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
        $payload = [
            'kind' => 'interrupt',
            'question_id' => 'ah_roundtrip',
            'prompt' => 'Persist me?',
            'schema' => ['type' => 'boolean'],
            'tool_call_id' => 'tc-rt',
            'tool_name' => 'ask_human',
            'ui_kind' => 'confirm',
        ];

        // First store writes state including nested pending human-input request
        $state = new RunState(
            runId: $runId,
            status: RunStatus::WaitingHuman,
            version: 3,
            turnNo: 2,
            pendingHumanInputRequests: [
                new PendingHumanInputRequestDTO(
                    questionId: 'ah_roundtrip',
                    continuationKind: HumanInputContinuationKindEnum::ModelTurn,
                    payload: $payload,
                    continuationRef: null,
                ),
            ],
            model: 'test-model');
        $this->store->compareAndSwap($state, 0);

        // New store instance (simulates recreating services after restart)
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
            serializer: $this->createRunStateSerializer(),
            lockFactory: new LockFactory(new FlockStore()),
            filesystem: new Filesystem(),
        );

        $loaded = $newStore->get($runId);
        $this->assertNotNull($loaded, 'State must survive store recreation');
        $this->assertSame($runId, $loaded->runId);
        $this->assertSame(RunStatus::WaitingHuman, $loaded->status);
        $this->assertSame(3, $loaded->version);
        $this->assertSame(2, $loaded->turnNo);
        $this->assertCount(1, $loaded->pendingHumanInputRequests);
        $request = $loaded->pendingHumanInputRequests[0];
        $this->assertSame(PendingHumanInputRequestDTO::class, $request::class);
        $this->assertSame('ah_roundtrip', $request->questionId);
        $this->assertSame(HumanInputContinuationKindEnum::ModelTurn, $request->continuationKind);
        $this->assertSame($payload, $request->payload);
        $this->assertNull($request->continuationRef);
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
        $state = new RunState(runId: $runId, status: RunStatus::Running, version: 1, model: 'test-model');
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

    /**
     * Regression: compareAndSwap() must never expose a partial state.json to
     * unlocked readers. The writer runs bounded CAS writes of a large state
     * while a plain subprocess reader (no lock, no store) hammers the file;
     * any non-empty content that is not complete parseable JSON is corruption.
     *
     * Bounded: 25 writes and a reader deadline of 2.5s; no unbounded stress.
     */
    public function testUnlockedReadersNeverObservePartialStateDuringConcurrentWrites(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $statePath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/state.json';
        // No mkdir: the reader treats a missing file as "no state yet" and
        // Filesystem::dumpFile() creates the session directory itself.

        // 4 MiB payload keeps the truncate-then-write window wide enough that a
        // tight reader loop reliably catches a partial read on the old in-place
        // file_put_contents(); dumpFile() (temp file + rename) never shows one.
        $largePayload = ['content' => str_repeat('x', 4 * 1024 * 1024)];

        $readerScript = <<<'PHP'
$path = $argv[1];
$deadline = microtime(true) + (float) $argv[2];
$reads = 0;
while (microtime(true) < $deadline) {
    $content = @file_get_contents($path);
    if (false === $content || '' === trim($content)) {
        continue; // missing / empty file is a complete "no state yet"
    }
    ++$reads;
    try {
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable $e) {
        fwrite(STDOUT, 'CORRUPT_JSON:' . $e->getMessage() . ':' . substr($content, 0, 200));
        exit(1);
    }
    if (!\is_array($data) || !isset($data['run_id'], $data['version'])) {
        fwrite(STDOUT, 'INVALID_SHAPE');
        exit(1);
    }
}
fwrite(STDOUT, 'OK reads=' . $reads);
exit(0);
PHP;

        $proc = proc_open(
            [\PHP_BINARY, '-r', $readerScript, $statePath, '2.5'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);

        try {
            fclose($pipes[0]);

            $state = new RunState(
                runId: $runId,
                status: RunStatus::Queued,
                version: 0,
                model: 'test-model',
                streamingMessage: $largePayload,
            );
            for ($i = 1; $i <= 25; ++$i) {
                $this->assertTrue(
                    $this->store->compareAndSwap($state->with(['version' => $i]), $i - 1),
                    \sprintf('CAS write %d must succeed', $i),
                );
            }

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            $proc = null;
        } finally {
            if (\is_resource($proc)) {
                proc_terminate($proc);
                foreach ($pipes as $pipe) {
                    if (\is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($proc);
            }
        }

        $this->assertSame(0, $exit, \sprintf('Unlocked reader observed partial/corrupt state.json: %s%s', $stdout, $stderr));
        $this->assertMatchesRegularExpression(
            '/^OK reads=[1-9][0-9]*$/',
            trim($stdout),
            \sprintf('Reader must report at least one successful read, got: %s%s', $stdout, $stderr),
        );

        $final = $this->store->get($runId);
        $this->assertNotNull($final);
        $this->assertSame(25, $final->version);
    }

    public function testEmbeddedRunIdMustMatchDirectory(): void
    {
        $runId = 'run-'.bin2hex(random_bytes(4));
        $state = new RunState(runId: $runId, status: RunStatus::Queued, version: 1, model: 'test-model');

        $this->store->compareAndSwap($state, 0);

        // Write a tampered state.json with a different embedded runId
        $statePath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/state.json';
        $tampered = json_encode(['run_id' => 'wrong-id', 'status' => 'queued', 'version' => 1]);
        file_put_contents($statePath, $tampered);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity error');
        $this->store->get($runId);
    }

    public function testFindRunningStaleBefore(): void
    {
        // Create a running run
        $runId = 'run-'.bin2hex(random_bytes(4));
        $state = new RunState(runId: $runId, status: RunStatus::Running, version: 1, model: 'test-model');
        $this->store->compareAndSwap($state, 0);

        // Run is recent, should not be stale
        $future = new \DateTimeImmutable('+10 minutes');
        $stale = $this->store->findRunningStaleBefore($future);
        $this->assertNotEmpty($stale);
        $this->assertSame($runId, $stale[0]->runId);

        // Completed runs are not returned as stale
        $completedState = new RunState(runId: $runId, status: RunStatus::Completed, version: 2, model: 'test-model');
        $this->store->compareAndSwap($completedState, 1);

        $staleAfterComplete = $this->store->findRunningStaleBefore($future);
        $this->assertEmpty($staleAfterComplete, 'Completed runs should not be returned as stale');
    }

    /**
     * Production-parity serializer: nested list DTOs (e.g. pending human-input
     * requests) require ArrayDenormalizer + PropertyInfo type extraction.
     */
    private function createRunStateSerializer(): NormalizerInterface&DenormalizerInterface
    {
        $propertyInfo = new PropertyInfoExtractor(
            typeExtractors: [new PhpDocExtractor(), new ReflectionExtractor()],
        );

        return new Serializer(
            [
                new DateTimeNormalizer(),
                new BackedEnumNormalizer(),
                new ArrayDenormalizer(),
                new ObjectNormalizer(
                    classMetadataFactory: ($cmf = new ClassMetadataFactory(new AttributeLoader())),
                    nameConverter: new MetadataAwareNameConverter($cmf, new CamelCaseToSnakeCaseNameConverter()),
                    propertyTypeExtractor: $propertyInfo,
                ),
            ],
            [new JsonEncoder()],
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
