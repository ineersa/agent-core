<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\AdvanceRunCoordinationFactory;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;

/** Continuations retain the captured step and idempotency identity. */
final class AdvanceRunCoordinationFactoryTest extends TestCase
{
    public function testCreateDispatchesAdvanceRunWithCanonicalKeyAndAttempt(): void
    {
        $commandBus = new TestMessageBus();

        $callback = AdvanceRunCoordinationFactory::create('run-advance-1', 7, 'follow-up');
        \Ineersa\AgentCore\Tests\Support\CoordinationActionTestRunner::run($callback, $commandBus);

        $this->assertCount(1, $commandBus->messages);
        $advance = $commandBus->messages[0];
        $this->assertInstanceOf(AdvanceRun::class, $advance);
        $this->assertSame('run-advance-1', $advance->runId());
        $this->assertSame(7, $advance->turnNo());
        $this->assertSame(1, $advance->attempt());
        $this->assertStringStartsWith('follow-up-', $advance->stepId());
        $this->assertSame(
            hash('sha256', \sprintf('%s|%s', $advance->runId(), $advance->stepId())),
            $advance->idempotencyKey(),
        );
    }

    public function testRepeatedDispatchPreservesPreparedIdentity(): void
    {
        $commandBus = new TestMessageBus();
        $callback = AdvanceRunCoordinationFactory::create('run-advance-2', 3, 'post-cancel-advance');

        \Ineersa\AgentCore\Tests\Support\CoordinationActionTestRunner::run($callback, $commandBus);
        \Ineersa\AgentCore\Tests\Support\CoordinationActionTestRunner::run($callback, $commandBus);

        $this->assertCount(2, $commandBus->messages);
        $this->assertSame($commandBus->messages[0]->stepId(), $commandBus->messages[1]->stepId());
        $this->assertStringStartsWith('post-cancel-advance-', $commandBus->messages[0]->stepId());
    }
}
