<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Tool\SubagentToolDefinitionProvider;
use Ineersa\CodingAgent\Agent\Tool\SubagentToolHandler;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SubagentToolDefinitionProvider::class)]
#[CoversClass(SubagentToolHandler::class)]
final class SubagentToolTest extends IsolatedKernelTestCase
{
    public function testDefinitionHasCorrectNameAndParallelSchema(): void
    {
        $tool = self::getContainer()->get(SubagentToolDefinitionProvider::class);
        $def = $tool->definition();

        $this->assertSame('subagent', $def->name);
        $this->assertArrayHasKey('properties', $def->parametersJsonSchema);
        $this->assertSame(8, $def->parametersJsonSchema['properties']['tasks']['maxItems']);
        $this->assertStringContainsString('8', $def->description);
    }

    public function testInvokeRejectsWithoutToolContext(): void
    {
        $handler = self::getContainer()->get(SubagentToolHandler::class);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('requires an active parent run context');
        $handler->__invoke(['agent' => 'scout', 'task' => 'do something']);
    }

    public function testInvokeWithContextRejectsConcurrency(): void
    {
        $handler = self::getContainer()->get(SubagentToolHandler::class);
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $context = $this->toolContext('tc-concurrency');

        $message = $accessor->with($context, static function () use ($handler): string {
            try {
                $handler->__invoke(['tasks' => [['agent' => 'scout', 'task' => 't']], 'concurrency' => 2]);

                return '';
            } catch (ToolCallException $e) {
                return $e->getMessage();
            }
        });

        $this->assertStringContainsString('concurrency', $message);
        $this->assertStringContainsString('not supported', $message);
    }

    public function testInvokeWithContextRejectsBackground(): void
    {
        $handler = self::getContainer()->get(SubagentToolHandler::class);
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $context = $this->toolContext('tc-bg');

        $message = $accessor->with($context, static function () use ($handler): string {
            try {
                $handler->__invoke(['agent' => 'scout', 'task' => 't', 'background' => true]);

                return '';
            } catch (ToolCallException $e) {
                return $e->getMessage();
            }
        });

        $this->assertStringContainsString('Background', $message);
    }

    public function testInvokeWithContextRejectsMixedSingleAndParallel(): void
    {
        $handler = self::getContainer()->get(SubagentToolHandler::class);
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $context = $this->toolContext('tc-mixed');

        $this->expectException(ToolCallException::class);
        $accessor->with($context, static function () use ($handler): void {
            $handler->__invoke([
                'agent' => 'scout',
                'task' => 'single',
                'tasks' => [['agent' => 'scout', 'task' => 'parallel']],
            ]);
        });
    }

    public function testInvokeWithContextRejectsTooManyParallelTasks(): void
    {
        $handler = self::getContainer()->get(SubagentToolHandler::class);
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $context = $this->toolContext('tc-cap');

        $tasks = [];
        for ($i = 0; $i < 9; ++$i) {
            $tasks[] = ['agent' => 'scout', 'task' => 't'.$i];
        }

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('at most 8 agents');

        $accessor->with($context, static function () use ($handler, $tasks): void {
            $handler->__invoke(['tasks' => $tasks]);
        });
    }

    public function testProviderIsAutoRegistered(): void
    {
        $tool = self::getContainer()->get(SubagentToolDefinitionProvider::class);

        $this->assertInstanceOf(
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
                public function isCancellationRequested(): bool
                {
                    return false;
                }
            },
            timeoutSeconds: 30,
            orderIndex: 0,
        );
    }
}
