<?php

declare(strict_types=1);

namespace Ineersa\Tests\Tui\Runtime;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\Tui\Runtime\RuntimeEventCallbacks;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Shared callback dispatcher used by RuntimeEventPoller and
 * SubagentLiveChildViewPoller.
 *
 * @covers \Ineersa\Tui\Runtime\RuntimeEventCallbacks
 */
final class RuntimeEventCallbacksTest extends TestCase
{
    public function testDispatchRunsMatchingCallbacksInOrderAndSkipsOthers(): void
    {
        $order = [];
        $callbacks = new RuntimeEventCallbacks(
            $this->createStub(LoggerInterface::class),
            'poller event callback failed',
            'tui.poller',
            'poller.callback_failed',
            onHumanInputRequested: static function (RuntimeEvent $e) use (&$order): void {
                $order[] = 'human:'.$e->seq;
            },
            onToolQuestionRequested: static function (RuntimeEvent $e) use (&$order): void {
                $order[] = 'question:'.$e->seq;
            },
            onToolTerminal: static function (RuntimeEvent $e) use (&$order): void {
                $order[] = 'terminal:'.$e->seq;
            },
        );

        // Each event triggers only its own callback; unrelated kinds are skipped.
        $callbacks->dispatch($this->event('human_input.requested', 1), 'run-9');
        $callbacks->dispatch($this->event('tool_question.requested', 2), 'run-9');
        $callbacks->dispatch($this->event('tool_execution.completed', 3), 'run-9');
        $callbacks->dispatch($this->event('run.completed', 4), 'run-9');

        $this->assertSame(['human:1', 'question:2', 'terminal:3'], $order);
    }

    public function testDispatchSwallowsCallbackExceptionAndKeepsLogIdentity(): void
    {
        $boom = new \RuntimeException('overlay exploded');
        $dispatched = [];
        $logger = $this->createMock(LoggerInterface::class);

        $callbacks = new RuntimeEventCallbacks(
            $logger,
            'MyPoller event callback failed',
            'tui.my_poller',
            'my_poller.callback_failed',
            onHumanInputRequested: static function () use ($boom): void {
                throw $boom;
            },
            onToolTerminal: static function (RuntimeEvent $e) use (&$dispatched): void {
                $dispatched[] = $e->seq;
            },
        );

        $logger->expects($this->once())->method('warning')
            ->with(
                'MyPoller event callback failed',
                $this->callback(static function (array $context): bool {
                    return 'tui.my_poller' === $context['component']
                        && 'my_poller.callback_failed' === $context['event_type']
                        && 'run-9' === $context['run_id']
                        && 'onHumanInputRequested' === $context['callback']
                        && 'human_input.requested' === $context['runtime_event_type']
                        && 7 === $context['seq']
                        && \RuntimeException::class === $context['exception_class']
                        && 'overlay exploded' === $context['exception_message'];
                }),
            );

        // One bad callback must not prevent later matching callbacks in the batch.
        $callbacks->dispatch($this->event('human_input.requested', 7), 'run-9');
        $callbacks->dispatch($this->event('tool_execution.failed', 8), 'run-9');

        $this->assertSame([8], $dispatched, 'terminal callback must still run after human callback throws');
    }

    public function testEventListNormalizesTraversable(): void
    {
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('events')
            ->with('run-9', 7)
            ->willReturn(new \ArrayIterator([
                $this->event('run.started', 1),
                $this->event('run.completed', 2),
            ]));

        $this->assertSame([1, 2], array_column(RuntimeEventCallbacks::eventList($client, 'run-9', 7), 'seq'));
    }

    private function event(string $type, int $seq): RuntimeEvent
    {
        return new RuntimeEvent(type: $type, runId: 'run-9', seq: $seq);
    }
}
