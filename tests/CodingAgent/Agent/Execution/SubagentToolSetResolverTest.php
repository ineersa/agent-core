<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
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
        $inner->expects(self::once())
            ->method('resolve')
            ->with('ref', null, null)
            ->willReturn(new ActiveToolSet(toolNames: ['read', 'write']));

        $eventStore = $this->createStub(EventStoreInterface::class);

        $resolver = new SubagentToolSetResolver($inner, new SubagentRunMetadataReader($eventStore), new NullLogger());
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
            ->willReturn([]); // No RunStarted event at all

        $resolver = new SubagentToolSetResolver($inner, new SubagentRunMetadataReader($eventStore), new NullLogger());
        $result = $resolver->resolve('ref', runId: 'parent-run');

        self::assertSame(['read', 'write'], $result->toolNames);
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

    public function testFiltersChildToolsToAllowedList(): void
    {
        $inner = $this->createMock(ToolSetResolverInterface::class);
        $inner->expects(self::once())
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
        $eventStore->expects(self::once())
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

        self::assertSame(['read', 'bash'], $result->toolNames);
        self::assertSame(['read', 'bash'], $result->allowListNames);
        // Execution modes for removed tools should NOT appear.
        self::assertCount(2, $result->executionModes);
        self::assertArrayHasKey('read', $result->executionModes);
        self::assertArrayHasKey('bash', $result->executionModes);
        self::assertArrayNotHasKey('write', $result->executionModes);
        self::assertArrayNotHasKey('edit', $result->executionModes);
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
                    payload: $this->childRunStartedPayload(['bash_only']),
                ),
            ]);

        $resolver = new SubagentToolSetResolver($inner, new SubagentRunMetadataReader($eventStore), new NullLogger());
        $result = $resolver->resolve('ref', runId: 'child-run');

        self::assertSame([], $result->toolNames);
        self::assertSame([], $result->allowListNames);
        self::assertSame([], $result->executionModes);
    }

    public function testFiltersChildToolsExcludingSubagent(): void
    {
        $inner = $this->createMock(ToolSetResolverInterface::class);
        $inner->expects(self::once())
            ->method('resolve')
            ->willReturn(new ActiveToolSet(
                toolNames: ['read', 'write', 'subagent'],
                allowListNames: ['read', 'write', 'subagent'],
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
                    payload: $this->childRunStartedPayload(['read', 'write']),
                ),
            ]);

        $resolver = new SubagentToolSetResolver($inner, new SubagentRunMetadataReader($eventStore), new NullLogger());
        $result = $resolver->resolve('ref', runId: 'child-run');

        self::assertNotContains('subagent', $result->toolNames);
    }
}
