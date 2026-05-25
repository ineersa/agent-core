<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\CodingAgent\Tool\CodingAgentToolSetResolver;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use PHPUnit\Framework\TestCase;

final class CodingAgentToolSetResolverTest extends TestCase
{
    public function testResolveReturnsAllToolNamesFromRegistry(): void
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->expects(self::once())
            ->method('activeToolNames')
            ->willReturn(['read', 'write', 'bash']);

        $resolver = new CodingAgentToolSetResolver($registry);
        $result = $resolver->resolve('toolset:run:abc:turn:1');

        self::assertInstanceOf(ActiveToolSet::class, $result);
        self::assertSame(['read', 'write', 'bash'], $result->toolNames);
        self::assertSame(['read', 'write', 'bash'], $result->allowListNames);
    }

    public function testResolveReturnsEmptySetWhenNoToolsRegistered(): void
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->expects(self::once())
            ->method('activeToolNames')
            ->willReturn([]);

        $resolver = new CodingAgentToolSetResolver($registry);
        $result = $resolver->resolve('toolset:run:abc:turn:1');

        self::assertSame([], $result->toolNames);
        self::assertSame([], $result->allowListNames);
    }

    public function testResolveIgnoresTurnNoAndRunId(): void
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->expects(self::once())
            ->method('activeToolNames')
            ->willReturn(['read']);

        $resolver = new CodingAgentToolSetResolver($registry);
        $result = $resolver->resolve('toolset:run:x:turn:5', turnNo: 5, runId: 'x');

        self::assertSame(['read'], $result->toolNames);
    }

    public function testResolveWithDifferentToolsRefStillReturnsSameSnapshot(): void
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->expects(self::exactly(2))
            ->method('activeToolNames')
            ->willReturn(['read']);

        $resolver = new CodingAgentToolSetResolver($registry);

        $result1 = $resolver->resolve('toolset:run:a:turn:1');
        $result2 = $resolver->resolve('toolset:run:b:turn:2');

        self::assertSame($result1->toolNames, $result2->toolNames);
    }
}
