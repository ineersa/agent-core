<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionPolicy;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\Builder\ToolCallBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToolBoundaryTest extends TestCase
{
    /* ─── ToolCall defaults ─── */

    public function testToolCallRequiredFields(): void
    {
        $toolCall = new ToolCall(
            toolCallId: 'call-abc',
            toolName: 'read_file',
            arguments: ['path' => 'test.txt'],
            orderIndex: 0,
        );

        $this->assertSame('call-abc', $toolCall->toolCallId);
        $this->assertSame('read_file', $toolCall->toolName);
        $this->assertSame(['path' => 'test.txt'], $toolCall->arguments);
        $this->assertSame(0, $toolCall->orderIndex);
        $this->assertNull($toolCall->runId);
        $this->assertNull($toolCall->mode);
        $this->assertNull($toolCall->timeoutSeconds);
        $this->assertNull($toolCall->toolIdempotencyKey);
        $this->assertSame([], $toolCall->context);
    }

    public function testToolCallBuilderFullMetadata(): void
    {
        $toolCall = ToolCallBuilder::create('call-full')
            ->withToolName('web_search')
            ->withArguments(['query' => 'php 8.4'])
            ->withOrderIndex(3)
            ->withRunId('run-full')
            ->withMode(ToolExecutionMode::Parallel)
            ->withTimeoutSeconds(120)
            ->withToolIdempotencyKey('idem-xyz')
            ->withContext(['turn_no' => 2, 'retry' => false])
            ->build();

        $this->assertSame('call-full', $toolCall->toolCallId);
        $this->assertSame('web_search', $toolCall->toolName);
        $this->assertSame(['query' => 'php 8.4'], $toolCall->arguments);
        $this->assertSame(3, $toolCall->orderIndex);
        $this->assertSame('run-full', $toolCall->runId);
        $this->assertSame(ToolExecutionMode::Parallel, $toolCall->mode);
        $this->assertSame(120, $toolCall->timeoutSeconds);
        $this->assertSame('idem-xyz', $toolCall->toolIdempotencyKey);
        $this->assertSame(['turn_no' => 2, 'retry' => false], $toolCall->context);
    }

    /* ─── ToolResult ─── */

    public function testToolResultSuccessShape(): void
    {
        $result = new ToolResult(
            toolCallId: 'call-1',
            toolName: 'web_search',
            content: [['type' => 'text', 'text' => 'result data']],
        );

        $this->assertSame('call-1', $result->toolCallId);
        $this->assertSame('web_search', $result->toolName);
        $this->assertSame([['type' => 'text', 'text' => 'result data']], $result->content);
        $this->assertNull($result->details);
        $this->assertFalse($result->isError);
    }

    public function testToolResultErrorShape(): void
    {
        $result = new ToolResult(
            toolCallId: 'call-err',
            toolName: 'web_search',
            content: [],
            details: ['error_code' => 500, 'message' => 'API unavailable'],
            isError: true,
        );

        $this->assertSame('call-err', $result->toolCallId);
        $this->assertTrue($result->isError);
        $this->assertSame(['error_code' => 500, 'message' => 'API unavailable'], $result->details);
        $this->assertSame([], $result->content);
    }

    /* ─── ToolExecutionMode enum ─── */

    #[DataProvider('toolExecutionModeProvider')]
    public function testToolExecutionModeValues(string $expectedValue, ToolExecutionMode $mode): void
    {
        $this->assertSame($expectedValue, $mode->value);
    }

    /**
     * @return array<string, array{0: string, 1: ToolExecutionMode}>
     */
    public static function toolExecutionModeProvider(): array
    {
        return [
            'sequential' => ['sequential', ToolExecutionMode::Sequential],
            'parallel' => ['parallel', ToolExecutionMode::Parallel],
            'interrupt' => ['interrupt', ToolExecutionMode::Interrupt],
        ];
    }

    /* ─── ToolExecutionPolicy ─── */

    public function testToolExecutionPolicyPreservesAllFields(): void
    {
        $policy = new ToolExecutionPolicy(
            mode: ToolExecutionMode::Parallel,
            timeoutSeconds: 60,
            maxParallelism: 3,
        );

        $this->assertSame(ToolExecutionMode::Parallel, $policy->mode);
        $this->assertSame(60, $policy->timeoutSeconds);
        $this->assertSame(3, $policy->maxParallelism);
    }
}
