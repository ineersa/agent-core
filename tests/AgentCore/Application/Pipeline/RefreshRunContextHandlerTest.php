<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\RefreshRunContextHandler;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\RefreshRunContext;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class RefreshRunContextHandlerTest extends IsolatedKernelTestCase
{
    public function testRefreshPreservesHistoryAndReplaysWithoutDuplicatingContext(): void
    {
        $old = [new AgentMessage('system', [['type' => 'text', 'text' => 'old']]), new AgentMessage('user-context', [], metadata: ['source' => 'skills_context'])];
        $tail = [new AgentMessage('user', [['type' => 'text', 'text' => 'compacted summary']], metadata: ['compact_summary' => true]), new AgentMessage('user', [['type' => 'text', 'text' => 'question']])];
        $fresh = [new AgentMessage('system', [['type' => 'text', 'text' => 'new']]), new AgentMessage('user-context', [], metadata: ['source' => 'agents_context'])];
        $state = new RunState('refresh-test', RunStatus::Completed, messages: [...$old, ...$tail]);
        $result = (new RefreshRunContextHandler())->handle(new RefreshRunContext($state->runId, $fresh), $state);
        $this->assertSame([...$fresh, ...$tail], $result->nextState?->messages);
        $this->assertSame(RunStatus::Completed, $result->nextState?->status);
        $this->assertSame([], $result->effects);
        $this->assertCount(2, $result->events[0]->payload['messages']);

        $start = RunEvent::forAppend($state->runId, 0, RunEventTypeEnum::RunStarted->value, ['payload' => ['messages' => array_map(static fn (AgentMessage $m): array => $m->toArray(), $state->messages)]]);
        $reducer = self::getContainer()->get(RunStateReducer::class);
        $replayed = $reducer->replay($state, [$start, $result->events[0], $result->events[0]]);
        $this->assertEquals($result->nextState?->messages, $replayed->messages);
    }
}
