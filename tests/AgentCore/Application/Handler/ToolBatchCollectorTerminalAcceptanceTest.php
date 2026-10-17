<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationAcceptedCompletionIdentity;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationEnvelopeFingerprint;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationOwnerReference;
use Ineersa\AgentCore\Tests\Support\InMemoryDeferredToolCompletionRepository;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\AgentCore\Tests\Support\TestToolBatchStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * BG-01 C3: prepared true-terminal acceptance and deferred settlement.
 */
final class ToolBatchCollectorTerminalAcceptanceTest extends TestCase
{
    public function testAttachedAcceptanceSettlesForegroundResultWithoutDetachment(): void
    {
        [$collector, $deferred, $correlation, $result, $fingerprint] = $this->readyPending();

        $prepared = $collector->prepareTerminalAcceptance($result, $fingerprint);
        $this->assertNotNull($prepared);
        $this->assertTrue($prepared->accepted);
        $this->assertFalse($prepared->duplicate);
        $this->assertFalse($prepared->detached);
        $this->assertInstanceOf(RecordToolInvocationLifecycleDTO::class, $prepared->lifecycleAction);
        $this->assertSame(
            ToolInvocationAcceptedCompletionIdentity::completionIdFromCorrelation($correlation),
            $prepared->completionId,
        );

        $deferred->applyLifecycleProjection($prepared->lifecycleAction);
        $this->assertSame('completed', $deferred->status($correlation->deferredId));
        $accepted = $deferred->findByDeferredId($correlation->deferredId);
        $this->assertSame(ToolInvocationCallerDispositionEnum::ForegroundResultAccepted, $accepted?->callerDisposition);
        $this->assertSame(ToolInvocationExecutionObservationEnum::Terminal, $accepted?->executionObservation);
        $this->assertSame($prepared->completionId, $accepted?->acceptedCompletionId);
        $this->assertSame($fingerprint, $accepted?->acceptedResultFingerprint);
    }

    public function testDetachedAcceptanceDoesNotMutateBatchAndIsIdempotent(): void
    {
        [$collector, $batches, $deferred, $correlation] = $this->readyBackgroundAcknowledged();
        $result = $this->toolResult($correlation, ['content' => [['type' => 'text', 'text' => 'done']]]);
        $fingerprint = ToolInvocationAcceptedCompletionIdentity::resultFingerprint([
            'tool_call_id' => $result->toolCallId,
            'result' => $result->result,
        ]);

        $before = $batches->load($correlation->runId, $correlation->turnNo, $correlation->stepId);
        $this->assertNotNull($before);

        $prepared = $collector->prepareTerminalAcceptance($result, $fingerprint);
        $this->assertNotNull($prepared);
        $this->assertTrue($prepared->accepted);
        $this->assertTrue($prepared->detached);
        $this->assertNotNull($prepared->lifecycleAction);

        $deferred->applyLifecycleProjection($prepared->lifecycleAction);
        $after = $batches->load($correlation->runId, $correlation->turnNo, $correlation->stepId);
        $this->assertSame($before?->inFlight, $after?->inFlight);
        $this->assertSame(array_keys($before?->results ?? []), array_keys($after?->results ?? []));
        $this->assertSame('completed', $deferred->status($correlation->deferredId));

        $duplicate = $collector->prepareTerminalAcceptance($result, $fingerprint);
        $this->assertNotNull($duplicate);
        $this->assertTrue($duplicate->accepted);
        $this->assertTrue($duplicate->duplicate);
        $this->assertNull($duplicate->lifecycleAction);
    }

    public function testConflictingFingerprintIsRejected(): void
    {
        [$collector, $deferred, $correlation, $result, $fingerprint] = $this->readyPending();
        $prepared = $collector->prepareTerminalAcceptance($result, $fingerprint);
        $this->assertNotNull($prepared?->lifecycleAction);
        $deferred->applyLifecycleProjection($prepared->lifecycleAction);

        $conflict = $collector->prepareTerminalAcceptance($result, hash('sha256', 'other'));
        $this->assertNotNull($conflict);
        $this->assertFalse($conflict->accepted);
        $this->assertSame('conflicting_completion', $conflict->rejectionReason);
        $this->assertSame('completed', $deferred->status($correlation->deferredId));
    }

    /**
     * @return array{0: ToolBatchCollector, 1: InMemoryDeferredToolCompletionRepository, 2: DeferredToolCompletionCorrelation, 3: ToolCallResult, 4: string}
     */
    private function readyPending(): array
    {
        $batches = new TestToolBatchStore();
        $deferred = new InMemoryDeferredToolCompletionRepository();
        $collector = new ToolBatchCollector(new \Ineersa\AgentCore\Tests\Support\StaticToolCompletionDeliveryFenceProvider(), $batches, 4, $deferred);
        $call = $this->call('run-term', 'step-1', 'call-1', 0);
        TestToolBatchRegistration::register($collector, $batches, 'run-term', 1, 'step-1', [$call]);
        $correlation = $deferred->registerPending($this->correlationFromCall($call));
        $result = $this->toolResult($correlation, ['content' => [['type' => 'text', 'text' => 'ok']]]);
        $fingerprint = ToolInvocationAcceptedCompletionIdentity::resultFingerprint([
            'tool_call_id' => $result->toolCallId,
            'result' => $result->result,
        ]);

        return [$collector, $deferred, $correlation, $result, $fingerprint];
    }

    /**
     * @return array{0: ToolBatchCollector, 1: TestToolBatchStore, 2: InMemoryDeferredToolCompletionRepository, 3: DeferredToolCompletionCorrelation}
     */
    private function readyBackgroundAcknowledged(): array
    {
        $batches = new TestToolBatchStore();
        $deferred = new InMemoryDeferredToolCompletionRepository();
        $collector = new ToolBatchCollector(new \Ineersa\AgentCore\Tests\Support\StaticToolCompletionDeliveryFenceProvider(), $batches, 4, $deferred);
        $call = $this->call('run-bg', 'step-1', 'call-bg', 0);
        TestToolBatchRegistration::register($collector, $batches, 'run-bg', 1, 'step-1', [$call]);
        $correlation = $deferred->registerPending($this->correlationFromCall($call));
        $deferred->applyLifecycleProjection(new RecordToolInvocationLifecycleDTO(
            $correlation,
            ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
            ToolInvocationExecutionObservationEnum::Active,
        ));
        $updated = $deferred->findByDeferredId($correlation->deferredId);
        $this->assertNotNull($updated);

        return [$collector, $batches, $deferred, $updated];
    }

    private function correlationFromCall(ExecuteToolCall $call): DeferredToolCompletionCorrelation
    {
        $base = new DeferredToolCompletionCorrelation(
            deferredId: Uuid::v7()->toRfc4122(),
            runId: $call->runId(),
            turnNo: $call->turnNo(),
            stepId: $call->stepId(),
            attempt: $call->attempt(),
            idempotencyKey: $call->idempotencyKey(),
            toolCallId: $call->toolCallId,
            toolName: $call->toolName,
            arguments: $call->args,
            orderIndex: $call->orderIndex,
            mode: $call->mode,
            maxParallelism: $call->maxParallelism,
            invocationId: 'inv-'.$call->toolCallId,
            controllerRunId: $call->runId(),
            executionOwnerKind: 'bash',
            executionOwnerReference: new ToolInvocationOwnerReference('bash', 'record-'.$call->toolCallId, 11, '/tmp/'.$call->toolCallId.'.log'),
            originalExecutionMode: $call->mode,
        );

        return new DeferredToolCompletionCorrelation(
            deferredId: $base->deferredId,
            runId: $base->runId,
            turnNo: $base->turnNo,
            stepId: $base->stepId,
            attempt: $base->attempt,
            idempotencyKey: $base->idempotencyKey,
            toolCallId: $base->toolCallId,
            toolName: $base->toolName,
            arguments: $base->arguments,
            orderIndex: $base->orderIndex,
            mode: $base->mode,
            maxParallelism: $base->maxParallelism,
            invocationId: $base->invocationId,
            controllerRunId: $base->controllerRunId,
            executionOwnerKind: $base->executionOwnerKind,
            executionOwnerReference: $base->executionOwnerReference,
            originalExecutionMode: $base->originalExecutionMode,
            envelopeFingerprint: ToolInvocationEnvelopeFingerprint::fromCorrelation($base),
        );
    }

    private function call(string $runId, string $stepId, string $toolCallId, int $order): ExecuteToolCall
    {
        return new ExecuteToolCall(
            $runId,
            1,
            $stepId,
            1,
            $toolCallId.'-key',
            $toolCallId,
            'bash',
            ['command' => 'true'],
            $order,
            mode: 'sequential',
        );
    }

    /** @param array<string, mixed> $payload */
    private function toolResult(DeferredToolCompletionCorrelation $correlation, array $payload): ToolCallResult
    {
        return new ToolCallResult(
            $correlation->runId,
            $correlation->turnNo,
            $correlation->stepId,
            $correlation->attempt,
            $correlation->idempotencyKey,
            $correlation->toolCallId,
            $correlation->orderIndex,
            $payload,
        );
    }
}
