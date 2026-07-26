<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\Batch\Deferred\Progress;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Completion\DeferredSubagentBatchChildOutcomeFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Progress\DeferredSubagentBatchProgressDeliveryService;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Progress\DeferredSubagentBatchProgressSnapshotFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Progress\SubagentProgressEventAppender;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Agent\Execution\SubagentProgressSnapshotBuilder;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Session\CommittedRunEventAppender;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Thesis: concurrent deliverIfNeeded claims at most one append per aggregate revision
 * (claim-before-append CAS), and a later higher revision remains deliverable after a
 * claim that lost or an append that failed after claim.
 */
#[Group('db')]
#[CoversClass(DeferredSubagentBatchProgressDeliveryService::class)]
final class DeferredSubagentBatchProgressDeliveryRaceTest extends IsolatedKernelTestCase
{
    public function testConcurrentDeliveryClaimsAtMostOneAppendPerRevision(): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-progress-race';
        $tool = 'tool-progress-race';
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $c1 = $factory->childIdentity($parent, $tool, 1);
        $c2 = $factory->childIdentity($parent, $tool, 2);

        $repo->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 1,
            parentToolCallId: $tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 2,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'scout', 'task' => 'T1', 'definitionModel' => null],
                ['batchIndex' => 2, 'childRunId' => $c2['childRunId'], 'artifactId' => $c2['artifactId'], 'agentName' => 'worker', 'task' => 'T2', 'definitionModel' => null],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1, 2]);

        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertNotNull($batch);
        $target = $batch->aggregateProgressRevision;
        $this->assertGreaterThan(0, $target);

        $claimA = $repo->claimProgressDeliveryRevision($lifecycle, $target, $batch->deliveredProgressRevision);
        $claimB = $repo->claimProgressDeliveryRevision($lifecycle, $target, $batch->deliveredProgressRevision);
        $this->assertTrue($claimA xor $claimB, 'Exactly one concurrent claim for the same revision must succeed');

        $appended = [];
        $service = $this->createDeliveryService($repo, $appended);
        $batchAfterClaim = $repo->findByLifecycleId($lifecycle);
        $this->assertNotNull($batchAfterClaim);
        $this->assertFalse($service->deliverIfNeeded($batchAfterClaim));
        $this->assertCount(0, $appended);
    }

    public function testAppendFailureAfterClaimDoesNotBlockLaterHigherRevision(): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-progress-append-fail';
        $tool = 'tool-progress-append-fail';
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $c1 = $factory->childIdentity($parent, $tool, 1);

        $repo->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 1,
            parentToolCallId: $tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Single,
            totalChildCount: 1,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'scout', 'task' => 'T1', 'definitionModel' => null],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1]);

        $appended = [];
        $failOnce = true;
        $failingAppender = $this->createRecordingAppender($appended, $failOnce);

        $service = new DeferredSubagentBatchProgressDeliveryService(
            $repo,
            $this->createSnapshotFactory(),
            $failingAppender,
            new TestLogger(),
        );

        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertNotNull($batch);
        $firstRevision = $batch->aggregateProgressRevision;

        try {
            $service->deliverIfNeeded($batch);
            $this->fail('Expected append failure after claim');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('simulated progress append failure', $e->getMessage());
        }

        $afterFail = $repo->findByLifecycleId($lifecycle);
        $this->assertNotNull($afterFail);
        $this->assertSame($firstRevision, $afterFail->deliveredProgressRevision, 'Claim advances delivered even when append fails');
        $this->assertCount(0, $appended, 'Failed append must not record a successful payload');

        // Same revision is not retried (at-most-once-per-claimed-revision).
        $this->assertFalse($service->deliverIfNeeded($afterFail));
        $this->assertCount(0, $appended);

        // Later higher aggregate revision recovers latest state.
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement(
            'UPDATE deferred_subagent_batch SET aggregate_progress_revision = aggregate_progress_revision + 1, projection_version = projection_version + 1 WHERE lifecycle_id = :id',
            ['id' => $lifecycle],
        );
        $em->clear();

        $later = $repo->findByLifecycleId($lifecycle);
        $this->assertNotNull($later);
        $this->assertGreaterThan($firstRevision, $later->aggregateProgressRevision);
        $this->assertTrue($service->deliverIfNeeded($later));
        $this->assertCount(1, $appended);
        $final = $repo->findByLifecycleId($lifecycle);
        $this->assertNotNull($final);
        $this->assertSame($final->aggregateProgressRevision, $final->deliveredProgressRevision);
    }

    public function testDeliverIfNeededSerializesTwoServiceCallsToOneAppend(): void
    {
        $repo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-progress-serial';
        $tool = 'tool-progress-serial';
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $c1 = $factory->childIdentity($parent, $tool, 1);
        $c2 = $factory->childIdentity($parent, $tool, 2);

        $repo->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 1,
            parentToolCallId: $tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Parallel,
            totalChildCount: 2,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $c1['childRunId'], 'artifactId' => $c1['artifactId'], 'agentName' => 'scout', 'task' => 'T1', 'definitionModel' => null],
                ['batchIndex' => 2, 'childRunId' => $c2['childRunId'], 'artifactId' => $c2['artifactId'], 'agentName' => 'worker', 'task' => 'T2', 'definitionModel' => null],
            ],
        );
        $repo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1, 2]);

        $appended = [];
        $service = $this->createDeliveryService($repo, $appended);
        $batch = $repo->findByLifecycleId($lifecycle);
        $this->assertNotNull($batch);

        $first = $service->deliverIfNeeded($batch);
        $second = $service->deliverIfNeeded($batch);
        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertCount(1, $appended);
        $this->assertSame(['pending', 'pending'], array_map(
            static fn (array $child): string => (string) ($child['status'] ?? ''),
            $appended[0]['children'] ?? [],
        ));
    }

    /**
     * @param list<array<string, mixed>> $appended
     */
    private function createDeliveryService(DeferredSubagentBatchRepository $repo, array &$appended): DeferredSubagentBatchProgressDeliveryService
    {
        $failOnce = false;

        return new DeferredSubagentBatchProgressDeliveryService(
            $repo,
            $this->createSnapshotFactory(),
            $this->createRecordingAppender($appended, $failOnce),
            new TestLogger(),
        );
    }

    /**
     * @param list<array<string, mixed>> $appended
     */
    private function createRecordingAppender(array &$appended, bool &$failOnce): SubagentProgressEventAppender
    {
        $inner = self::getContainer()->get(CommittedRunEventAppender::class);

        return new class($inner, $appended, $failOnce) extends SubagentProgressEventAppender {
            public function __construct(
                CommittedRunEventAppender $inner,
                private array &$appended,
                private bool &$failOnce,
            ) {
                parent::__construct($inner);
            }

            public function append(string $parentRunId, int $parentTurnNo, string $parentToolCallId, int $parentOrderIndex, string $toolName, array $progress): RunEvent
            {
                if ($this->failOnce) {
                    $this->failOnce = false;
                    throw new \RuntimeException('simulated progress append failure');
                }
                $this->appended[] = $progress;

                // Do not touch durable parent event store — race thesis is claim/append coordination only.
                return new RunEvent(
                    runId: $parentRunId,
                    seq: 1,
                    turnNo: $parentTurnNo,
                    type: 'tool_execution_update',
                    payload: [
                        'tool_call_id' => $parentToolCallId,
                        'tool_name' => $toolName,
                        'subagent_progress' => $progress,
                    ],
                );
            }
        };
    }

    private function createSnapshotFactory(): DeferredSubagentBatchProgressSnapshotFactory
    {
        return new DeferredSubagentBatchProgressSnapshotFactory(
            new DeferredSubagentBatchChildOutcomeFactory(),
            self::getContainer()->get(SubagentChildProgressSummaryBuilder::class),
            self::getContainer()->get(SubagentProgressSnapshotBuilder::class),
        );
    }
}
