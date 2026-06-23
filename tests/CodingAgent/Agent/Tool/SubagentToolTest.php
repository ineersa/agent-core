<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Tool\SubagentTool;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Lock\LockFactory;

#[CoversClass(SubagentTool::class)]
final class SubagentToolTest extends IsolatedKernelTestCase
{
    public function testDefinitionHasCorrectName(): void
    {
        $tool = self::getContainer()->get(SubagentTool::class);
        $def = $tool->definition();

        self::assertSame('subagent', $def->name);
        self::assertContains('agent', $def->parametersJsonSchema['required']);
        self::assertContains('task', $def->parametersJsonSchema['required']);
        self::assertFalse($def->parametersJsonSchema['additionalProperties']);
    }

    public function testDefinitionHasOnlyAgentAndTaskProperties(): void
    {
        $tool = self::getContainer()->get(SubagentTool::class);
        $def = $tool->definition();

        $props = array_keys($def->parametersJsonSchema['properties'] ?? []);
        \sort($props);
        self::assertSame(['agent', 'task'], $props);
    }

    public function testDefinitionHasSequentialExecutionMode(): void
    {
        $tool = self::getContainer()->get(SubagentTool::class);
        $def = $tool->definition();

        self::assertSame(\Ineersa\AgentCore\Domain\Tool\ToolExecutionMode::Sequential, $def->executionMode);
    }

    public function testInvokeRejectsWithoutToolContext(): void
    {
        $tool = self::getContainer()->get(SubagentTool::class);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('requires an active parent run context');
        $tool->__invoke(['agent' => 'scout', 'task' => 'do something']);
    }

    public function testInvokeWithoutContextRejectsAllShapes(): void
    {
        $tool = self::getContainer()->get(SubagentTool::class);

        $cases = [
            ['task' => 'no agent'],
            ['agent' => 's'],
            ['tasks' => [['agent' => 's', 'task' => 't']]],
            ['agent' => 's', 'task' => 't', 'concurrency' => 2],
            ['agent' => 's', 'task' => 't', 'background' => true],
        ];

        foreach ($cases as $arguments) {
            try {
                $tool->__invoke($arguments);
                self::fail('Expected ToolCallException');
            } catch (ToolCallException $e) {
                self::assertStringContainsString('requires an active parent run context', $e->getMessage());
            }
        }
    }

    public function testInvokeWithContextRejectsTasksArray(): void
    {
        $tool = self::getContainer()->get(SubagentTool::class);
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);

        $lockFactory = self::getContainer()->get(LockFactory::class);
        $lock = $lockFactory->createLock('subagent-test-tasks');
        $context = new ToolContext(
            runId: 'parent-run',
            turnNo: 0,
            toolCallId: 'tc-1',
            toolName: 'subagent',
            cancellationToken: new class implements \Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface {
                public function isCancellationRequested(): bool { return false; }
            },
            timeoutSeconds: 30,
            orderIndex: 0,
        );

        $result = $accessor->with($context, function () use ($tool) {
            try {
                $tool->__invoke([
                    'agent' => 'scout',
                    'task' => 'do it',
                    'tasks' => [['agent' => 's', 'task' => 't']],
                ]);
                return 'no-exception';
            } catch (ToolCallException $e) {
                return $e->getMessage();
            }
        });

        self::assertStringContainsString('not yet implemented', (string) $result);
        self::assertStringContainsString('tasks', (string) $result);
    }

    public function testInvokeWithContextRejectsConcurrency(): void
    {
        $tool = self::getContainer()->get(SubagentTool::class);
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);

        $lockFactory = self::getContainer()->get(LockFactory::class);
        $context = new ToolContext(
            runId: 'parent-run',
            turnNo: 0,
            toolCallId: 'tc-2',
            toolName: 'subagent',
            cancellationToken: new class implements \Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface {
                public function isCancellationRequested(): bool { return false; }
            },
            timeoutSeconds: 30,
            orderIndex: 0,
        );

        $result = $accessor->with($context, function () use ($tool) {
            try {
                $tool->__invoke([
                    'agent' => 'scout',
                    'task' => 'do it',
                    'concurrency' => 2,
                ]);
                return 'no-exception';
            } catch (ToolCallException $e) {
                return $e->getMessage();
            }
        });

        self::assertStringContainsString('not yet implemented', (string) $result);
        self::assertStringContainsString('concurrency', (string) $result);
    }

    public function testInvokeWithContextRejectsBackground(): void
    {
        $tool = self::getContainer()->get(SubagentTool::class);
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);

        $lockFactory = self::getContainer()->get(LockFactory::class);
        $context = new ToolContext(
            runId: 'parent-run',
            turnNo: 0,
            toolCallId: 'tc-3',
            toolName: 'subagent',
            cancellationToken: new class implements \Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface {
                public function isCancellationRequested(): bool { return false; }
            },
            timeoutSeconds: 30,
            orderIndex: 0,
        );

        $result = $accessor->with($context, function () use ($tool) {
            try {
                $tool->__invoke([
                    'agent' => 'scout',
                    'task' => 'do it',
                    'background' => true,
                ]);
                return 'no-exception';
            } catch (ToolCallException $e) {
                return $e->getMessage();
            }
        });

        self::assertStringContainsString('not yet implemented', (string) $result);
        self::assertStringContainsString('background', (string) $result);
    }

    public function testProviderIsAutoRegistered(): void
    {
        $tool = self::getContainer()->get(SubagentTool::class);

        self::assertInstanceOf(
            \Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface::class,
            $tool,
        );
    }
}
