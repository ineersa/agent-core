<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Extension\Boundary\ConversationBoundaryNotifier;
use Ineersa\CodingAgent\Extension\Boundary\ConversationBoundaryProjector;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Runtime\Messenger\WorkerFailedEventSubscriber;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterConversationBoundaryHookInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\ConversationBoundaryDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\ConversationBoundaryOutcomeEnum;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Thesis: permanent worker-failure appends route through the shared boundary notifier.
 */
final class WorkerFailedConversationBoundaryTest extends TestCase
{
    public function testPermanentFailureNotifiesConversationBoundary(): void
    {
        $runId = 'fail-run-1';
        $runStore = $this->createMock(RunStoreInterface::class);
        $runStore->expects($this->atLeastOnce())->method('get')->with($runId)->willReturn(new RunState(
            runId: $runId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 2,
        ));
        $runStore->expects($this->once())->method('compareAndSwap')->willReturn(true);

        $eventStore = new InMemoryEventStore();
        // Seed prior history so the projector can derive a range; the subscriber appends agent_end.
        $eventStore->append(new RunEvent($runId, 1, 1, 'run_started', []));
        $eventStore->append(new RunEvent($runId, 2, 1, 'llm_step_completed', []));

        $registry = new ExtensionHookRegistry();
        $seen = [];
        $registry->addAfterConversationBoundaryHook(new class($seen) implements AfterConversationBoundaryHookInterface {
            /** @param list<ConversationBoundaryDTO> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function afterConversationBoundary(ConversationBoundaryDTO $boundary): void
            {
                $this->seen[] = $boundary;
            }
        });

        $notifier = new ConversationBoundaryNotifier(
            new ConversationBoundaryProjector($eventStore),
            $registry,
            new TestLogger(),
        );

        $subscriber = new WorkerFailedEventSubscriber($runStore, $eventStore, new TestLogger(), $notifier);
        $message = new StartRun(
            runId: $runId,
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'idem-1',
            payload: new StartRunPayload(systemPrompt: 'x'),
        );
        $event = new WorkerMessageFailedEvent(new Envelope($message), 'run_control', new \RuntimeException('boom'));

        $subscriber->onWorkerMessageFailed($event);

        $this->assertCount(1, $seen);
        $this->assertSame($runId, $seen[0]->runId);
        $this->assertSame(ConversationBoundaryOutcomeEnum::Failed, $seen[0]->outcome);
        $this->assertSame(1, $seen[0]->sourceStartSeq);
        $this->assertSame(3, $seen[0]->sourceEndSeq);
        $this->assertSame(3, $seen[0]->latestCommittedSeq);
    }
}
