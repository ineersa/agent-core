<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Tool\SubagentToolDefinitionProvider;
use Ineersa\CodingAgent\Agent\Tool\SubagentToolHandler;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tests\Tool\Support\NativeToolSchemaProbe;
use Ineersa\CodingAgent\Tool\DTO\SubagentArgumentsDTO;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SubagentToolDefinitionProvider::class)]
#[CoversClass(SubagentToolHandler::class)]
final class SubagentToolTest extends IsolatedKernelTestCase
{
    public function testDefinitionHasCorrectNameAndNativeSchema(): void
    {
        $tool = self::getContainer()->get(SubagentToolDefinitionProvider::class);
        $def = $tool->definition();

        $this->assertSame('subagent', $def->name);
        $this->assertNull($def->parametersJsonSchema);
    }

    public function testInvokeRejectsWithoutToolContext(): void
    {
        $handler = self::getContainer()->get(SubagentToolHandler::class);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('requires an active parent run context');
        $handler->__invoke(new SubagentArgumentsDTO(agent: 'scout', task: 'do something'));
    }

    public function testSchemaRejectsUnknownConcurrencyAndBackgroundProperties(): void
    {
        $tool = self::getContainer()->get(SubagentToolDefinitionProvider::class);
        $this->assertNull($tool->definition()->parametersJsonSchema);

        $schema = NativeToolSchemaProbe::for($tool);
        $args = $schema;

        $this->assertFalse($args['additionalProperties']);
        $this->assertArrayNotHasKey('concurrency', $args['properties']);
        $this->assertArrayNotHasKey('background', $args['properties']);
        $this->assertArrayNotHasKey('tasks', $args['properties']);
        $this->assertSame(['agent', 'task'], $args['required']);
    }
}
