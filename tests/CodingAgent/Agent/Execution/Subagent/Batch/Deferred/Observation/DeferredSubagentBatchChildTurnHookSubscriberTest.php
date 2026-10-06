<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\Batch\Deferred\Observation;

use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
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
                    ['batchIndex' => 1, 'childRunId' => $onlyFailed['childRunId'], 'artifactId' => $onlyFailed['artifactId'], 'agentName' => 'worker', 'task' => 'T2', 'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium'],
                ],
            );
            $batchRepo->applyLaunchFailurePreparation($parent, $tool, $lifecycle);
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
                    ['batchIndex' => 1, 'childRunId' => $tracked['childRunId'], 'artifactId' => $tracked['artifactId'], 'agentName' => 'worker', 'task' => 'T1', 'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium'],
                ],
            );
            $batchRepo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1]);
        }

        $bus = new TestMessageBus();
        $subscriber = new DeferredSubagentBatchChildTurnHookSubscriber($childRepo);

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
        $actions = $subscriber->prepareAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: $childRunId,
            turnNo: 2,
            status: 'running',
            events: $events,
            effectsCount: 0,
            runState: new RunState($childRunId, RunStatus::Running, turnNo: 2),
        ), 0);

        $this->assertCount($expectedDispatches, $actions);
        if ($expectedDispatches > 0) {
            $this->assertInstanceOf(ObserveDeferredSubagentBatchChildTurnMessage::class, $actions[0]->message);
            /** @var ObserveDeferredSubagentBatchChildTurnMessage $msg */
            $msg = $actions[0]->message;
            $this->assertSame($lifecycle, $msg->batchLifecycleId);
            $this->assertSame(1, $msg->batchIndex);
            $this->assertSame($tracked['childRunId'], $msg->childRunId);
            $this->assertSame(RunStatus::Running, $msg->committedStatus);
            $this->assertSame(2, $msg->turnNo);
            $this->assertCount(2, $msg->committedEvents);
            $this->assertSame(7, $msg->committedEvents[0]->seq);
            // This complete canonical batch follows cursor zero despite allocation holes.
            $bound = $actions[0]->bindCanonicalSequences([7, 8]);
            self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Observation\ObserveDeferredSubagentBatchChildTurnHandler::class)($bound->message);
            $this->assertSame(8, $childRepo->findEntityByBatchLifecycleAndIndex($lifecycle, 1)->childEventCursor);
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
     * Preparation captures the obligation instead of dispatching best effort.
     * DeferredAfterTurnCoordinationHandlerTest covers retained broker failures.
     */
    public function testPreparationCapturesObservationWithoutDispatch(): void
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
                ['batchIndex' => 1, 'childRunId' => $child['childRunId'], 'artifactId' => $child['artifactId'], 'agentName' => 'worker', 'task' => 'task', 'launchModel' => 'deepseek/deepseek-v4-flash', 'launchReasoning' => 'medium'],
            ],
        );
        $batchRepo->applyLaunchSuccessState($parent, $tool, $lifecycle, new \DateTimeImmutable(), [1]);

        $subscriber = new DeferredSubagentBatchChildTurnHookSubscriber($childRepo);
        $actions = $subscriber->prepareAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: $child['childRunId'],
            turnNo: 1,
            status: 'running',
            events: [new AfterTurnCommitEventSummary(1, RunEventTypeEnum::RunStarted->value, [])],
            effectsCount: 0,
            runState: new RunState($child['childRunId'], RunStatus::Running, turnNo: 1),
        ), 0);

        $this->assertCount(1, $actions);
        $this->assertSame($lifecycle, $actions[0]->message->batchLifecycleId);
    }
}
