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

        self::assertSame('call-abc', $toolCall->toolCallId);
        self::assertSame('read_file', $toolCall->toolName);
        self::assertSame(['path' => 'test.txt'], $toolCall->arguments);
        self::assertSame(0, $toolCall->orderIndex);
        self::assertNull($toolCall->runId);
        self::assertNull($toolCall->mode);
        self::assertNull($toolCall->timeoutSeconds);
        self::assertNull($toolCall->toolIdempotencyKey);
        self::assertSame([], $toolCall->context);
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

        self::assertSame('call-full', $toolCall->toolCallId);
        self::assertSame('web_search', $toolCall->toolName);
        self::assertSame(['query' => 'php 8.4'], $toolCall->arguments);
        self::assertSame(3, $toolCall->orderIndex);
        self::assertSame('run-full', $toolCall->runId);
        self::assertSame(ToolExecutionMode::Parallel, $toolCall->mode);
        self::assertSame(120, $toolCall->timeoutSeconds);
        self::assertSame('idem-xyz', $toolCall->toolIdempotencyKey);
        self::assertSame(['turn_no' => 2, 'retry' => false], $toolCall->context);
    }

    /* ─── ToolResult ─── */

    public function testToolResultSuccessShape(): void
    {
        $result = new ToolResult(
            toolCallId: 'call-1',
            toolName: 'web_search',
            content: [['type' => 'text', 'text' => 'result data']],
        );

        self::assertSame('call-1', $result->toolCallId);
        self::assertSame('web_search', $result->toolName);
        self::assertSame([['type' => 'text', 'text' => 'result data']], $result->content);
        self::assertNull($result->details);
        self::assertFalse($result->isError);
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

        self::assertSame('call-err', $result->toolCallId);
        self::assertTrue($result->isError);
        self::assertSame(['error_code' => 500, 'message' => 'API unavailable'], $result->details);
        self::assertSame([], $result->content);
    }

    /* ─── ToolExecutionMode enum ─── */

    #[DataProvider('toolExecutionModeProvider')]
    public function testToolExecutionModeValues(string $expectedValue, ToolExecutionMode $mode): void
    {
        self::assertSame($expectedValue, $mode->value);
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

        self::assertSame(ToolExecutionMode::Parallel, $policy->mode);
        self::assertSame(60, $policy->timeoutSeconds);
        self::assertSame(3, $policy->maxParallelism);
    }
}
