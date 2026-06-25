<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\CodingAgent\Agent\Tool\SubagentToolDefinitionBuilder;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class SubagentToolDefinitionBuilderTest extends IsolatedKernelTestCase
{
    public function testBuildDoesNotSetToolExecutorTimeout(): void
    {
        $handler = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Tool\SubagentToolHandler::class);
        $def = SubagentToolDefinitionBuilder::build(new AgentsConfig(subagentToolTimeoutSeconds: 1800), $handler);

        self::assertNull($def->timeoutSeconds);
        self::assertStringContainsString('full child handoff inline', $def->description);
    }
}
