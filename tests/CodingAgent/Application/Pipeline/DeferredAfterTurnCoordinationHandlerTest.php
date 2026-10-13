<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Observation\ObserveDeferredSubagentBatchChildTurnMessage;
use Ineersa\CodingAgent\Application\Message\DeferredAfterTurnCoordinationDTO;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class DeferredAfterTurnCoordinationHandlerTest extends IsolatedKernelTestCase
{
    public function testBindingUsesAllocatedSequencesAfterAnAbandonedPreparation(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('allocation hole');
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $event = new RunEvent($run, 0, 1, 'agent_end', ['status' => 'completed']);
        $summary = new AfterTurnCommitEventSummary(0, 'agent_end', ['status' => 'completed']);
        $work = ['run_id' => $run, 'predecessor_seq' => 0];
        $mismatch = new ObserveDeferredSubagentBatchChildTurnMessage('batch', 1, $run, RunStatus::Completed, 1, [$summary, $summary]);
        try {
            $store->appendTransition([$event], $work + ['after_turn_actions' => [new DeferredAfterTurnCoordinationDTO($run, 0, $mismatch)]]);
            $this->fail('A mismatched observation cannot publish an intent.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Child observation differs from the staged event batch.', $exception->getMessage());
        }
        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertNull($store->latestSequenceFor($run));
        $message = new ObserveDeferredSubagentBatchChildTurnMessage('batch', 1, $run, RunStatus::Completed, 1, [$summary]);
        $store->appendTransition([$event], $work + ['after_turn_actions' => [new DeferredAfterTurnCoordinationDTO($run, 0, $message)]]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $this->assertSame([2], $pending->eventSequences);
        $this->assertSame(2, $pending->work['after_turn_actions'][0]->message->committedEvents[0]->seq);
        $this->assertSame(0, $pending->work['after_turn_actions'][0]->message->predecessorSequence);
        $container->get(PendingTransitionRecovery::class)->recover($run);
        $this->assertSame(2, $store->latestSequenceFor($run));
    }
}
