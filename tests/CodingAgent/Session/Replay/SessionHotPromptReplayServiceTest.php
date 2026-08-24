<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Replay;

use Ineersa\AgentCore\Application\Replay\PromptStateReplayService;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryPromptStateStore;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Session\Replay\SessionHotPromptReplayService;
use PHPUnit\Framework\TestCase;

final class SessionHotPromptReplayServiceTest extends TestCase
{
    public function testBuildsHotPromptFromCommittedStateWithoutReadingEvents(): void
    {
        $events = new InMemoryEventStore();
        $store = new InMemoryPromptStateStore();
        $service = new SessionHotPromptReplayService($events, $store, new PromptStateReplayService(), new ReplayEventPreparer());
        $state = new RunState(
            runId: 'run-hot-prompt',
            status: RunStatus::Running,
            lastSeq: 42,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'Hello']]),
                new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'Hi']], metadata: ['tool_calls' => [['id' => 'call-1']]]),
            ],
        );

        $prompt = $service->rebuildHotPromptState($state);

        $this->assertSame('run_state', $prompt->source);
        $this->assertSame(42, $prompt->lastSeq);
        $this->assertSame($state->messages[0]->toArray(), $prompt->messages[0]);
        $this->assertSame($state->messages[1]->toArray(), $prompt->messages[1]);
        $this->assertSame(0, $events->allForCalls);
        $this->assertSame($prompt, $store->get($state->runId));
    }

    public function testIntegrityVerificationStillReadsCanonicalEvents(): void
    {
        $events = new InMemoryEventStore();
        $store = new InMemoryPromptStateStore();
        $service = new SessionHotPromptReplayService($events, $store, new PromptStateReplayService(), new ReplayEventPreparer());
        $events->seed(new RunEvent(runId: 'run-integrity', seq: 1, turnNo: 0, type: 'run_started', payload: []));
        $events->seed(new RunEvent(runId: 'run-integrity', seq: 3, turnNo: 1, type: 'turn_advanced', payload: []));

        $integrity = $service->verifyIntegrity('run-integrity');

        $this->assertSame([2], $integrity->missingSequences);
        $this->assertSame(1, $events->allForCalls);
    }
}
