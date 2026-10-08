<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\Codex;

use Amp\CancelledException;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmStreamCancelledException;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\Codex\CodexRunCancellation;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;

final class CodexRunCancellationTest extends TestCase
{
    public function testOnlySubscribedOperationsOwnAPollWatcher(): void
    {
        $token = $this->createStub(CancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturn(false);
        $before = EventLoop::getIdentifiers();
        $cancellation = new CodexRunCancellation($token);
        $this->assertSame($before, EventLoop::getIdentifiers());
        $first = $cancellation->subscribe(static function (): void {
            self::fail('Uncancelled run must not call subscribers.');
        });
        $second = $cancellation->subscribe(static function (): void {
            self::fail('Uncancelled run must not call subscribers.');
        });
        $watchers = array_values(array_diff(EventLoop::getIdentifiers(), $before));
        $this->assertCount(1, $watchers);
        $this->assertFalse(EventLoop::isReferenced($watchers[0]));
        $cancellation->unsubscribe($first);
        $this->assertContains($watchers[0], EventLoop::getIdentifiers());
        $cancellation->unsubscribe($second);
        $this->assertSame($before, EventLoop::getIdentifiers());
        unset($cancellation);
        $this->assertSame($before, EventLoop::getIdentifiers());
    }

    public function testAlreadyCancelledRunThrowsTypedCancellationWithoutStartingWatcher(): void
    {
        $token = $this->createStub(CancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturn(true);
        $before = EventLoop::getIdentifiers();
        $cancellation = new CodexRunCancellation($token);
        $this->assertTrue($cancellation->isRequested());
        try {
            $cancellation->throwIfRequested();
            $this->fail('Expected cancellation.');
        } catch (CancelledException $exception) {
            $this->assertInstanceOf(LlmStreamCancelledException::class, $exception->getPrevious());
        }
        $this->assertSame($before, EventLoop::getIdentifiers());
    }
}
