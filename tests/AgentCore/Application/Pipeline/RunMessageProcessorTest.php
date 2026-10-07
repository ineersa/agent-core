<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\RunMessageHandler;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Contract\History\HistoryTailDiscardInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class RunMessageProcessorTest extends TestCase
{
    public function testDiscardPublishesBeforeResetAndNoOpHandler(): void
    {
        $this->exerciseDiscard(false);
    }

    public function testFailedDiscardAppendCannotResetMetadataOrRunHandler(): void
    {
        $this->exerciseDiscard(true);
    }

    private function exerciseDiscard(bool $failAppend): void
    {
        $active = new TestActiveRunContext();
        $active->loadRecovered(RunState::queued('run'));
        $store = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $store->expects($failAppend ? $this->once() : $this->exactly(2))->method('appendTransition')->willReturnCallback(static function (array $events) use ($failAppend): array {
            if ([] === $events) {
                return [];
            }
            $event = $events[0];
            if ($failAppend) {
                throw new \RuntimeException('append failed');
            }

            return [new RunEvent($event->runId, 7, $event->turnNo, $event->type, $event->payload)];
        });
        $discard = $this->createMock(HistoryTailDiscardInterface::class);
        $discard->method('isContextMutatingMessage')->willReturn(true);
        $discard->expects($this->once())->method('prepareForwardTailDiscard')->willReturn(RunEvent::forAppend('run', 0, 'history_tail_discarded', ['after_turn_no' => 0]));
        $discard->expects($failAppend ? $this->never() : $this->once())->method('afterDiscardCommitted')->willReturnCallback(function () use ($active): void {
            $this->assertSame(7, $active->requireLoaded('run')->lastSeq);
            $this->assertSame(1, $active->requireLoaded('run')->version);
        });
        $handler = $this->createMock(RunMessageHandler::class);
        $handler->method('supports')->willReturn(true);
        $handler->expects($failAppend ? $this->never() : $this->once())->method('handle')->willReturnCallback(function (object $message, RunState $state): HandlerResult {
            $this->assertSame(7, $state->lastSeq);

            return new HandlerResult();
        });
        $bus = new TestMessageBus();
        $dispatcher = new StepDispatcher($bus, $bus);
        $commit = new RunCommit($active, $store, $dispatcher, new NullLogger(), new ToolBatchCollector(), new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(), new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()));
        $processor = new RunMessageProcessor($active, new RunLockManager(new LockFactory(new InMemoryStore())), $commit, [$handler], $discard);
        if ($failAppend) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('append failed');
        }
        $processor->process('test', new AdvanceRun('run', 0, 'step', 1, 'identity'));
        $this->assertSame(7, $active->requireLoaded('run')->lastSeq);
    }
}
