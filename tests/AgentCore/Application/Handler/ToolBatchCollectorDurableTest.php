<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionToolBatchStore;
use Ineersa\CodingAgent\Tests\Session\Support\ParentSessionToolBatchRunStoragePaths;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Durable coordination proofs use the real filesystem SessionToolBatchStore.
 */
final class ToolBatchCollectorDurableTest extends TestCase
{
    private string $projectDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('tool-batch-collector-durable');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testRegisterAndCollectWithStore(): void
    {
        $store = $this->createStore();
        $collector = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);

        $initial = $collector->registerExpectedBatch('run-1', 1, 'step-1', [
            $this->executeToolCall('run-1', 'step-1', 'call-1', 0, 'sequential'),
            $this->executeToolCall('run-1', 'step-1', 'call-2', 1, 'sequential'),
        ]);

        $this->assertCount(1, $initial);
        $this->assertSame('call-1', $initial[0]->toolCallId);

        $firstOutcome = $collector->collect($this->toolResult('run-1', 'step-1', 'call-1', 0));
        $this->assertTrue($firstOutcome->accepted);
        $this->assertFalse($firstOutcome->complete);
        $this->assertCount(1, $firstOutcome->effectsToDispatch);
        $this->assertSame('call-2', $firstOutcome->effectsToDispatch[0]->toolCallId);

        $loaded = $store->load('run-1', 1, 'step-1');
        $this->assertNotNull($loaded);
        $this->assertFalse($loaded->finalized);
        $this->assertCount(1, $loaded->results);

        $secondOutcome = $collector->collect($this->toolResult('run-1', 'step-1', 'call-2', 1));
        $this->assertTrue($secondOutcome->accepted);
        $this->assertTrue($secondOutcome->complete);

        $finalized = $store->load('run-1', 1, 'step-1');
        $this->assertNotNull($finalized);
        $this->assertTrue($finalized->finalized);
    }

    public function testCrossProcessRecoveryWithStore(): void
    {
        $store = $this->createStore();
        $registrar = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);

        $initial = $registrar->registerExpectedBatch('run-2', 1, 'step-1', [
            $this->executeToolCall('run-2', 'step-1', 'call-1', 0, 'parallel', maxParallelism: 2),
            $this->executeToolCall('run-2', 'step-1', 'call-2', 1, 'parallel', maxParallelism: 2),
        ]);
        $this->assertCount(2, $initial);
        unset($registrar);

        $recovering = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);
        $firstOutcome = $recovering->collect($this->toolResult('run-2', 'step-1', 'call-1', 0));
        $this->assertTrue($firstOutcome->accepted);
        $this->assertFalse($firstOutcome->complete);
        $this->assertEmpty($firstOutcome->effectsToDispatch);

        $secondOutcome = $recovering->collect($this->toolResult('run-2', 'step-1', 'call-2', 1));
        $this->assertTrue($secondOutcome->accepted);
        $this->assertTrue($secondOutcome->complete);
    }

    public function testCrossProcessRecoveryDispatchesPendingCalls(): void
    {
        $store = $this->createStore();
        $registrar = new ToolBatchCollector(defaultMaxParallelism: 2, store: $store);

        $initial = $registrar->registerExpectedBatch('run-3', 1, 'step-1', [
            $this->executeToolCall('run-3', 'step-1', 'call-1', 0, 'parallel', maxParallelism: 2),
            $this->executeToolCall('run-3', 'step-1', 'call-2', 1, 'parallel', maxParallelism: 2),
            $this->executeToolCall('run-3', 'step-1', 'call-3', 2, 'parallel', maxParallelism: 2),
        ]);
        $this->assertCount(2, $initial);
        unset($registrar);

        $recovering = new ToolBatchCollector(defaultMaxParallelism: 2, store: $store);
        $firstOutcome = $recovering->collect($this->toolResult('run-3', 'step-1', 'call-1', 0));
        $this->assertTrue($firstOutcome->accepted);
        $this->assertFalse($firstOutcome->complete);
        $this->assertCount(1, $firstOutcome->effectsToDispatch);
        $this->assertSame('call-3', $firstOutcome->effectsToDispatch[0]->toolCallId);
    }

    public function testRejectedWhenStoreIsEmpty(): void
    {
        $collector = new ToolBatchCollector(store: $this->createStore());
        $outcome = $collector->collect($this->toolResult('run-nonexistent', 'step-1', 'call-1', 0));
        $this->assertFalse($outcome->accepted);
        $this->assertFalse($outcome->duplicate);
    }

    public function testDuplicateResultWithStore(): void
    {
        $store = $this->createStore();
        $collector = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);
        $collector->registerExpectedBatch('run-4', 1, 'step-1', [
            $this->executeToolCall('run-4', 'step-1', 'call-1', 0, 'sequential'),
        ]);

        $firstOutcome = $collector->collect($this->toolResult('run-4', 'step-1', 'call-1', 0));
        $this->assertTrue($firstOutcome->accepted);

        $dupOutcome = $collector->collect($this->toolResult('run-4', 'step-1', 'call-1', 0));
        $this->assertTrue($dupOutcome->accepted);
        $this->assertFalse($dupOutcome->duplicate);
        $this->assertTrue($dupOutcome->complete);
    }

    public function testCrossProcessParallelDispatchRecovery(): void
    {
        $store = $this->createStore();
        $registrar = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);
        $initial = $registrar->registerExpectedBatch('run-5', 1, 'step-1', [
            $this->executeToolCall('run-5', 'step-1', 'call-1', 0, 'sequential'),
            $this->executeToolCall('run-5', 'step-1', 'call-2', 1, 'parallel', maxParallelism: 4),
            $this->executeToolCall('run-5', 'step-1', 'call-3', 2, 'parallel', maxParallelism: 4),
        ]);
        $this->assertCount(1, $initial);
        unset($registrar);

        $recovering = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);
        $firstOutcome = $recovering->collect($this->toolResult('run-5', 'step-1', 'call-1', 0));
        $this->assertTrue($firstOutcome->accepted);
        $this->assertFalse($firstOutcome->complete);
        $this->assertCount(2, $firstOutcome->effectsToDispatch);
    }

    public function testFailedDurableSaveDoesNotDirtyInMemoryCache(): void
    {
        $store = new class($this->createStore()) implements ToolBatchStoreInterface {
            public function __construct(private readonly SessionToolBatchStore $inner)
            {
            }

            public bool $failNextMutate = false;

            public function load(string $runId, int $turnNo, string $stepId): ?ToolBatchStateDTO
            {
                return $this->inner->load($runId, $turnNo, $stepId);
            }

            public function save(string $runId, int $turnNo, string $stepId, ToolBatchStateDTO $batchState): void
            {
                $this->inner->save($runId, $turnNo, $stepId, $batchState);
            }

            public function delete(string $runId, int $turnNo, string $stepId): void
            {
                $this->inner->delete($runId, $turnNo, $stepId);
            }

            public function deleteAllForRun(string $runId): void
            {
                $this->inner->deleteAllForRun($runId);
            }

            public function mutate(string $runId, int $turnNo, string $stepId, callable $callback): mixed
            {
                if ($this->failNextMutate) {
                    $this->failNextMutate = false;
                    throw new \RuntimeException('Simulated durable write failure.');
                }

                return $this->inner->mutate($runId, $turnNo, $stepId, $callback);
            }
        };

        $collector = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);
        $collector->registerExpectedBatch('run-6', 1, 'step-1', [
            $this->executeToolCall('run-6', 'step-1', 'call-1', 0, 'sequential'),
        ]);

        $store->failNextMutate = true;

        try {
            $collector->collect($this->toolResult('run-6', 'step-1', 'call-1', 0));
            $this->fail('Expected simulated durable write failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated durable write failure.', $e->getMessage());
        }

        $retryOutcome = $collector->collect($this->toolResult('run-6', 'step-1', 'call-1', 0));
        $this->assertTrue($retryOutcome->accepted);
        $this->assertFalse($retryOutcome->duplicate);
        $this->assertTrue($retryOutcome->complete);
    }

    private function createStore(): SessionToolBatchStore
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );
        $hatfield = new HatfieldSessionStore($appConfig, $entityManager);

        return new SessionToolBatchStore(
            new ParentSessionToolBatchRunStoragePaths($hatfield),
            new LockFactory(new FlockStore()),
            new NullLogger(),
        );
    }

    private function executeToolCall(
        string $runId,
        string $stepId,
        string $toolCallId,
        int $orderIndex,
        string $mode,
        int $maxParallelism = 4,
    ): ExecuteToolCall {
        return new ExecuteToolCall(
            runId: $runId,
            turnNo: 1,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s', $runId, $toolCallId)),
            toolCallId: $toolCallId,
            toolName: 'web_search',
            args: [],
            orderIndex: $orderIndex,
            mode: $mode,
            maxParallelism: $maxParallelism,
        );
    }

    private function toolResult(string $runId, string $stepId, string $toolCallId, int $orderIndex): ToolCallResult
    {
        return new ToolCallResult(
            runId: $runId,
            turnNo: 1,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', \sprintf('%s|%s', $runId, $toolCallId)),
            toolCallId: $toolCallId,
            orderIndex: $orderIndex,
            result: ['ok' => true],
            isError: false,
            error: null,
        );
    }
}
