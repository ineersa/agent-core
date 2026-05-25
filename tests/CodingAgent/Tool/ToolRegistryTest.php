<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry();
    }

    /* ───────── Permanent tool registration ───────── */

    public function testRegisterPermanentTool(): void
    {
        $this->registry->registerTool(
            name: 'read',
            description: 'Read file contents',
            parametersJsonSchema: ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
            handler: $this->dummyHandler(),
            promptLine: '- read: Read file contents',
            promptGuidelines: ['Use read for files', 'Output is truncated at 2000 lines'],
        );

        self::assertSame(['- read: Read file contents'], $this->registry->permanentToolLines());
        self::assertSame(
            ['Use read for files', 'Output is truncated at 2000 lines'],
            $this->registry->permanentGuidelines(),
        );
        self::assertSame(['read'], $this->registry->activeToolNames());
    }

    public function testRegisterMultiplePermanentToolsPreservesOrder(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'write', description: 'Write', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'write: Write', promptGuidelines: ['G2']);
        $this->registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'bash: Bash', promptGuidelines: ['G3']);

        self::assertSame(['read: Read', 'write: Write', 'bash: Bash'], $this->registry->permanentToolLines());
        self::assertSame(['G1', 'G2', 'G3'], $this->registry->permanentGuidelines());
        self::assertSame(['read', 'write', 'bash'], $this->registry->activeToolNames());
    }

    public function testIdenticalReRegistrationIsIdempotent(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);

        // Lines should not duplicate
        self::assertCount(1, $this->registry->permanentToolLines());
        self::assertCount(1, $this->registry->permanentGuidelines());
    }

    public function testRegisterPermanentToolWithEmptyNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->registerTool(name: '', description: 'desc', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'line');
    }

    public function testRegisterPermanentToolWithEmptyDescriptionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->registerTool(name: 'test', description: '', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'line');
    }

    /* ───────── Prompt deduplication ───────── */

    public function testDedupesDuplicatePromptLinesAcrossTools(): void
    {
        $this->registry->registerTool(name: 'a', description: 'A', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'same line', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'b', description: 'B', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'same line', promptGuidelines: ['G2']);

        self::assertSame(['same line'], $this->registry->permanentToolLines());
    }

    public function testDedupesDuplicateGuidelinesAcrossTools(): void
    {
        $this->registry->registerTool(name: 'a', description: 'A', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'L1', promptGuidelines: ['shared guideline']);
        $this->registry->registerTool(name: 'b', description: 'B', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'L2', promptGuidelines: ['shared guideline', 'unique g']);

        self::assertSame(['shared guideline', 'unique g'], $this->registry->permanentGuidelines());
    }

    /* ───────── Dynamic tools ───────── */

    public function testAddDynamicTool(): void
    {
        $this->registry->addDynamicTool(name: 'fg', description: 'Fg tool', parametersJsonSchema: [], handler: $this->dummyHandler());

        self::assertSame(['fg'], $this->registry->activeToolNames());
    }

    public function testRemoveDynamicTool(): void
    {
        $this->registry->addDynamicTool(name: 'fg', description: 'Fg', parametersJsonSchema: [], handler: $this->dummyHandler());
        $this->registry->addDynamicTool(name: 'bg', description: 'Bg', parametersJsonSchema: [], handler: $this->dummyHandler());

        $this->registry->removeDynamicTool('fg');

        self::assertSame(['bg'], $this->registry->activeToolNames());
    }

    public function testRemoveNonExistentDynamicToolIsNoOp(): void
    {
        $this->registry->removeDynamicTool('nonexistent');
        self::assertSame([], $this->registry->activeToolNames());
    }

    public function testSetDynamicToolsReplacesAll(): void
    {
        $this->registry->addDynamicTool(name: 'old', description: 'Old', parametersJsonSchema: [], handler: $this->dummyHandler());
        $this->registry->setDynamicTools([
            ['name' => 'new1', 'description' => 'New1', 'parametersJsonSchema' => [], 'handler' => $this->dummyHandler()],
            ['name' => 'new2', 'description' => 'New2', 'parametersJsonSchema' => [], 'handler' => $this->dummyHandler()],
        ]);

        self::assertSame(['new1', 'new2'], $this->registry->activeToolNames());
    }

    public function testGetDynamicToolsReturnsOrderedList(): void
    {
        $this->registry->addDynamicTool(name: 'a', description: 'A', parametersJsonSchema: ['type' => 'object'], handler: $this->dummyHandler());
        $this->registry->addDynamicTool(name: 'b', description: 'B', parametersJsonSchema: ['type' => 'array'], handler: $this->dummyHandler());

        $tools = $this->registry->getDynamicTools();
        self::assertCount(2, $tools);
        self::assertSame('a', $tools[0]['name']);
        self::assertSame('b', $tools[1]['name']);
        self::assertSame(['type' => 'object'], $tools[0]['parametersJsonSchema']);
    }

    public function testDynamicToolNameConflictWithPermanentThrows(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read', promptGuidelines: []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('permanent tool with the same name already exists');
        $this->registry->addDynamicTool(name: 'read', description: 'Dup', parametersJsonSchema: [], handler: $this->dummyHandler());
    }

    public function testDynamicToolWithEmptyNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->addDynamicTool(name: '', description: 'desc', parametersJsonSchema: [], handler: $this->dummyHandler());
    }

    /* ───────── Active tool names = permanent + dynamic ───────── */

    public function testActiveToolNamesCombinesPermanentAndDynamicInOrder(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read', promptGuidelines: []);
        $this->registry->registerTool(name: 'write', description: 'Write', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'write', promptGuidelines: []);
        $this->registry->addDynamicTool(name: 'bg', description: 'Bg', parametersJsonSchema: [], handler: $this->dummyHandler());

        self::assertSame(['read', 'write', 'bg'], $this->registry->activeToolNames());
    }

    public function testActiveToolNamesDoesNotIncludeRemovedDynamicTools(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read', promptGuidelines: []);
        $this->registry->addDynamicTool(name: 'bg', description: 'Bg', parametersJsonSchema: [], handler: $this->dummyHandler());
        $this->registry->removeDynamicTool('bg');

        self::assertSame(['read'], $this->registry->activeToolNames());
    }

    public function testPermanentToolLinesAndGuidelinesExcludeDynamicTools(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read line', promptGuidelines: ['Guideline']);
        $this->registry->addDynamicTool(name: 'bg', description: 'Bg', parametersJsonSchema: [], handler: $this->dummyHandler());

        self::assertSame(['read line'], $this->registry->permanentToolLines());
        self::assertSame(['Guideline'], $this->registry->permanentGuidelines());
    }

    /* ───────── Edge cases ───────── */

    public function testEmptyRegistryReturnsEmptyLists(): void
    {
        self::assertSame([], $this->registry->permanentToolLines());
        self::assertSame([], $this->registry->permanentGuidelines());
        self::assertSame([], $this->registry->activeToolNames());
        self::assertSame([], $this->registry->getDynamicTools());
    }

    public function testToolWithNoGuidelines(): void
    {
        $this->registry->registerTool(name: 'minimal', description: 'Min', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'minimal: Minimal');
        self::assertSame([], $this->registry->permanentGuidelines());
    }

    /* ───────── Private helpers ───────── */

    private function dummyHandler(): object
    {
        return new class {
            public function __invoke(): string
            {
                return 'handler result';
            }
        };
    }
}
