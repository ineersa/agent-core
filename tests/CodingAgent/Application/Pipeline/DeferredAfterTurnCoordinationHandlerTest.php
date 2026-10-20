<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Observation\ObserveDeferredSubagentBatchChildTurnMessage;
use Ineersa\CodingAgent\Application\Message\DeferredAfterTurnCoordinationDTO;
use Ineersa\CodingAgent\Application\Pipeline\DeferredAfterTurnCoordinationHandler;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DeferredAfterTurnCoordinationHandlerTest extends IsolatedKernelTestCase
{
    public static function mismatchedIdentities(): iterable
    {
        yield 'run' => ['run'];
        yield 'predecessor' => ['predecessor'];
        yield 'action' => ['action'];
        yield 'allocated sequences' => ['sequences'];
    }

    #[DataProvider('mismatchedIdentities')]
    public function testReusedWorkRetainsCoordinationIdentityChecks(string $mismatch): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('identity fence');
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $message = new ObserveDeferredSubagentBatchChildTurnMessage('batch', 1, $run, RunStatus::Completed, 1, [new AfterTurnCommitEventSummary(0, 'agent_end', [])]);
        $action = new DeferredAfterTurnCoordinationDTO($run, 0, $message);
        $store->appendTransition([new RunEvent($run, 0, 0, 'agent_end', [])], ['run_id' => $run, 'predecessor_seq' => 0, 'after_turn_actions' => [$action]]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $action = $pending->work['after_turn_actions'][0];
        if ('run' === $mismatch) {
            $pending = new VerifiedTransitionDTO($pending->identity, array_replace($pending->work, ['run_id' => 'different']), $pending->eventSequences);
        } elseif ('predecessor' === $mismatch) {
            $action = new DeferredAfterTurnCoordinationDTO($run, 1, $action->message);
        } elseif ('action' === $mismatch) {
            $action = new DeferredAfterTurnCoordinationDTO($run, 0, new ObserveDeferredSubagentBatchChildTurnMessage('different', 1, $run, RunStatus::Completed, 1, $action->message->committedEvents));
        } else {
            $pending = new VerifiedTransitionDTO($pending->identity, $pending->work, [999]);
        }
        try {
            $container->get(DeferredAfterTurnCoordinationHandler::class)($action, $pending);
            $this->fail('Mismatched captured identities cannot coordinate.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString(match ($mismatch) {
                'run' => 'work run identity mismatch',
                'predecessor', 'action' => 'verified owner transition',
                'sequences' => 'allocated canonical sequences',
            }, $exception->getMessage());
        }
        $this->assertNotNull($store->verifiedPendingTransition($run));
        $this->assertNull($store->latestSequenceFor($run));
    }

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
