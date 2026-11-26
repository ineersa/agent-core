<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Tool\SubagentToolDefinitionProvider;
use Ineersa\CodingAgent\Agent\Tool\SubagentToolHandler;
use Ineersa\CodingAgent\Tests\Tool\Support\NativeToolSchemaProbe;
use Ineersa\CodingAgent\Tool\DTO\SubagentArgumentsDTO;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

#[CoversClass(SubagentToolDefinitionProvider::class)]
#[CoversClass(SubagentToolHandler::class)]
final class SubagentToolTest extends TestCase
{
    public function testInvokeRejectsWithoutToolContext(): void
    {
        $handler = $this->handler();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('requires an active parent run context');
        $handler->__invoke(new SubagentArgumentsDTO(agent: 'scout', task: 'do something'));
    }

    public function testSchemaRejectsUnknownConcurrencyAndBackgroundProperties(): void
    {
        $tool = new SubagentToolDefinitionProvider($this->handler());
        $definition = $tool->definition();
        $this->assertSame('subagent', $definition->name);
        $this->assertNull($definition->parametersJsonSchema);

        $schema = NativeToolSchemaProbe::for($tool);
        $args = $schema;

        $this->assertFalse($args['additionalProperties']);
        $this->assertArrayNotHasKey('concurrency', $args['properties']);
        $this->assertArrayNotHasKey('background', $args['properties']);
        $this->assertArrayNotHasKey('tasks', $args['properties']);
        $this->assertSame(['agent', 'task'], $args['required']);
    }

    private function handler(): SubagentToolHandler
    {
        $accessor = new StackToolExecutionContextAccessor();

        return new SubagentToolHandler($accessor, new ToolRuntime($accessor), new ServiceLocator([]));
    }
}
