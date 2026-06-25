<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\CodingAgent\Agent\Tool\SubagentToolDefinitionBuilder;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class SubagentToolDefinitionBuilderTest extends IsolatedKernelTestCase
{
    public function testBuildSetsLongSubagentTimeout(): void
    {
        $handler = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Tool\SubagentToolHandler::class);
        $def = SubagentToolDefinitionBuilder::build(new AgentsConfig(subagentToolTimeoutSeconds: 900), $handler);

        self::assertSame(900, $def->timeoutSeconds);
        self::assertStringContainsString('full child handoff inline', $def->description);
    }
}
