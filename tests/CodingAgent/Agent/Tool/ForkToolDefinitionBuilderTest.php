<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\CodingAgent\Agent\Tool\ForkToolDefinitionBuilder;
use Ineersa\CodingAgent\Agent\Tool\ForkToolHandler;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ForkToolDefinitionBuilder::class)]
final class ForkToolDefinitionBuilderTest extends IsolatedKernelTestCase
{
    public function testSchemaExposesTaskOnlyWithoutLevel(): void
    {
        $handler = self::getContainer()->get(ForkToolHandler::class);
        $definition = ForkToolDefinitionBuilder::build($handler);

        $schema = $definition->parametersJsonSchema;
        $this->assertSame(['task'], $schema['required']);
        $this->assertArrayHasKey('task', $schema['properties']);
        $this->assertArrayNotHasKey('level', $schema['properties']);
        $this->assertStringNotContainsString('level=', $definition->promptLine);
        $this->assertStringNotContainsString('junior', $definition->promptLine);
    }
}
