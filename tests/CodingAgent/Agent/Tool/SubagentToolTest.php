<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Tool\SubagentTool;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

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

        // Without context, all calls fail with the context error before
        // argument validation.  This test proves the context guard fires
        // first, which is correct — subagent artifacts are parent-scoped.
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

    public function testProviderIsAutoRegistered(): void
    {
        // Verify SubagentTool implements HatfieldToolProviderInterface so
        // it is auto-tagged and registered by ToolRegistry.
        $tool = self::getContainer()->get(SubagentTool::class);

        self::assertInstanceOf(
            \Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface::class,
            $tool,
        );
    }
}
