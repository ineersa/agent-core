<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
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
 * Regression: finalized durable snapshot must replay acceptedComplete on redelivery
 * when canonical commit never happened (old behavior returned duplicate and stalled).
 */
final class ToolBatchCollectorFinalizedRedeliveryTest extends TestCase
{
    private string $projectDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('tool-batch-finalized-redelivery');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testFinalizedSnapshotRedeliveryReplaysAcceptedComplete(): void
    {
        $store = $this->createStore();
        $collector = new ToolBatchCollector(defaultMaxParallelism: 4, store: $store);

        $collector->registerExpectedBatch('run-1', 1, 'step-1', [
            $this->executeToolCall('call-1', 0),
        ]);

        $result = $this->toolResult('call-1', 0);
        $first = $collector->collect($result);
        $this->assertTrue($first->complete);

        $redelivery = $collector->collect($result);
        $this->assertTrue($redelivery->accepted);
        $this->assertFalse($redelivery->duplicate);
        $this->assertTrue($redelivery->complete);
        $this->assertSame(['call-1'], array_map(static fn (ToolCallResult $r): string => $r->toolCallId, $redelivery->orderedResults));
        $this->assertSame([], $redelivery->effectsToDispatch);
    }

    private function executeToolCall(string $toolCallId, int $orderIndex): ExecuteToolCall
    {
        return new ExecuteToolCall(
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: hash('sha256', 'call-'.$toolCallId),
            toolCallId: $toolCallId,
            toolName: 'read',
            args: [],
            orderIndex: $orderIndex,
        );
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

    private function toolResult(string $toolCallId, int $orderIndex): ToolCallResult
    {
        return new ToolCallResult(
            runId: 'run-1',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: hash('sha256', 'result-'.$toolCallId),
            toolCallId: $toolCallId,
            orderIndex: $orderIndex,
            result: ['ok' => true],
            isError: false,
            error: null,
        );
    }
}
