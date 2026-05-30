<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Tool\CodingAgentToolSetResolver;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use PHPUnit\Framework\TestCase;

final class CodingAgentToolSetResolverTest extends TestCase
{
    public function testResolveReturnsAllToolNamesFromRegistry(): void
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->expects($this->once())
            ->method('activeToolNames')
            ->willReturn(['read', 'write', 'bash']);
        $registry->expects($this->once())
            ->method('activeToolDefinitions')
            ->willReturn([
                $this->makeDefinition('read'),
                $this->makeDefinition('write'),
                $this->makeDefinition('bash'),
            ]);

        $resolver = new CodingAgentToolSetResolver($registry);
        $result = $resolver->resolve('toolset:run:abc:turn:1');

        $this->assertInstanceOf(ActiveToolSet::class, $result);
        $this->assertSame(['read', 'write', 'bash'], $result->toolNames);
        $this->assertSame(['read', 'write', 'bash'], $result->allowListNames);
        $this->assertSame(
            ['read' => 'sequential', 'write' => 'sequential', 'bash' => 'sequential'],
            $result->executionModes,
        );
    }

    public function testResolveReturnsEmptySetWhenNoToolsRegistered(): void
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->expects($this->once())
            ->method('activeToolNames')
            ->willReturn([]);
        $registry->expects($this->once())
            ->method('activeToolDefinitions')
            ->willReturn([]);

        $resolver = new CodingAgentToolSetResolver($registry);
        $result = $resolver->resolve('toolset:run:abc:turn:1');

        $this->assertSame([], $result->toolNames);
        $this->assertSame([], $result->allowListNames);
        $this->assertSame([], $result->executionModes);
    }

    public function testResolveIgnoresTurnNoAndRunId(): void
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->expects($this->once())
            ->method('activeToolNames')
            ->willReturn(['read']);
        $registry->expects($this->once())
            ->method('activeToolDefinitions')
            ->willReturn([$this->makeDefinition('read')]);

        $resolver = new CodingAgentToolSetResolver($registry);
        $result = $resolver->resolve('toolset:run:x:turn:5', turnNo: 5, runId: 'x');

        $this->assertSame(['read'], $result->toolNames);
    }

    public function testResolveWithDifferentToolsRefStillReturnsSameSnapshot(): void
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->expects($this->exactly(2))
            ->method('activeToolNames')
            ->willReturn(['read']);
        $registry->expects($this->exactly(2))
            ->method('activeToolDefinitions')
            ->willReturn([$this->makeDefinition('read')]);

        $resolver = new CodingAgentToolSetResolver($registry);

        $result1 = $resolver->resolve('toolset:run:a:turn:1');
        $result2 = $resolver->resolve('toolset:run:b:turn:2');

        $this->assertSame($result1->toolNames, $result2->toolNames);
    }

    public function testResolveIncludesExecutionModesFromToolDefinitions(): void
    {
        $registry = $this->createMock(ToolRegistryInterface::class);
        $registry->expects($this->once())
            ->method('activeToolNames')
            ->willReturn(['seq_tool', 'par_tool']);
        $registry->expects($this->once())
            ->method('activeToolDefinitions')
            ->willReturn([
                new ToolDefinitionDTO(
                    name: 'seq_tool',
                    description: 'Sequential tool',
                    parametersJsonSchema: [],
                    handler: $this->dummyHandler(),
                    promptLine: 'seq_tool: Sequential',
                ),
                new ToolDefinitionDTO(
                    name: 'par_tool',
                    description: 'Parallel tool',
                    parametersJsonSchema: [],
                    handler: $this->dummyHandler(),
                    promptLine: 'par_tool: Parallel',
                    executionMode: ToolExecutionMode::Parallel,
                ),
            ]);

        $resolver = new CodingAgentToolSetResolver($registry);
        $result = $resolver->resolve('toolset:run:abc:turn:1');

        $this->assertSame(
            ['seq_tool' => 'sequential', 'par_tool' => 'parallel'],
            $result->executionModes,
        );
    }

    private function makeDefinition(string $name): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: $name,
            description: $name,
            parametersJsonSchema: [],
            handler: $this->dummyHandler(),
            promptLine: $name.': '.$name,
        );
    }

    private function dummyHandler(): ToolHandlerInterface
    {
        return new class implements ToolHandlerInterface {
            public function __invoke(array $arguments = []): string
            {
                return 'handler result';
            }
        };
    }
}
