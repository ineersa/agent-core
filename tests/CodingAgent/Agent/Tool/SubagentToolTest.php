<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Tool\SubagentTool;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SubagentTool::class)]
final class SubagentToolTest extends IsolatedKernelTestCase
{
    public function testDefinitionHasCorrectNameAndParallelSchema(): void
    {
        $tool = self::getContainer()->get(SubagentTool::class);
        $def = $tool->definition();

        self::assertSame('subagent', $def->name);
        self::assertArrayHasKey('properties', $def->parametersJsonSchema);
        self::assertSame(8, $def->parametersJsonSchema['properties']['tasks']['maxItems']);
        self::assertStringContainsString('8', $def->description);
    }

    public function testInvokeRejectsWithoutToolContext(): void
    {
        $tool = self::getContainer()->get(SubagentTool::class);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('requires an active parent run context');
        $tool->__invoke(['agent' => 'scout', 'task' => 'do something']);
    }

    public function testInvokeWithContextRejectsConcurrency(): void
    {
        $tool = self::getContainer()->get(SubagentTool::class);
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $context = $this->toolContext('tc-concurrency');

        $message = $accessor->with($context, function () use ($tool): string {
            try {
                $tool->__invoke(['tasks' => [['agent' => 'scout', 'task' => 't']], 'concurrency' => 2]);
                return '';
            } catch (ToolCallException $e) {
                return $e->getMessage();
            }
        });

        self::assertStringContainsString('concurrency', $message);
        self::assertStringContainsString('not supported', $message);
    }

    public function testInvokeWithContextRejectsBackground(): void
    {
        $tool = self::getContainer()->get(SubagentTool::class);
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $context = $this->toolContext('tc-bg');

        $message = $accessor->with($context, function () use ($tool): string {
            try {
                $tool->__invoke(['agent' => 'scout', 'task' => 't', 'background' => true]);
                return '';
            } catch (ToolCallException $e) {
                return $e->getMessage();
            }
        });

        self::assertStringContainsString('Background', $message);
    }

    public function testInvokeWithContextRejectsMixedSingleAndParallel(): void
    {
        $tool = self::getContainer()->get(SubagentTool::class);
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $context = $this->toolContext('tc-mixed');

        $this->expectException(ToolCallException::class);
        $accessor->with($context, function () use ($tool): void {
            $tool->__invoke([
                'agent' => 'scout',
                'task' => 'single',
                'tasks' => [['agent' => 'scout', 'task' => 'parallel']],
            ]);
        });
    }

    public function testInvokeWithContextRejectsTooManyParallelTasks(): void
    {
        $tool = self::getContainer()->get(SubagentTool::class);
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $context = $this->toolContext('tc-cap');

        $tasks = [];
        for ($i = 0; $i < 9; ++$i) {
            $tasks[] = ['agent' => 'scout', 'task' => 't'.$i];
        }

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('at most 8 agents');

        $accessor->with($context, function () use ($tool, $tasks): void {
            $tool->__invoke(['tasks' => $tasks]);
        });
    }

    public function testProviderIsAutoRegistered(): void
    {
        $tool = self::getContainer()->get(SubagentTool::class);

        self::assertInstanceOf(
            \Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface::class,
            $tool,
        );
    }

    private function toolContext(string $toolCallId): ToolContext
    {
        return new ToolContext(
            runId: 'parent-run',
            turnNo: 0,
            toolCallId: $toolCallId,
            toolName: 'subagent',
            cancellationToken: new class implements \Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface {
                public function isCancellationRequested(): bool { return false; }
            },
            timeoutSeconds: 30,
            orderIndex: 0,
        );
    }
}
