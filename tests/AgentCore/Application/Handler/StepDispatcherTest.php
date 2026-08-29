<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use PHPUnit\Framework\TestCase;

final class StepDispatcherTest extends TestCase
{
    public function testDispatchesTransitionsToCommandBusAndExecutionEffectsToExecutionBusInOrder(): void
    {
        $commandBus = new TestMessageBus();
        $executionBus = new TestMessageBus();
        $dispatcher = new StepDispatcher($commandBus, $executionBus);

        $advance = new AdvanceRun('run-1', 1, 'advance-1', 1, 'advance-key');
        $llm = new ExecuteLlmStep('run-1', 1, 'llm-1', 1, 'llm-key', 'context-1', 'tools-1');
        $compact = new CompactRun('run-1', 1, 'compact-1', 1, 'compact-key');
        $tool = new ExecuteToolCall('run-1', 1, 'tool-1', 1, 'tool-key', 'call-1', 'read', [], 0);

        $dispatcher->dispatchEffects([$advance, $llm, $compact, $tool]);

        $this->assertSame([$advance, $compact], $commandBus->messages);
        $this->assertSame([$llm, $tool], $executionBus->messages);
    }
}
