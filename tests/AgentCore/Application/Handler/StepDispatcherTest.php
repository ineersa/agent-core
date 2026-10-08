<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;

final class StepDispatcherTest extends TestCase
{
    public function testDispatchesCoordinationActionsOnCommandBusInOrder(): void
    {
        $commandBus = new TestMessageBus();
        $dispatcher = new StepDispatcher($commandBus);

        $advance = new AdvanceRun('run-1', 1, 'advance-1', 1, 'advance-key');
        $compact = new CompactRun('run-1', 1, 'compact-1', 1, 'compact-key');

        $dispatcher->dispatchCoordinationActions([$advance, $compact]);

        $this->assertSame([$advance, $compact], $commandBus->messages);
    }
}
