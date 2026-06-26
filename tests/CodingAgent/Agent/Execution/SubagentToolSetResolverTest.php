<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Agent\Execution\SubagentToolSetResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(SubagentToolSetResolver::class)]
#[CoversClass(SubagentRunMetadataReader::class)]
final class SubagentToolSetResolverTest extends TestCase
{
    public function testPassThroughWhenNoRunId(): void
    {
        $inner = $this->createMock(ToolSetResolverInterface::class);
        $inner->expects($this->once())
            ->method('resolve')
            ->with('ref', null, null)
            ->willReturn(new ActiveToolSet(toolNames: ['read', 'write']));

        $eventStore = $this->createStub(EventStoreInterface::class);

        $resolver = new SubagentToolSetResolver($inner, new SubagentRunMetadataReader($eventStore), new NullLogger());
        $result = $resolver->resolve('ref');

        $this->assertSame(['read', 'write'], $result->toolNames);
    }

    public function testPassThroughWhenNotChildRun(): void
    {
        $inner = $this->createMock(ToolSetResolverInterface::class);
        $inner->expects($this->once())
            ->method('resolve')
            ->willReturn(new ActiveToolSet(toolNames: ['read', 'write']));

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('allFor')
            ->with('parent-run')
            ->willReturn([]); // No RunStarted event at all

        $resolver = new SubagentToolSetResolver($inner, new SubagentRunMetadataReader($eventStore), new NullLogger());
        $result = $resolver->resolve('ref', runId: 'parent-run');

        $this->assertSame(['read', 'write'], $result->toolNames);
    }

    public function testFiltersChildToolsToAllowedList(): void
    {
        $inner = $this->createMock(ToolSetResolverInterface::class);
        $inner->expects($this->once())
            ->method('resolve')
            ->willReturn(new ActiveToolSet(
                toolNames: ['read', 'write', 'bash', 'edit'],
                allowListNames: ['read', 'write', 'bash', 'edit'],
                executionModes: [
                    'read' => ToolExecutionMode::Sequential,
                    'write' => ToolExecutionMode::Sequential,
                    'bash' => ToolExecutionMode::Sequential,
                    'edit' => ToolExecutionMode::Sequential,
                ],
            ));

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('allFor')
            ->with('child-run')
            ->willReturn([
                new RunEvent(
                    runId: 'child-run',
                    seq: 1,
                    turnNo: 0,
                    type: RunEventTypeEnum::RunStarted->value,
                    payload: $this->childRunStartedPayload(['read', 'bash']),
                ),
            ]);

        $resolver = new SubagentToolSetResolver($inner, new SubagentRunMetadataReader($eventStore), new NullLogger());
        $result = $resolver->resolve('ref', runId: 'child-run');

        $this->assertSame(['read', 'bash'], $result->toolNames);
        $this->assertSame(['read', 'bash'], $result->allowListNames);
        // Execution modes for removed tools should NOT appear.
        $this->assertCount(2, $result->executionModes);
        $this->assertArrayHasKey('read', $result->executionModes);
        $this->assertArrayHasKey('bash', $result->executionModes);
        $this->assertArrayNotHasKey('write', $result->executionModes);
        $this->assertArrayNotHasKey('edit', $result->executionModes);
    }

    public function testFiltersOutAllToolsWhenNoOverlap(): void
    {
        $inner = $this->createMock(ToolSetResolverInterface::class);
        $inner->expects($this->once())
            ->method('resolve')
            ->willReturn(new ActiveToolSet(
                toolNames: ['read', 'write'],
                allowListNames: ['read', 'write'],
            ));

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('allFor')
            ->with('child-run')
            ->willReturn([
                new RunEvent(
                    runId: 'child-run',
                    seq: 1,
                    turnNo: 0,
                    type: RunEventTypeEnum::RunStarted->value,
                    payload: $this->childRunStartedPayload(['bash_only']),
                ),
            ]);

        $resolver = new SubagentToolSetResolver($inner, new SubagentRunMetadataReader($eventStore), new NullLogger());
        $result = $resolver->resolve('ref', runId: 'child-run');

        $this->assertSame([], $result->toolNames);
        $this->assertSame([], $result->allowListNames);
        $this->assertSame([], $result->executionModes);
    }

    public function testFiltersChildToolsExcludingSubagent(): void
    {
        $inner = $this->createMock(ToolSetResolverInterface::class);
        $inner->expects($this->once())
            ->method('resolve')
            ->willReturn(new ActiveToolSet(
                toolNames: ['read', 'write', 'subagent'],
                allowListNames: ['read', 'write', 'subagent'],
            ));

        $eventStore = $this->createMock(EventStoreInterface::class);
        $eventStore->expects($this->once())
            ->method('allFor')
            ->with('child-run')
            ->willReturn([
                new RunEvent(
                    runId: 'child-run',
                    seq: 1,
                    turnNo: 0,
                    type: RunEventTypeEnum::RunStarted->value,
                    payload: $this->childRunStartedPayload(['read', 'write']),
                ),
            ]);

        $resolver = new SubagentToolSetResolver($inner, new SubagentRunMetadataReader($eventStore), new NullLogger());
        $result = $resolver->resolve('ref', runId: 'child-run');

        $this->assertNotContains('subagent', $result->toolNames);
    }

    /**
     * Build a correctly-shaped RunStarted event payload matching what
     * StartRunHandler produces after normalizing StartRunPayload.
     *
     * @param list<string> $allowedTools
     *
     * @return array<string, mixed>
     */
    private function childRunStartedPayload(array $allowedTools): array
    {
        return [
            'step_id' => 'start-1',
            'payload' => [
                'system_prompt' => 'You are a scout.',
                'messages' => [],
                'metadata' => [
                    'session' => [
                        'kind' => 'agent_child',
                        'parent_run_id' => 'parent-1',
                        'agent_name' => 'scout',
                        'artifact_id' => 'agent_abc123',
                        'interactive' => false,
                    ],
                    'model' => null,
                    'reasoning' => null,
                    'tools_scope' => [
                        'allowed_tools' => $allowedTools,
                        'mcp' => [
                            'mode' => 'none',
                            'tools' => [],
                        ],
                    ],
                ],
            ],
        ];
    }
}
