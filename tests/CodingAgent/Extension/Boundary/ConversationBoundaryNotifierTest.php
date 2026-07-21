<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Boundary;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Extension\Boundary\ConversationBoundaryNotifier;
use Ineersa\CodingAgent\Extension\Boundary\ConversationBoundaryProjector;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterConversationBoundaryHookInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\ConversationBoundaryDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\ConversationBoundaryOutcomeEnum;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: terminal agent_end commits deliver one post-persist boundary with
 * deterministic range/outcome/watermark; intermediate commits do not; hook
 * failure is isolated.
 */
final class ConversationBoundaryNotifierTest extends TestCase
{
    public function testTerminalCompletedBatchNotifiesOnceWithDeterministicRange(): void
    {
        $eventStore = new InMemoryEventStore();
        $eventStore->append(new RunEvent('run-1', 1, 1, 'run_started', []));
        $eventStore->append(new RunEvent('run-1', 2, 1, 'llm_step_completed', []));
        $terminal = $eventStore->append(new RunEvent('run-1', 3, 1, 'agent_end', ['reason' => 'completed']));

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

        $notifier->notifyPersistedBatch('run-1', [
            new RunEvent('run-1', 2, 1, 'llm_step_completed', []),
            $terminal,
        ]);

        $this->assertCount(1, $seen);
        $boundary = $seen[0];
        $this->assertSame('run-1', $boundary->runId);
        $this->assertSame(ConversationBoundaryOutcomeEnum::Completed, $boundary->outcome);
        $this->assertSame(1, $boundary->sourceStartSeq);
        $this->assertSame(3, $boundary->sourceEndSeq);
        $this->assertSame(3, $boundary->latestCommittedSeq);
        $this->assertSame(hash('sha256', 'run-1|3|completed'), $boundary->boundaryId);
    }

    public function testIntermediateCommitDoesNotNotify(): void
    {
        $eventStore = new InMemoryEventStore();
        $registry = new ExtensionHookRegistry();
        $called = false;
        $registry->addAfterConversationBoundaryHook(new class($called) implements AfterConversationBoundaryHookInterface {
            public function __construct(private bool &$called)
            {
            }

            public function afterConversationBoundary(ConversationBoundaryDTO $boundary): void
            {
                $this->called = true;
            }
        });

        $notifier = new ConversationBoundaryNotifier(
            new ConversationBoundaryProjector($eventStore),
            $registry,
            new TestLogger(),
        );

        $notifier->notifyPersistedBatch('run-2', [
            new RunEvent('run-2', 4, 2, 'llm_step_completed', []),
            new RunEvent('run-2', 5, 2, 'tool_batch_committed', []),
        ]);

        $this->assertFalse($called);
    }

    public function testHookFailureIsIsolatedAndDoesNotPropagate(): void
    {
        $eventStore = new InMemoryEventStore();
        $terminal = $eventStore->append(new RunEvent('run-3', 1, 1, 'agent_end', ['reason' => 'failed']));

        $registry = new ExtensionHookRegistry();
        $secondCalled = false;
        $registry->addAfterConversationBoundaryHook(new class implements AfterConversationBoundaryHookInterface {
            public function afterConversationBoundary(ConversationBoundaryDTO $boundary): void
            {
                throw new \RuntimeException('hook boom');
            }
        });
        $registry->addAfterConversationBoundaryHook(new class($secondCalled) implements AfterConversationBoundaryHookInterface {
            public function __construct(private bool &$secondCalled)
            {
            }

            public function afterConversationBoundary(ConversationBoundaryDTO $boundary): void
            {
                $this->secondCalled = true;
            }
        });

        $logger = new TestLogger();
        $notifier = new ConversationBoundaryNotifier(
            new ConversationBoundaryProjector($eventStore),
            $registry,
            $logger,
        );

        $notifier->notifyPersistedBatch('run-3', [$terminal]);

        $this->assertTrue($secondCalled);
        $this->assertSame('warning', $logger->records[0]['level'] ?? null);
        $this->assertSame('extension.conversation_boundary_hook_failed', $logger->records[0]['message'] ?? null);
    }

    public function testSecondTerminalBoundaryStartsAfterPreviousTerminal(): void
    {
        $eventStore = new InMemoryEventStore();
        $eventStore->append(new RunEvent('run-4', 1, 1, 'agent_end', ['reason' => 'completed']));
        $eventStore->append(new RunEvent('run-4', 2, 2, 'llm_step_completed', []));
        $second = $eventStore->append(new RunEvent('run-4', 3, 2, 'agent_end', ['reason' => 'cancelled']));

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
        $notifier->notifyPersistedBatch('run-4', [$second]);

        $this->assertCount(1, $seen);
        $this->assertSame(2, $seen[0]->sourceStartSeq);
        $this->assertSame(3, $seen[0]->sourceEndSeq);
        $this->assertSame(ConversationBoundaryOutcomeEnum::Cancelled, $seen[0]->outcome);
    }
}
