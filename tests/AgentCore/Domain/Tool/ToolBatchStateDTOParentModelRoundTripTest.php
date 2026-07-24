<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use PHPUnit\Framework\TestCase;

/**
 * Crash recovery / human-input continuation must preserve the parent execution
 * model snapshot so deferred child launches cannot re-resolve session/default.
 */
final class ToolBatchStateDTOParentModelRoundTripTest extends TestCase
{
    public function testParentModelSurvivesPersistedSnapshotRoundTrip(): void
    {
        $call = new ExecuteToolCall(
            runId: 'run-tool-batch-1',
            turnNo: 3,
            stepId: 'step-tools-1',
            attempt: 1,
            idempotencyKey: 'ik-tool-1',
            toolCallId: 'call-subagent-1',
            toolName: 'subagent',
            args: ['tasks' => ['inspect']],
            orderIndex: 0,
            parentModel: 'deepseek/deepseek-v4-flash',
        );

        $batch = new ToolBatchStateDTO(
            expectedOrder: ['call-subagent-1' => 0],
            calls: ['call-subagent-1' => $call],
            pendingQueue: ['call-subagent-1'],
            inFlight: [],
            results: [],
            finalized: false,
            maxParallelism: 1,
        );

        $persisted = $batch->toPersistedArray();
        $this->assertSame(
            'deepseek/deepseek-v4-flash',
            $persisted['call_data']['call-subagent-1']['parentModel'] ?? null,
        );

        $reconstructed = ToolBatchStateDTO::fromPersistedArray(
            $persisted,
            runId: 'run-tool-batch-1',
            turnNo: 3,
            stepId: 'step-tools-1',
        );

        $this->assertArrayHasKey('call-subagent-1', $reconstructed->calls);
        $this->assertSame(
            'deepseek/deepseek-v4-flash',
            $reconstructed->calls['call-subagent-1']->parentModel,
        );
        $this->assertSame($persisted, $reconstructed->toPersistedArray());
    }
}
