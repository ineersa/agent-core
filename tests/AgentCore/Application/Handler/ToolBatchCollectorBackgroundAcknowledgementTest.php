<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RecordToolInvocationLifecycleDTO;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\ToolBackgroundAcknowledgement;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationBackgroundReadiness;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationCallerDispositionEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationEnvelopeFingerprint;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationExecutionObservationEnum;
use Ineersa\AgentCore\Domain\Tool\ToolInvocationOwnerReference;
use Ineersa\AgentCore\Tests\Support\InMemoryDeferredToolCompletionRepository;
use Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\AgentCore\Tests\Support\TestToolBatchStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * BG-01 C2: prepared background acknowledgment and detached logical admission.
 *
 * Cross-batch matrix (accepted clarification over original §10.2):
 * - Detached Sequential releases the original logical reservation, so a later
 *   Sequential/Parallel call in a new batch is not blocked by that old mode.
 * - Attached Sequential and max_parallelism still govern the active batch.
 * - Native deferred status stays pending; acknowledgment is not completion.
 */
final class ToolBatchCollectorBackgroundAcknowledgementTest extends TestCase
{
    public function testAcknowledgeReleasesCallerWaitAndDetachedSequentialDoesNotBlockLaterBatch(): void
    {
        [$collector, $batches, $deferred, $correlation] = $this->readyCollector(
            runId: 'run-ack',
            stepId: 'step-1',
            toolCallId: 'call-1',
            mode: 'sequential',
            siblings: [
                $this->call('run-ack', 'step-1', 'call-1', 0, 'sequential'),
                $this->call('run-ack', 'step-1', 'call-2', 1, 'sequential'),
            ],
        );

        $prepared = $collector->prepareBackgroundAcknowledgement($this->readiness($correlation));
        $this->assertTrue($prepared->accepted);
        $this->assertFalse($prepared->duplicate);
        $this->assertFalse($prepared->complete);
        $this->assertNotNull($prepared->acknowledgement);
        $this->assertSame(ToolBackgroundAcknowledgement::STATUS, $prepared->acknowledgement->status);
        $this->assertSame('running_in_background', null !== $prepared->acknowledgementResult?->result['content'][0]['text']
            ? json_decode((string) $prepared->acknowledgementResult->result['content'][0]['text'], true, 512, \JSON_THROW_ON_ERROR)['status']
            : null);
        $this->assertCount(1, $prepared->effectsToDispatch);
        $this->assertSame('call-2', $prepared->effectsToDispatch[0]->toolCallId);
        $this->assertCount(2, $prepared->localActions);
        $this->assertInstanceOf(FinalizeToolBatchDTO::class, $prepared->batchAction);
        $this->assertInstanceOf(RecordToolInvocationLifecycleDTO::class, $prepared->lifecycleAction);

        $this->applyPrepared($batches, $deferred, $prepared->localActions);

        $batch = $batches->load('run-ack', 1, 'step-1');
        $this->assertNotNull($batch);
        $this->assertArrayNotHasKey('call-1', $batch->inFlight);
        $this->assertArrayHasKey('call-1', $batch->results);
        $this->assertArrayHasKey('call-2', $batch->inFlight);
        $this->assertSame('pending', $deferred->status($correlation->deferredId));
        $updated = $deferred->findByRunAndToolCall('run-ack', 'call-1');
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $updated?->callerDisposition);
        $this->assertSame(ToolInvocationExecutionObservationEnum::Active, $updated?->executionObservation);
        $this->assertSame($correlation->executionOwnerReference?->recordId, $updated?->executionOwnerReference?->recordId);

        // Later batch: detached old Sequential must not block a new Sequential call.
        $later = TestToolBatchRegistration::register(
            $collector,
            $batches,
            'run-ack',
            2,
            'step-2',
            [$this->call('run-ack', 'step-2', 'call-later', 0, 'sequential', null, 2)],
        );
        $this->assertCount(1, $later);
        $this->assertSame('call-later', $later[0]->toolCallId);
        $this->assertSame('pending', $deferred->status($correlation->deferredId));
    }

    public function testAcknowledgementOrdersWithSiblingResultsAndIsIdempotent(): void
    {
        [$collector, $batches, $deferred, $correlation] = $this->readyCollector(
            runId: 'run-order',
            stepId: 'step-1',
            toolCallId: 'call-b',
            mode: 'parallel',
            siblings: [
                $this->call('run-order', 'step-1', 'call-a', 0, 'parallel', 2),
                $this->call('run-order', 'step-1', 'call-b', 1, 'parallel', 2),
            ],
            maxParallelism: 2,
        );

        TestToolBatchCoordination::collect(
            $collector,
            $batches,
            $this->toolResult('run-order', 'step-1', 'call-a', 0, ['content' => [['type' => 'text', 'text' => 'first']]]),
        );

        $prepared = $collector->prepareBackgroundAcknowledgement($this->readiness($correlation));
        $this->assertTrue($prepared->accepted);
        $this->assertTrue($prepared->complete);
        $this->assertCount(2, $prepared->orderedResults);
        $this->assertSame(['call-a', 'call-b'], array_map(
            static fn (ToolCallResult $result): string => $result->toolCallId,
            $prepared->orderedResults,
        ));
        $this->applyPrepared($batches, $deferred, $prepared->localActions);

        $again = $collector->prepareBackgroundAcknowledgement($this->readiness($correlation));
        $this->assertTrue($again->accepted);
        $this->assertTrue($again->duplicate);
        $this->assertSame([], $again->localActions);
        $this->assertSame($prepared->acknowledgement?->invocationId, $again->acknowledgement?->invocationId);
        $this->assertSame('pending', $deferred->status($correlation->deferredId));
    }

    public function testFinalResultFirstRefusesBackgroundAcknowledgement(): void
    {
        [$collector, $batches, $deferred, $correlation] = $this->readyCollector(
            runId: 'run-final-first',
            stepId: 'step-1',
            toolCallId: 'call-1',
            mode: 'sequential',
            siblings: [$this->call('run-final-first', 'step-1', 'call-1', 0, 'sequential')],
        );

        TestToolBatchCoordination::collect(
            $collector,
            $batches,
            $this->toolResult('run-final-first', 'step-1', 'call-1', 0, ['content' => [['type' => 'text', 'text' => 'done']]]),
        );
        $deferred->applyLifecycleProjection(new RecordToolInvocationLifecycleDTO(
            $correlation,
            ToolInvocationCallerDispositionEnum::ForegroundResultAccepted,
            ToolInvocationExecutionObservationEnum::Terminal,
        ));

        $prepared = $collector->prepareBackgroundAcknowledgement($this->readiness($correlation));
        $this->assertFalse($prepared->accepted);
        $this->assertSame('already_resolved', $prepared->rejectionReason);
        $this->assertSame('pending', $deferred->status($correlation->deferredId));
    }

    #[DataProvider('bindingRejectionProvider')]
    public function testBindingAndReadinessRejections(string $expectedReason, callable $mutate): void
    {
        [$collector, , $deferred, $correlation] = $this->readyCollector(
            runId: 'run-bind',
            stepId: 'step-1',
            toolCallId: 'call-1',
            mode: 'sequential',
            siblings: [$this->call('run-bind', 'step-1', 'call-1', 0, 'sequential')],
        );

        $readiness = $mutate($this->readiness($correlation), $correlation, $deferred);
        $prepared = $collector->prepareBackgroundAcknowledgement($readiness);
        $this->assertFalse($prepared->accepted);
        $this->assertSame($expectedReason, $prepared->rejectionReason);
    }

    /**
     * @return iterable<string, array{0: string, 1: callable}>
     */
    public static function bindingRejectionProvider(): iterable
    {
        yield 'wrong owner record' => ['wrong_owner', static function (ToolInvocationBackgroundReadiness $readiness): ToolInvocationBackgroundReadiness {
            return new ToolInvocationBackgroundReadiness(
                $readiness->runId,
                $readiness->turnNo,
                $readiness->stepId,
                $readiness->toolCallId,
                $readiness->toolName,
                new ToolInvocationOwnerReference('bash', 'other-record', 9),
                $readiness->invocationId,
                $readiness->controllerRunId,
                $readiness->envelopeFingerprint,
            );
        }];
        yield 'stale step identity' => ['stale_identity', static function (ToolInvocationBackgroundReadiness $readiness): ToolInvocationBackgroundReadiness {
            return new ToolInvocationBackgroundReadiness(
                $readiness->runId,
                $readiness->turnNo,
                'other-step',
                $readiness->toolCallId,
                $readiness->toolName,
                $readiness->ownerReference,
                $readiness->invocationId,
                $readiness->controllerRunId,
                $readiness->envelopeFingerprint,
            );
        }];
        yield 'unready missing registration' => ['not_ready', static function (ToolInvocationBackgroundReadiness $readiness): ToolInvocationBackgroundReadiness {
            return new ToolInvocationBackgroundReadiness(
                'missing-run',
                $readiness->turnNo,
                $readiness->stepId,
                $readiness->toolCallId,
                $readiness->toolName,
                $readiness->ownerReference,
            );
        }];
        yield 'cancelling observation' => ['cancelling', static function (ToolInvocationBackgroundReadiness $readiness, DeferredToolCompletionCorrelation $correlation, InMemoryDeferredToolCompletionRepository $deferred): ToolInvocationBackgroundReadiness {
            $acknowledged = $correlation->withLifecycle(
                ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
                ToolInvocationExecutionObservationEnum::Active,
            );
            $deferred->applyLifecycleProjection(new RecordToolInvocationLifecycleDTO(
                $correlation,
                ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
                ToolInvocationExecutionObservationEnum::Active,
            ));
            $deferred->applyLifecycleProjection(new RecordToolInvocationLifecycleDTO(
                $acknowledged,
                ToolInvocationCallerDispositionEnum::BackgroundAcknowledged,
                ToolInvocationExecutionObservationEnum::CancelRequested,
            ));

            return $readiness;
        }];
    }

    public function testAttachedMaxParallelismStillLimitsCurrentBatchAfterSiblingAcknowledgement(): void
    {
        [$collector, $batches, $deferred, $correlation] = $this->readyCollector(
            runId: 'run-cap',
            stepId: 'step-1',
            toolCallId: 'call-1',
            mode: 'parallel',
            siblings: [
                $this->call('run-cap', 'step-1', 'call-1', 0, 'parallel', 2),
                $this->call('run-cap', 'step-1', 'call-2', 1, 'parallel', 2),
                $this->call('run-cap', 'step-1', 'call-3', 2, 'parallel', 2),
            ],
            maxParallelism: 2,
        );

        $prepared = $collector->prepareBackgroundAcknowledgement($this->readiness($correlation));
        $this->assertTrue($prepared->accepted);
        $this->assertCount(1, $prepared->effectsToDispatch);
        $this->assertSame('call-3', $prepared->effectsToDispatch[0]->toolCallId);
        $this->applyPrepared($batches, $deferred, $prepared->localActions);

        $batch = $batches->load('run-cap', 1, 'step-1');
        $this->assertNotNull($batch);
        $this->assertSame(['call-2' => true, 'call-3' => true], $batch->inFlight);
        $this->assertSame([], $batch->pendingQueue);
    }

    public function testAcknowledgementDoesNotMarkDeferredCompletedOrReleaseNativeOwner(): void
    {
        [$collector, $batches, $deferred, $correlation] = $this->readyCollector(
            runId: 'run-native',
            stepId: 'step-1',
            toolCallId: 'call-1',
            mode: 'sequential',
            siblings: [$this->call('run-native', 'step-1', 'call-1', 0, 'sequential')],
        );

        $prepared = $collector->prepareBackgroundAcknowledgement($this->readiness($correlation));
        $this->applyPrepared($batches, $deferred, $prepared->localActions);
        $updated = $deferred->findByRunAndToolCall('run-native', 'call-1');
        $this->assertSame('pending', $deferred->status($correlation->deferredId));
        $this->assertSame('record-call-1', $updated?->executionOwnerReference?->recordId);
        $this->assertNotSame('success', $prepared->acknowledgement?->status);
        $this->assertNotSame('completed', $prepared->acknowledgement?->status);
    }

    public function testAwaitingHumanInputRefusesWithoutMutationAndAnswerStillResumes(): void
    {
        [$collector, $batches, $deferred, $correlation] = $this->readyCollector(
            runId: 'run-wait',
            stepId: 'step-1',
            toolCallId: 'call-1',
            mode: 'sequential',
            siblings: [
                $this->call('run-wait', 'step-1', 'call-1', 0, 'sequential'),
                $this->call('run-wait', 'step-1', 'call-2', 1, 'sequential'),
            ],
        );

        $effects = TestToolBatchCoordination::suspend(
            $collector,
            $batches,
            'run-wait',
            1,
            'step-1',
            'call-1',
            'question-1',
        );
        // Suspending the sequential head frees capacity for the next attached sibling.
        $this->assertCount(1, $effects);
        $this->assertSame('call-2', $effects[0]->toolCallId);
        $before = $batches->load('run-wait', 1, 'step-1');
        $this->assertNotNull($before);
        $this->assertSame(['call-1' => 'question-1'], $before->awaitingHumanInput);
        $this->assertArrayNotHasKey('call-1', $before->results);

        $prepared = $collector->prepareBackgroundAcknowledgement($this->readiness($correlation));
        $this->assertFalse($prepared->accepted);
        $this->assertSame('awaiting_human_input', $prepared->rejectionReason);
        $this->assertSame([], $prepared->localActions);

        $after = $batches->load('run-wait', 1, 'step-1');
        $this->assertEquals($before, $after);
        $unchanged = $deferred->findByRunAndToolCall('run-wait', 'call-1');
        $this->assertSame(ToolInvocationCallerDispositionEnum::ForegroundPending, $unchanged?->callerDisposition);
        $this->assertSame('pending', $deferred->status($correlation->deferredId));

        $answer = new ToolCallHumanInputAnswerDTO(
            questionId: 'question-1',
            answer: 'continue',
            continuationRef: [
                'run_id' => 'run-wait',
                'turn_no' => 1,
                'step_id' => 'step-1',
                'tool_call_id' => 'call-1',
            ],
            requestPayload: ['question_id' => 'question-1', 'prompt' => 'Allow?'],
        );
        $resumed = TestToolBatchCoordination::resume(
            $collector,
            $batches,
            'run-wait',
            1,
            'step-1',
            'call-1',
            'question-1',
            $answer,
        );
        // Sibling call-2 still occupies the sequential slot, so call-1 is requeued without a fake ack.
        $this->assertSame([], $resumed);
        $batch = $batches->load('run-wait', 1, 'step-1');
        $this->assertNotNull($batch);
        $this->assertSame([], $batch->awaitingHumanInput);
        $this->assertSame(['call-1'], $batch->pendingQueue);
        $this->assertArrayHasKey('call-2', $batch->inFlight);
        $this->assertArrayNotHasKey('call-1', $batch->results);
    }

    public function testStoredCallEnvelopeMismatchRejectsWithoutMutation(): void
    {
        [$collector, $batches, $deferred, $correlation] = $this->readyCollector(
            runId: 'run-mismatch',
            stepId: 'step-1',
            toolCallId: 'call-1',
            mode: 'sequential',
            siblings: [$this->call('run-mismatch', 'step-1', 'call-1', 0, 'sequential')],
        );

        $beforeBatch = $batches->load('run-mismatch', 1, 'step-1');
        $this->assertNotNull($beforeBatch);
        $beforeDeferred = $deferred->findByRunAndToolCall('run-mismatch', 'call-1');
        $this->assertNotNull($beforeDeferred);

        $mismatched = new DeferredToolCompletionCorrelation(
            deferredId: $correlation->deferredId,
            runId: $correlation->runId,
            turnNo: $correlation->turnNo,
            stepId: $correlation->stepId,
            attempt: $correlation->attempt,
            idempotencyKey: $correlation->idempotencyKey,
            toolCallId: $correlation->toolCallId,
            toolName: $correlation->toolName,
            arguments: ['command' => 'sleep 30'],
            orderIndex: $correlation->orderIndex,
            toolIdempotencyKey: $correlation->toolIdempotencyKey,
            mode: $correlation->mode,
            timeoutSeconds: $correlation->timeoutSeconds,
            maxParallelism: $correlation->maxParallelism,
            argSchema: $correlation->argSchema,
            toolsRef: $correlation->toolsRef,
            invocationId: $correlation->invocationId,
            controllerRunId: $correlation->controllerRunId,
            executionOwnerKind: $correlation->executionOwnerKind,
            executionOwnerReference: $correlation->executionOwnerReference,
            originalExecutionMode: $correlation->originalExecutionMode,
            deadlineMetadata: $correlation->deadlineMetadata,
        );
        $mismatched = new DeferredToolCompletionCorrelation(
            deferredId: $mismatched->deferredId,
            runId: $mismatched->runId,
            turnNo: $mismatched->turnNo,
            stepId: $mismatched->stepId,
            attempt: $mismatched->attempt,
            idempotencyKey: $mismatched->idempotencyKey,
            toolCallId: $mismatched->toolCallId,
            toolName: $mismatched->toolName,
            arguments: $mismatched->arguments,
            orderIndex: $mismatched->orderIndex,
            toolIdempotencyKey: $mismatched->toolIdempotencyKey,
            mode: $mismatched->mode,
            timeoutSeconds: $mismatched->timeoutSeconds,
            maxParallelism: $mismatched->maxParallelism,
            argSchema: $mismatched->argSchema,
            toolsRef: $mismatched->toolsRef,
            invocationId: $mismatched->invocationId,
            controllerRunId: $mismatched->controllerRunId,
            executionOwnerKind: $mismatched->executionOwnerKind,
            executionOwnerReference: $mismatched->executionOwnerReference,
            originalExecutionMode: $mismatched->originalExecutionMode,
            deadlineMetadata: $mismatched->deadlineMetadata,
            envelopeFingerprint: ToolInvocationEnvelopeFingerprint::fromCorrelation($mismatched),
            callerDisposition: $mismatched->callerDisposition,
            executionObservation: $mismatched->executionObservation,
        );
        $deferred->replaceForTest($mismatched);

        $prepared = $collector->prepareBackgroundAcknowledgement($this->readiness($mismatched));
        $this->assertFalse($prepared->accepted);
        $this->assertSame('stale_identity', $prepared->rejectionReason);
        $this->assertSame([], $prepared->localActions);

        $afterBatch = $batches->load('run-mismatch', 1, 'step-1');
        $this->assertEquals($beforeBatch, $afterBatch);
        $afterDeferred = $deferred->findByRunAndToolCall('run-mismatch', 'call-1');
        $this->assertEquals($mismatched, $afterDeferred);
        $this->assertSame('pending', $deferred->status($correlation->deferredId));
        $this->assertSame(ToolInvocationCallerDispositionEnum::ForegroundPending, $afterDeferred?->callerDisposition);
    }

    public function testNullStoredAliasUsesDeferredIdAndIgnoresRequestAliasOnRetry(): void
    {
        [$collector, $batches, $deferred, $correlation] = $this->readyCollector(
            runId: 'run-null-alias',
            stepId: 'step-1',
            toolCallId: 'call-1',
            mode: 'sequential',
            siblings: [$this->call('run-null-alias', 'step-1', 'call-1', 0, 'sequential')],
            includeInvocationId: false,
        );
        $this->assertNull($correlation->invocationId);

        $firstReadiness = new ToolInvocationBackgroundReadiness(
            $correlation->runId,
            $correlation->turnNo,
            $correlation->stepId,
            $correlation->toolCallId,
            $correlation->toolName,
            $correlation->executionOwnerReference,
            'alias-A',
            $correlation->controllerRunId,
            $correlation->envelopeFingerprint,
        );
        $prepared = $collector->prepareBackgroundAcknowledgement($firstReadiness);
        $this->assertTrue($prepared->accepted);
        $this->assertSame($correlation->deferredId, $prepared->acknowledgement?->invocationId);
        $this->assertSame($correlation->deferredId, $prepared->acknowledgement?->inspection['invocation_id'] ?? null);
        $this->applyPrepared($batches, $deferred, $prepared->localActions);

        $updated = $deferred->findByRunAndToolCall('run-null-alias', 'call-1');
        $this->assertNull($updated?->invocationId);
        $this->assertSame(ToolInvocationCallerDispositionEnum::BackgroundAcknowledged, $updated?->callerDisposition);

        $retryOmittingAlias = new ToolInvocationBackgroundReadiness(
            $correlation->runId,
            $correlation->turnNo,
            $correlation->stepId,
            $correlation->toolCallId,
            $correlation->toolName,
            $correlation->executionOwnerReference,
            null,
            $correlation->controllerRunId,
            $correlation->envelopeFingerprint,
        );
        $again = $collector->prepareBackgroundAcknowledgement($retryOmittingAlias);
        $this->assertTrue($again->accepted);
        $this->assertTrue($again->duplicate);
        $this->assertSame([], $again->localActions);
        $this->assertSame($correlation->deferredId, $again->acknowledgement?->invocationId);
        $this->assertSame(
            $prepared->acknowledgementResult?->result['content'][0]['text'] ?? null,
            $again->acknowledgementResult?->result['content'][0]['text'] ?? null,
        );

        $retryDifferentAlias = new ToolInvocationBackgroundReadiness(
            $correlation->runId,
            $correlation->turnNo,
            $correlation->stepId,
            $correlation->toolCallId,
            $correlation->toolName,
            $correlation->executionOwnerReference,
            'alias-B',
            $correlation->controllerRunId,
            $correlation->envelopeFingerprint,
        );
        $third = $collector->prepareBackgroundAcknowledgement($retryDifferentAlias);
        $this->assertTrue($third->accepted);
        $this->assertTrue($third->duplicate);
        $this->assertSame([], $third->localActions);
        $this->assertSame($correlation->deferredId, $third->acknowledgement?->invocationId);
    }

    public function testStoredAliasMismatchStillRejects(): void
    {
        [$collector, , , $correlation] = $this->readyCollector(
            runId: 'run-stored-alias',
            stepId: 'step-1',
            toolCallId: 'call-1',
            mode: 'sequential',
            siblings: [$this->call('run-stored-alias', 'step-1', 'call-1', 0, 'sequential')],
        );
        $this->assertSame('inv-call-1', $correlation->invocationId);

        $prepared = $collector->prepareBackgroundAcknowledgement(new ToolInvocationBackgroundReadiness(
            $correlation->runId,
            $correlation->turnNo,
            $correlation->stepId,
            $correlation->toolCallId,
            $correlation->toolName,
            $correlation->executionOwnerReference,
            'other-alias',
            $correlation->controllerRunId,
            $correlation->envelopeFingerprint,
        ));
        $this->assertFalse($prepared->accepted);
        $this->assertSame('stale_identity', $prepared->rejectionReason);
        $this->assertSame([], $prepared->localActions);
    }

    /**
     * @param list<ExecuteToolCall> $siblings
     *
     * @return array{0: ToolBatchCollector, 1: TestToolBatchStore, 2: InMemoryDeferredToolCompletionRepository, 3: DeferredToolCompletionCorrelation}
     */
    private function readyCollector(
        string $runId,
        string $stepId,
        string $toolCallId,
        string $mode,
        array $siblings,
        int $maxParallelism = 4,
        ?string $invocationId = null,
        bool $includeInvocationId = true,
    ): array {
        $batches = new TestToolBatchStore();
        $deferred = new InMemoryDeferredToolCompletionRepository();
        $collector = new ToolBatchCollector(new \Ineersa\AgentCore\Tests\Support\StaticToolCompletionDeliveryFenceProvider(), $batches, $maxParallelism, $deferred);
        TestToolBatchRegistration::register($collector, $batches, $runId, 1, $stepId, $siblings);

        $owner = new ToolInvocationOwnerReference('bash', 'record-'.$toolCallId, 4242, '/tmp/'.$toolCallId.'.log');
        $storedCall = null;
        foreach ($siblings as $call) {
            if ($call->toolCallId === $toolCallId) {
                $storedCall = $call;
                break;
            }
        }
        $this->assertInstanceOf(ExecuteToolCall::class, $storedCall);

        $correlation = new DeferredToolCompletionCorrelation(
            deferredId: Uuid::v7()->toRfc4122(),
            runId: $runId,
            turnNo: 1,
            stepId: $stepId,
            attempt: $storedCall->attempt(),
            idempotencyKey: $storedCall->idempotencyKey(),
            toolCallId: $toolCallId,
            toolName: $storedCall->toolName,
            arguments: $storedCall->args,
            orderIndex: $storedCall->orderIndex,
            toolIdempotencyKey: $storedCall->toolIdempotencyKey,
            mode: $storedCall->mode,
            timeoutSeconds: $storedCall->timeoutSeconds,
            maxParallelism: $storedCall->maxParallelism,
            argSchema: $storedCall->argSchema,
            toolsRef: $storedCall->toolsRef,
            invocationId: $includeInvocationId ? ($invocationId ?? 'inv-'.$toolCallId) : null,
            controllerRunId: $runId,
            executionOwnerKind: 'bash',
            executionOwnerReference: $owner,
            originalExecutionMode: $mode,
        );
        $correlation = new DeferredToolCompletionCorrelation(
            deferredId: $correlation->deferredId,
            runId: $correlation->runId,
            turnNo: $correlation->turnNo,
            stepId: $correlation->stepId,
            attempt: $correlation->attempt,
            idempotencyKey: $correlation->idempotencyKey,
            toolCallId: $correlation->toolCallId,
            toolName: $correlation->toolName,
            arguments: $correlation->arguments,
            orderIndex: $correlation->orderIndex,
            mode: $correlation->mode,
            maxParallelism: $correlation->maxParallelism,
            invocationId: $correlation->invocationId,
            controllerRunId: $correlation->controllerRunId,
            executionOwnerKind: $correlation->executionOwnerKind,
            executionOwnerReference: $correlation->executionOwnerReference,
            originalExecutionMode: $correlation->originalExecutionMode,
            envelopeFingerprint: ToolInvocationEnvelopeFingerprint::fromCorrelation($correlation),
        );
        $correlation = $deferred->registerPending($correlation);

        return [$collector, $batches, $deferred, $correlation];
    }

    private function readiness(DeferredToolCompletionCorrelation $correlation): ToolInvocationBackgroundReadiness
    {
        $owner = $correlation->executionOwnerReference;
        $this->assertNotNull($owner);

        return new ToolInvocationBackgroundReadiness(
            $correlation->runId,
            $correlation->turnNo,
            $correlation->stepId,
            $correlation->toolCallId,
            $correlation->toolName,
            $owner,
            $correlation->invocationId,
            $correlation->controllerRunId,
            $correlation->envelopeFingerprint,
        );
    }

    private function call(string $runId, string $stepId, string $toolCallId, int $order, string $mode, ?int $maxParallelism = null, int $turnNo = 1): ExecuteToolCall
    {
        return new ExecuteToolCall(
            $runId,
            $turnNo,
            $stepId,
            1,
            $toolCallId.'-key',
            $toolCallId,
            'bash',
            ['command' => 'sleep 1'],
            $order,
            mode: $mode,
            maxParallelism: $maxParallelism,
        );
    }

    /** @param array<string, mixed> $payload */
    private function toolResult(string $runId, string $stepId, string $toolCallId, int $order, array $payload): ToolCallResult
    {
        return new ToolCallResult($runId, 1, $stepId, 1, $toolCallId.'-key', $toolCallId, $order, $payload);
    }

    /** @param list<object> $actions */
    private function applyPrepared(TestToolBatchStore $batches, InMemoryDeferredToolCompletionRepository $deferred, array $actions): void
    {
        $batchActions = [];
        foreach ($actions as $action) {
            if ($action instanceof FinalizeToolBatchDTO) {
                $batchActions[] = $action;
            }
        }
        if ([] !== $batchActions) {
            $transition = new VerifiedTransitionDTO(Uuid::v7()->toRfc4122(), ['run_id' => $batchActions[0]->runId, 'actions' => $actions]);
            $batches->prepareChanges($batchActions, $transition)();
        }
        foreach ($actions as $action) {
            if ($action instanceof RecordToolInvocationLifecycleDTO) {
                $deferred->applyLifecycleProjection($action);
            }
        }
    }
}
