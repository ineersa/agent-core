<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\CodingAgent\Agent\Tool\ForkToolDefinitionBuilder;
use Ineersa\CodingAgent\Agent\Tool\ForkToolHandler;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ForkToolDefinitionBuilder::class)]
final class ForkToolDefinitionBuilderTest extends IsolatedKernelTestCase
{
    public function testSchemaExposesTaskWithOptionalModelAndThinkingWithoutLevel(): void
    {
        $handler = self::getContainer()->get(ForkToolHandler::class);
        $definition = ForkToolDefinitionBuilder::build($handler);

        $schema = $definition->parametersJsonSchema;
        $this->assertSame(['task'], $schema['required']);
        $this->assertArrayHasKey('task', $schema['properties']);
        $this->assertArrayHasKey('model', $schema['properties']);
        $this->assertArrayHasKey('thinking', $schema['properties']);
        $this->assertArrayNotHasKey('level', $schema['properties']);
        $this->assertSame(ModelResolver::LEVELS, $schema['properties']['thinking']['enum']);

        $this->assertStringNotContainsString('level=', $definition->promptLine);
        $this->assertStringNotContainsString('junior', $definition->promptLine);

        $guidelines = implode(' ', $definition->promptGuidelines);
        $this->assertStringContainsString('explicitly asked', $guidelines);
        $this->assertStringContainsString('model or thinking', $guidelines);
    }
}
