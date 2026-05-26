<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\CodingAgent\Extension\ExtensionToolRegistryBridge;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use Ineersa\Hatfield\ExtensionApi\ToolRegistrationDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ExtensionToolRegistryBridge — the adapter that maps public
 * ExtensionApi ToolRegistrationDTOs into the CodingAgent ToolRegistry
 * permanent tool registrations.
 */
final class ExtensionToolRegistryBridgeTest extends TestCase
{
    // ── Basic registration flow ──

    public function testRegisterToolForwardsToRegistry(): void
    {
        $registry = new ToolRegistry();
        $bridge = new ExtensionToolRegistryBridge($registry);

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'ext_tool',
            description: 'An extension-provided tool',
            parametersJsonSchema: ['type' => 'object', 'properties' => ['foo' => ['type' => 'string']]],
            handler: $this->dummyHandler(),
            promptSummary: 'ext_tool: do extension stuff',
            promptGuidelines: ['Use ext_tool for extension operations.'],
        ));

        $names = $registry->activeToolNames();
        $this->assertContains('ext_tool', $names);

        $lines = $registry->permanentToolLines();
        $this->assertContains('ext_tool: do extension stuff', $lines);

        $guidelines = $registry->permanentGuidelines();
        $this->assertContains('Use ext_tool for extension operations.', $guidelines);
    }

    public function testRegisterToolDerivesPromptLineFromNameAndDescription(): void
    {
        $registry = new ToolRegistry();
        $bridge = new ExtensionToolRegistryBridge($registry);

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'my_tool',
            description: 'My custom tool',
            parametersJsonSchema: [],
            handler: $this->dummyHandler(),
            // promptSummary intentionally omitted
        ));

        $lines = $registry->permanentToolLines();
        $this->assertContains('my_tool: My custom tool', $lines);
    }

    public function testRegisterToolWithoutGuidelines(): void
    {
        $registry = new ToolRegistry();
        $bridge = new ExtensionToolRegistryBridge($registry);

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'simple_tool',
            description: 'A simple tool',
            parametersJsonSchema: [],
            handler: $this->dummyHandler(),
        ));

        $this->assertContains('simple_tool', $registry->activeToolNames());
        $this->assertSame([], $registry->permanentGuidelines());
    }

    public function testMultipleRegistrationsOrderPreserved(): void
    {
        $registry = new ToolRegistry();
        $bridge = new ExtensionToolRegistryBridge($registry);

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'tool_a', description: 'First', parametersJsonSchema: [], handler: $this->dummyHandler(),
            promptSummary: 'tool_a: first',
        ));
        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'tool_b', description: 'Second', parametersJsonSchema: [], handler: $this->dummyHandler(),
            promptSummary: 'tool_b: second',
        ));
        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'tool_c', description: 'Third', parametersJsonSchema: [], handler: $this->dummyHandler(),
            promptSummary: 'tool_c: third',
        ));

        $this->assertSame(['tool_a', 'tool_b', 'tool_c'], $registry->activeToolNames());
        $this->assertSame(
            ['tool_a: first', 'tool_b: second', 'tool_c: third'],
            $registry->permanentToolLines(),
        );
    }

    // ── Duplicate handling via ToolRegistry ──

    public function testDuplicateIsIdempotent(): void
    {
        $registry = new ToolRegistry();
        $bridge = new ExtensionToolRegistryBridge($registry);

        $dto = new ToolRegistrationDTO(
            name: 'dup_tool', description: 'Duplicate', parametersJsonSchema: [], handler: $this->dummyHandler(),
            promptSummary: 'dup_tool: description',
        );

        $bridge->registerTool($dto);
        $bridge->registerTool($dto); // identical re-registration

        $this->assertCount(1, $registry->activeToolNames());
        $this->assertCount(1, $registry->permanentToolLines());
    }

    // ── Handler passthrough ──

    public function testHandlerPassthrough(): void
    {
        $registry = new ToolRegistry();
        $bridge = new ExtensionToolRegistryBridge($registry);

        $handler = $this->dummyHandler();

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'callable_tool', description: 'Callable handler', parametersJsonSchema: [], handler: $handler,
        ));

        // Handler is stored — verify through definition lookup
        $def = $registry->toolDefinition('callable_tool');
        $this->assertNotNull($def);
        $this->assertSame($handler, $def->handler);
    }

    // ── Handler validation ──

    public function testHandlerMustImplementToolHandlerInterface(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an instance of ToolHandlerInterface');

        $registry = new ToolRegistry();
        $bridge = new ExtensionToolRegistryBridge($registry);

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'bad_handler_tool', description: 'Bad handler', parametersJsonSchema: [], handler: new \stdClass(),
        ));
    }

    public function testNullHandlerThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an instance of ToolHandlerInterface');

        $registry = new ToolRegistry();
        $bridge = new ExtensionToolRegistryBridge($registry);

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'null_handler_tool', description: 'Null handler', parametersJsonSchema: [], handler: null,
        ));
    }

    // ── Guideline deduplication via ToolRegistry ──

    public function testGuidelineDeduplication(): void
    {
        $registry = new ToolRegistry();
        $bridge = new ExtensionToolRegistryBridge($registry);

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'tool_x', description: 'X', parametersJsonSchema: [], handler: $this->dummyHandler(),
            promptGuidelines: ['Guideline A', 'Guideline B'],
            promptSummary: 'tool_x: X',
        ));

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'tool_y', description: 'Y', parametersJsonSchema: [], handler: $this->dummyHandler(),
            promptGuidelines: ['Guideline B', 'Guideline C'],
            promptSummary: 'tool_y: Y',
        ));

        // Deduped, first occurrence position preserved
        $this->assertSame(
            ['Guideline A', 'Guideline B', 'Guideline C'],
            $registry->permanentGuidelines(),
        );
    }

    // ── Error propagation from ToolRegistry ──

    public function testEmptyNameThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tool name and description must be non-empty strings');

        $registry = new ToolRegistry();
        $bridge = new ExtensionToolRegistryBridge($registry);

        $bridge->registerTool(new ToolRegistrationDTO(
            name: '', description: 'Has name but empty', parametersJsonSchema: [], handler: $this->dummyHandler(),
        ));
    }

    public function testEmptyDescriptionThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tool name and description must be non-empty strings');

        $registry = new ToolRegistry();
        $bridge = new ExtensionToolRegistryBridge($registry);

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'some_tool', description: '', parametersJsonSchema: [], handler: $this->dummyHandler(),
        ));
    }

    // ── ToolRegistryInterface contract adherence ──

    public function testAcceptsAnyToolRegistryImplementation(): void
    {
        $mockHandler = $this->createMock(ToolHandlerInterface::class);

        $mock = $this->createMock(ToolRegistryInterface::class);
        $mock->expects($this->once())
            ->method('registerTool')
            ->with(
                'mocked_tool',
                'Mocked description',
                ['type' => 'object'],
                $mockHandler,
                'mocked_tool: Mocked description',
                ['Guideline'],
            );

        $bridge = new ExtensionToolRegistryBridge($mock);
        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'mocked_tool',
            description: 'Mocked description',
            parametersJsonSchema: ['type' => 'object'],
            handler: $mockHandler,
            // promptSummary omitted → derived from name + description
            promptGuidelines: ['Guideline'],
        ));
    }

    /* ───────── Private helpers ───────── */

    private function dummyHandler(): ToolHandlerInterface
    {
        return new class implements ToolHandlerInterface {
            public function __invoke(array $arguments = []): string
            {
                return 'extension handler result';
            }
        };
    }
}
