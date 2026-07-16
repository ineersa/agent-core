<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\Batch\Deferred\Observation;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchIdentityFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Observation\DeferredSubagentBatchChildTurnHookSubscriber;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Observation\ObserveDeferredSubagentBatchChildTurnMessage;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[Group('db')]
final class DeferredSubagentBatchChildTurnHookSubscriberTest extends IsolatedKernelTestCase
{
    /**
     * Test thesis: only tracked Launched batch children dispatch Observe messages with
     * lifecycle id, batch index, child run id, and committed event summaries; untracked
     * and launch-Failed children dispatch nothing.
     */
    #[DataProvider('hookDispatchScenarioProvider')]
    public function testAfterTurnCommitDispatchesObservationOnlyForTrackedLaunchedChildren(
        string $scenario,
        bool $reserveTrackedLaunched,
        bool $reserveFailedChild,
        bool $useUntrackedChild,
        int $expectedDispatches,
    ): void {
        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        /** @var DeferredSubagentChildRepository $childRepo */
        $childRepo = self::getContainer()->get(DeferredSubagentChildRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-hook-'.$scenario;
        $tool = 'tool-hook-'.$scenario;
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $tracked = $factory->childIdentity($parent, $tool, 1);
        $failed = $factory->childIdentity($parent, $tool, 2);

        if ('launch_failed_child' === $scenario) {
            $onlyFailed = $factory->childIdentity($parent, $tool, 1);
            $batchRepo->reserveBatch(
                lifecycleId: $lifecycle,
                parentRunId: $parent,
                parentTurnNo: 2,
                parentToolCallId: $tool,
                parentOrderIndex: 0,
                executionMode: ChildRunBatchExecutionModeEnum::Parallel,
                totalChildCount: 1,
                deadlineAt: new \DateTimeImmutable('+600 seconds'),
                childIntents: [
                    ['batchIndex' => 1, 'childRunId' => $onlyFailed['childRunId'], 'artifactId' => $onlyFailed['artifactId'], 'agentName' => 'worker', 'task' => 'T2', 'definitionModel' => null,
                    'artifactKind' => AgentArtifactKindEnum::Subagent->value],
                ],
            );
            $childRepo->markChildFailed($lifecycle, 1);
            $failed = $onlyFailed;
        } elseif ($reserveTrackedLaunched) {
            $batchRepo->reserveBatch(
                lifecycleId: $lifecycle,
                parentRunId: $parent,
                parentTurnNo: 2,
                parentToolCallId: $tool,
                parentOrderIndex: 0,
                executionMode: ChildRunBatchExecutionModeEnum::Parallel,
                totalChildCount: 1,
                deadlineAt: new \DateTimeImmutable('+600 seconds'),
                childIntents: [
                    ['batchIndex' => 1, 'childRunId' => $tracked['childRunId'], 'artifactId' => $tracked['artifactId'], 'agentName' => 'worker', 'task' => 'T1', 'definitionModel' => null,
                    'artifactKind' => AgentArtifactKindEnum::Subagent->value],
                ],
            );
            $batchRepo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1]);
        }

        $bus = new TestMessageBus();
        $subscriber = new DeferredSubagentBatchChildTurnHookSubscriber(
            $childRepo,
            $bus,
            new TestLogger(),
        );

        if ($useUntrackedChild) {
            $childRunId = 'untracked-child-'.$scenario;
        } elseif ('launch_failed_child' === $scenario) {
            $childRunId = $factory->childIdentity($parent, $tool, 1)['childRunId'];
        } else {
            $childRunId = $tracked['childRunId'];
        }
        $events = [
            new AfterTurnCommitEventSummary(7, RunEventTypeEnum::LlmStepCompleted->value, ['usage' => ['input_tokens' => 3]]),
            new AfterTurnCommitEventSummary(8, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 2]),
        ];
        $subscriber->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: $childRunId,
            turnNo: 2,
            status: 'running',
            events: $events,
            effectsCount: 0,
        ));

        $this->assertCount($expectedDispatches, $bus->messages);
        if ($expectedDispatches > 0) {
            $this->assertInstanceOf(ObserveDeferredSubagentBatchChildTurnMessage::class, $bus->messages[0]);
            /** @var ObserveDeferredSubagentBatchChildTurnMessage $msg */
            $msg = $bus->messages[0];
            $this->assertSame($lifecycle, $msg->batchLifecycleId);
            $this->assertSame(1, $msg->batchIndex);
            $this->assertSame($tracked['childRunId'], $msg->childRunId);
            $this->assertSame(RunStatus::Running, $msg->committedStatus);
            $this->assertSame(2, $msg->turnNo);
            $this->assertCount(2, $msg->committedEvents);
            $this->assertSame(7, $msg->committedEvents[0]->seq);
        }
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool, 3: bool, 4: int}>
     */
    public static function hookDispatchScenarioProvider(): array
    {
        return [
            'tracked_launched' => ['tracked_launched', true, false, false, 1],
            'untracked_child' => ['untracked_child', true, false, true, 0],
            'launch_failed_child' => ['launch_failed_child', false, true, false, 0],
        ];
    }

    /**
     * Test thesis: hook dispatch failures are locally degraded with structured correlation
     * logging and must not leak raw exception messages or prompt/tool content.
     */
    public function testDispatchFailureIsLoggedWithoutExceptionMessage(): void
    {
        /** @var DeferredSubagentBatchRepository $batchRepo */
        $batchRepo = self::getContainer()->get(DeferredSubagentBatchRepository::class);
        /** @var DeferredSubagentChildRepository $childRepo */
        $childRepo = self::getContainer()->get(DeferredSubagentChildRepository::class);
        $factory = new DeferredSubagentBatchIdentityFactory();
        $parent = 'parent-dispatch-fail';
        $tool = 'tool-dispatch-fail';
        $lifecycle = $factory->batchLifecycleId($parent, $tool);
        $child = $factory->childIdentity($parent, $tool, 1);
        $batchRepo->reserveBatch(
            lifecycleId: $lifecycle,
            parentRunId: $parent,
            parentTurnNo: 1,
            parentToolCallId: $tool,
            parentOrderIndex: 0,
            executionMode: ChildRunBatchExecutionModeEnum::Single,
            totalChildCount: 1,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
            childIntents: [
                ['batchIndex' => 1, 'childRunId' => $child['childRunId'], 'artifactId' => $child['artifactId'], 'agentName' => 'worker', 'task' => 'task', 'definitionModel' => null,
                    'artifactKind' => AgentArtifactKindEnum::Subagent->value],
            ],
        );
        $batchRepo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1]);

        $logger = new TestLogger();
        $failingBus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('dsn=secret://should-not-log');
            }
        };
        $subscriber = new DeferredSubagentBatchChildTurnHookSubscriber($childRepo, $failingBus, $logger);
        $subscriber->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: $child['childRunId'],
            turnNo: 1,
            status: 'running',
            events: [new AfterTurnCommitEventSummary(1, RunEventTypeEnum::RunStarted->value, [])],
            effectsCount: 0,
        ));

        $this->assertContains('deferred_subagent_batch.child_turn_dispatch_failed', array_column($logger->records, 'message'));
        $record = $logger->records[array_key_last($logger->records)];
        $this->assertArrayNotHasKey('message', $record['context']);
        $this->assertSame(\RuntimeException::class, $record['context']['exception_class']);
        $this->assertSame($lifecycle, $record['context']['batch_lifecycle_id']);
        $this->assertSame($child['childRunId'], $record['context']['child_run_id']);
    }
}
