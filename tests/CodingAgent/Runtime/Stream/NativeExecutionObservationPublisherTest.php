<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Stream\NativeExecutionObservationPublisher;
use Ineersa\CodingAgent\Session\Event\RunEventPublishedEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class NativeExecutionObservationPublisherTest extends IsolatedKernelTestCase
{
    public function testRoutesToRecordedRootWithoutLosingNativeOwner(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $root = $sessions->createSession('root');
        $other = $sessions->createSession('other');
        $container->get(AgentArtifactRegistry::class)->create($root, 'agent-child', 'child', 'worker', AgentArtifactKindEnum::Subagent);
        $relationships = $container->get(RunOperationalProjectionRepository::class);
        $relationships->replace(RunState::queued('nested')->with(['parentRunId' => 'child']));
        $relationships->replace(RunState::queued('cycle')->with(['parentRunId' => 'cycle']));
        $publisher = $container->get(NativeExecutionObservationPublisher::class);
        $sink = $container->get(InMemoryRuntimeEventSink::class);
        $previous = getenv('HATFIELD_CONSUMER_STDOUT_EVENTS');
        putenv('HATFIELD_CONSUMER_STDOUT_EVENTS=0');
        try {
            foreach ([$root, 'child', 'nested', $other, 'unknown', 'cycle'] as $owner) {
                $publisher->publish($owner, 'bash:1', true);
            }
        } finally {
            putenv(false === $previous ? 'HATFIELD_CONSUMER_STDOUT_EVENTS' : 'HATFIELD_CONSUMER_STDOUT_EVENTS='.$previous);
        }

        $events = iterator_to_array($sink->drain($root));
        $this->assertSame([$root, $root, $root], array_column($events, 'runId'));
        $this->assertSame([0, 0, 0], array_column($events, 'seq'));
        $this->assertSame([$root, 'child', 'nested'], array_column(array_column($events, 'payload'), 'owner_run_id'));
        $this->assertSame([$other], array_column(iterator_to_array($sink->drain($other)), 'runId'));
        foreach (['child', 'nested', 'unknown', 'cycle'] as $owner) {
            $this->assertSame([], iterator_to_array($sink->drain($owner)));
        }
    }

    public function testPublishedCanonicalChildLifecycleRoutesNestedAndResumedExecution(): void
    {
        $container = self::getContainer();
        $root = $container->get(HatfieldSessionStore::class)->createSession('root');
        $container->get(AgentArtifactRegistry::class)->create($root, 'agent-fork', 'fork-run', 'worker', AgentArtifactKindEnum::Fork);
        $container->get(RunOperationalProjectionRepository::class)->replace(RunState::queued('nested')->with(['parentRunId' => 'fork-run']));
        $dispatcher = $container->get(EventDispatcherInterface::class);
        $previous = getenv('HATFIELD_CONSUMER_STDOUT_EVENTS');
        putenv('HATFIELD_CONSUMER_STDOUT_EVENTS=0');
        try {
            foreach ([
                [$root, RunEventTypeEnum::RunStarted, []],
                ['fork-run', RunEventTypeEnum::RunStarted, []],
                ['nested', RunEventTypeEnum::RunStarted, []],
                ['fork-run', RunEventTypeEnum::TurnAdvanced, []],
                ['fork-run', RunEventTypeEnum::AgentEnd, ['reason' => 'failed', 'error' => 'private error']],
                ['fork-run', RunEventTypeEnum::TurnAdvanced, ['resumes_execution' => true]],
                ['fork-run', RunEventTypeEnum::WaitingHuman, ['question_id' => 'private-id', 'approval_context' => [], 'prompt' => 'private prompt']],
                ['fork-run', RunEventTypeEnum::AgentCommandApplied, ['kind' => 'human_response', 'question_id' => 'private-id', 'answer' => 'private answer']],
                ['fork-run', RunEventTypeEnum::ToolExecutionEnd, ['is_error' => true]],
                ['fork-run', RunEventTypeEnum::AgentEnd, ['reason' => 'cancelled']],
                ['nested', RunEventTypeEnum::AgentEnd, ['reason' => 'completed']],
                ['unknown', RunEventTypeEnum::AgentEnd, []],
            ] as $i => [$run, $type, $payload]) {
                $dispatcher->dispatch(new RunEventPublishedEvent(new RunEvent($run, $i + 1, 1, $type->value, $payload)));
            }
        } finally {
            putenv(false === $previous ? 'HATFIELD_CONSUMER_STDOUT_EVENTS' : 'HATFIELD_CONSUMER_STDOUT_EVENTS='.$previous);
        }
        $events = iterator_to_array($container->get(InMemoryRuntimeEventSink::class)->drain($root));
        $this->assertSame(['child_execution.started', 'child_execution.started', 'child_execution.turn_observed', 'child_execution.ended', 'child_execution.started', 'child_execution.turn_observed', 'child_execution.turn_observed', 'child_execution.turn_observed', 'child_execution.ended', 'child_execution.ended'], array_column($events, 'type'));
        $this->assertSame([0, 0, 0, 0, 0, 0, 0, 0, 0, 0], array_column($events, 'seq'));
        $this->assertSame([
            ['child_run_id' => 'fork-run', 'parent_run_id' => $root, 'child_sequence' => 2],
            ['child_run_id' => 'nested', 'parent_run_id' => 'fork-run', 'child_sequence' => 3],
            ['child_run_id' => 'fork-run', 'parent_run_id' => $root, 'child_sequence' => 4, 'phase' => 'turn'],
            ['child_run_id' => 'fork-run', 'parent_run_id' => $root, 'child_sequence' => 5, 'outcome' => 'failed'],
            ['child_run_id' => 'fork-run', 'parent_run_id' => $root, 'child_sequence' => 6],
            ['child_run_id' => 'fork-run', 'parent_run_id' => $root, 'child_sequence' => 6, 'phase' => 'turn'],
            ['child_run_id' => 'fork-run', 'parent_run_id' => $root, 'child_sequence' => 7, 'phase' => 'permission', 'request_id' => hash('sha256', 'private-id')],
            ['child_run_id' => 'fork-run', 'parent_run_id' => $root, 'child_sequence' => 8, 'phase' => 'resolved', 'request_id' => hash('sha256', 'private-id')],
            ['child_run_id' => 'fork-run', 'parent_run_id' => $root, 'child_sequence' => 10, 'outcome' => 'cancelled'],
            ['child_run_id' => 'nested', 'parent_run_id' => 'fork-run', 'child_sequence' => 11, 'outcome' => 'unknown'],
        ], array_column($events, 'payload'));
    }
}
