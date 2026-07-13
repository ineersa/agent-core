<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSingleSubagentChildTurnHookSubscriber;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\ObserveDeferredSingleSubagentChildTurnMessage;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[Group('db')]
final class DeferredSingleSubagentChildTurnObservationTest extends IsolatedKernelTestCase
{
    public function testTrackedChildCommitDispatchesObservationMessage(): void
    {
        /** @var DeferredSingleSubagentLaunchRepository $repo */
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $repo->reserve(
            parentRunId: 'parent-hook',
            parentTurnNo: 1,
            parentToolCallId: 'tool-hook',
            parentOrderIndex: 0,
            childRunId: 'child-hook-uuid',
            artifactId: 'agent_bbbbbbbbbbbbbbbb',
            agentName: 'worker',
            task: 'task',
            definitionModel: null,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
        );
        $repo->markLaunched('parent-hook', 'tool-hook', new \DateTimeImmutable());

        $bus = new TestMessageBus();
        $subscriber = new DeferredSingleSubagentChildTurnHookSubscriber($repo, $bus, new TestLogger());
        $context = new AfterTurnCommitHookContext(
            runId: 'child-hook-uuid',
            turnNo: 2,
            status: 'running',
            events: [new AfterTurnCommitEventSummary(5, RunEventTypeEnum::LlmStepCompleted->value, ['usage' => ['input_tokens' => 3]])],
            effectsCount: 0,
        );

        $subscriber->handleAfterTurnCommit($context);

        $this->assertCount(1, $bus->messages);
        $this->assertInstanceOf(ObserveDeferredSingleSubagentChildTurnMessage::class, $bus->messages[0]);
        /** @var ObserveDeferredSingleSubagentChildTurnMessage $msg */
        $msg = $bus->messages[0];
        $this->assertSame('child-hook-uuid', $msg->childRunId);
        $this->assertSame(5, $msg->committedEvents[0]->seq);
    }

    public function testUntrackedChildCommitDispatchesNothing(): void
    {
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $bus = new TestMessageBus();
        $subscriber = new DeferredSingleSubagentChildTurnHookSubscriber($repo, $bus, new TestLogger());
        $context = new AfterTurnCommitHookContext('untracked-child', 1, 'running', [new AfterTurnCommitEventSummary(1, 'x', [])], 0);
        $subscriber->handleAfterTurnCommit($context);
        $this->assertCount(0, $bus->messages);
    }

    public function testFailedLaunchProjectionDoesNotDispatch(): void
    {
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $repo->reserve(
            parentRunId: 'parent-failed',
            parentTurnNo: 1,
            parentToolCallId: 'tool-failed',
            parentOrderIndex: 0,
            childRunId: 'child-failed-uuid',
            artifactId: 'agent_eeeeeeeeeeeeeeee',
            agentName: 'worker',
            task: 'task',
            definitionModel: null,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
        );
        $repo->markFailed('parent-failed', 'tool-failed');

        $bus = new TestMessageBus();
        $subscriber = new DeferredSingleSubagentChildTurnHookSubscriber($repo, $bus, new TestLogger());
        $context = new AfterTurnCommitHookContext(
            runId: 'child-failed-uuid',
            turnNo: 1,
            status: 'running',
            events: [new AfterTurnCommitEventSummary(1, RunEventTypeEnum::RunStarted->value, [])],
            effectsCount: 0,
        );
        $subscriber->handleAfterTurnCommit($context);
        $this->assertCount(0, $bus->messages);
    }

    public function testDispatchFailureIsLoggedWithoutExceptionMessage(): void
    {
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $repo->reserve(
            parentRunId: 'parent-dispatch-fail',
            parentTurnNo: 1,
            parentToolCallId: 'tool-dispatch-fail',
            parentOrderIndex: 0,
            childRunId: 'child-dispatch-fail',
            artifactId: 'agent_ffffffffffffffff',
            agentName: 'worker',
            task: 'task',
            definitionModel: null,
            deadlineAt: new \DateTimeImmutable('+600 seconds'),
        );
        $repo->markLaunched('parent-dispatch-fail', 'tool-dispatch-fail', new \DateTimeImmutable());

        $logger = new TestLogger();
        $failingBus = new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                throw new \RuntimeException('dsn=secret://should-not-log');
            }
        };
        $subscriber = new DeferredSingleSubagentChildTurnHookSubscriber($repo, $failingBus, $logger);
        $subscriber->handleAfterTurnCommit(new AfterTurnCommitHookContext(
            runId: 'child-dispatch-fail',
            turnNo: 1,
            status: 'running',
            events: [new AfterTurnCommitEventSummary(1, 'x', [])],
            effectsCount: 0,
        ));

        $this->assertContains('deferred_single_subagent.child_turn_dispatch_failed', array_column($logger->records, 'message'));
        $record = $logger->records[array_key_last($logger->records)];
        $this->assertArrayNotHasKey('message', $record['context']);
        $this->assertSame(\RuntimeException::class, $record['context']['exception_class']);
    }
}
