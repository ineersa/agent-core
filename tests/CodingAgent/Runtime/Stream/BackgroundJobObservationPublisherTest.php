<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Stream\BackgroundJobObservationPublisher;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class BackgroundJobObservationPublisherTest extends IsolatedKernelTestCase
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
        $publisher = $container->get(BackgroundJobObservationPublisher::class);
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
}
