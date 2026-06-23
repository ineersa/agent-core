<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Agent\Execution\SubagentToolSetResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(SubagentToolSetResolver::class)]
final class SubagentToolSetResolverTest extends TestCase
{
    public function testPassThroughWhenNoRunId(): void
    {
        $inner = $this->createMock(ToolSetResolverInterface::class);
        $inner->expects(self::once())
            ->method('resolve')
            ->with('ref', null, null)
            ->willReturn(new ActiveToolSet(toolNames: ['read', 'write']));

        $eventStore = $this->createStub(EventStoreInterface::class);

        $resolver = new SubagentToolSetResolver($inner, $eventStore, new NullLogger());
        $result = $resolver->resolve('ref');

        self::assertSame(['read', 'write'], $result->toolNames);
    }

    public function testPassThroughWhenNotChildRun(): void
    {
        $inner = $this->createMock(ToolSetResolverInterface::class);
        $inner->expects(self::once())
            ->method('resolve')
            ->willReturn(new ActiveToolSet(toolNames: ['read', 'write']));

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects(self::once())
            ->method('allFor')
            ->with('parent-run')
            ->willReturn([]); // No RunStarted with kind=agent_child

        $resolver = new SubagentToolSetResolver($inner, $eventStore, new NullLogger());
        $result = $resolver->resolve('ref', runId: 'parent-run');

        self::assertSame(['read', 'write'], $result->toolNames);
    }

    public function testFiltersChildToolsToAllowedList(): void
    {
        $inner = $this->createMock(ToolSetResolverInterface::class);
        $inner->expects(self::once())
            ->method('resolve')
            ->willReturn(new ActiveToolSet(
                toolNames: ['read', 'write', 'bash', 'edit'],
                allowListNames: ['read', 'write', 'bash', 'edit'],
            ));

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects(self::once())
            ->method('allFor')
            ->with('child-run')
            ->willReturn([
                new RunEvent(
                    runId: 'child-run',
                    seq: 1,
                    turnNo: 0,
                    type: RunEventTypeEnum::RunStarted->value,
                    payload: [
                        'kind' => 'agent_child',
                        'tools_scope' => [
                            'allowed_tools' => ['read', 'bash'],
                        ],
                    ],
                ),
            ]);

        $resolver = new SubagentToolSetResolver($inner, $eventStore, new NullLogger());
        $result = $resolver->resolve('ref', runId: 'child-run');

        self::assertSame(['read', 'bash'], $result->toolNames);
        self::assertSame(['read', 'bash'], $result->allowListNames);
    }

    public function testFiltersOutAllToolsWhenNoOverlap(): void
    {
        $inner = $this->createMock(ToolSetResolverInterface::class);
        $inner->expects(self::once())
            ->method('resolve')
            ->willReturn(new ActiveToolSet(
                toolNames: ['read', 'write'],
                allowListNames: ['read', 'write'],
            ));

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects(self::once())
            ->method('allFor')
            ->with('child-run')
            ->willReturn([
                new RunEvent(
                    runId: 'child-run',
                    seq: 1,
                    turnNo: 0,
                    type: RunEventTypeEnum::RunStarted->value,
                    payload: [
                        'kind' => 'agent_child',
                        'tools_scope' => [
                            'allowed_tools' => ['bash_only'],
                        ],
                    ],
                ),
            ]);

        $resolver = new SubagentToolSetResolver($inner, $eventStore, new NullLogger());
        $result = $resolver->resolve('ref', runId: 'child-run');

        self::assertSame([], $result->toolNames);
        self::assertSame([], $result->allowListNames);
    }
}
