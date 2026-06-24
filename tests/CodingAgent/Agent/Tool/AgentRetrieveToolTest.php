<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Tool\AgentRetrieveTool;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AgentRetrieveTool::class)]
final class AgentRetrieveToolTest extends IsolatedKernelTestCase
{
    public function testDefinitionHasCorrectNameAndSchema(): void
    {
        $tool = self::getContainer()->get(AgentRetrieveTool::class);
        $def = $tool->definition();

        self::assertSame('agent_retrieve', $def->name);
        self::assertFalse($def->parametersJsonSchema['additionalProperties']);
        self::assertSame(
            ['handoff', 'metadata', 'events', 'history', 'debug'],
            $def->parametersJsonSchema['properties']['mode']['enum'] ?? [],
        );
        self::assertSame(\Ineersa\AgentCore\Domain\Tool\ToolExecutionMode::Sequential, $def->executionMode);
    }

    public function testInvokeRejectsWithoutToolContext(): void
    {
        $tool = self::getContainer()->get(AgentRetrieveTool::class);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('requires an active parent run context');
        $tool->__invoke(['artifact_id' => 'agent_x']);
    }

    public function testInvokeRejectsMissingIdentifiersWithContext(): void
    {
        $tool = self::getContainer()->get(AgentRetrieveTool::class);
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $context = new ToolContext(
            runId: 'parent-run',
            turnNo: 0,
            toolCallId: 'tc-1',
            toolName: 'agent_retrieve',
            cancellationToken: new class implements \Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface {
                public function isCancellationRequested(): bool { return false; }
            },
            timeoutSeconds: 30,
            orderIndex: 0,
        );

        $accessor->with($context, function () use ($tool): void {
            try {
                $tool->__invoke([]);
                self::fail('Expected ToolCallException');
            } catch (ToolCallException $e) {
                self::assertStringContainsString('at least one identifier', $e->getMessage());
            }
        });
    }
}
