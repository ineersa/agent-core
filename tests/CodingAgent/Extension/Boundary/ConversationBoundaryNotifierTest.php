<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Boundary;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Extension\Boundary\ConversationBoundaryNotifier;
use Ineersa\CodingAgent\Extension\Boundary\ConversationBoundaryProjector;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterConversationBoundaryHookInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\ConversationBoundaryDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\ConversationBoundaryOutcomeEnum;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: terminal agent_end batches deliver one post-persist boundary with
 * sourceEndSeq/latestCommittedSeq/outcome derived solely from the current batch;
 * intermediate commits do not notify; hook failure is isolated; no event-store
 * history scan is required.
 */
final class ConversationBoundaryNotifierTest extends TestCase
{
    public function testTerminalCompletedBatchNotifiesOnceFromCurrentBatchOnly(): void
    {
        $terminal = new RunEvent('run-1', 3, 1, 'agent_end', ['reason' => 'completed']);
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
            new ConversationBoundaryProjector(),
            $registry,
            new TestLogger(),
        );

        $notifier->notifyPersistedBatch('run-1', [
            new RunEvent('run-1', 2, 1, 'llm_step_completed', ['usage' => ['input_tokens' => 1]]),
            $terminal,
        ]);

        $this->assertCount(1, $seen);
        $boundary = $seen[0];
        $this->assertSame('run-1', $boundary->runId);
        $this->assertSame(ConversationBoundaryOutcomeEnum::Completed, $boundary->outcome);
        $this->assertSame(3, $boundary->sourceEndSeq);
        $this->assertSame(3, $boundary->latestCommittedSeq);
        $this->assertSame(hash('sha256', 'run-1|3|completed'), $boundary->boundaryId);
        $this->assertCount(2, $boundary->events);
        $this->assertSame('llm_step_completed', $boundary->events[0]->type);
        $this->assertSame(['usage' => ['input_tokens' => 1]], $boundary->events[0]->payload);
        $this->assertSame(1, $boundary->events[0]->turnNo);
    }

    public function testIntermediateCommitDoesNotNotify(): void
    {
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
            new ConversationBoundaryProjector(),
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
        $terminal = new RunEvent('run-3', 1, 1, 'agent_end', ['reason' => 'failed']);
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
            new ConversationBoundaryProjector(),
            $registry,
            $logger,
        );

        $notifier->notifyPersistedBatch('run-3', [$terminal]);

        $this->assertTrue($secondCalled);
        $this->assertSame('warning', $logger->records[0]['level'] ?? null);
        $this->assertSame('extension.conversation_boundary_hook_failed', $logger->records[0]['message'] ?? null);
    }
}
