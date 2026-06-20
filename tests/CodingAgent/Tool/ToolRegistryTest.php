<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    private ToolRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry();
    }

    /* ───────── Provider-seeded permanent tools ───────── */

    public function testConstructorRegistersEmptyProviders(): void
    {
        $registry = new ToolRegistry([]);

        $this->assertSame([], $registry->activeToolNames());
    }

    public function testConstructorRegistersProviderDefinitionsAsPermanentTools(): void
    {
        $handler = $this->dummyHandler();
        $registry = new ToolRegistry([
            $this->createProvider('read', 'Read tool', $handler, 'read: Read', ['G1']),
        ]);

        $this->assertSame(['read'], $registry->activeToolNames());
        $this->assertSame(['read: Read'], $registry->permanentToolLines());
        $this->assertSame(['G1'], $registry->permanentGuidelines());

        $definition = $registry->toolDefinition('read');
        $this->assertNotNull($definition);
        $this->assertSame($handler, $definition->handler);
        $this->assertSame('Read tool', $definition->description);
    }

    public function testConstructorRegistersMultipleProvidersInOrder(): void
    {
        $registry = new ToolRegistry([
            $this->createProvider('a', 'A', $this->dummyHandler(), 'a: A'),
            $this->createProvider('b', 'B', $this->dummyHandler(), 'b: B'),
            $this->createProvider('c', 'C', $this->dummyHandler(), 'c: C'),
        ]);

        $this->assertSame(['a', 'b', 'c'], $registry->activeToolNames());
        $this->assertSame(['a: A', 'b: B', 'c: C'], $registry->permanentToolLines());
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

        $this->assertSame(['- read: Read file contents'], $this->registry->permanentToolLines());
        $this->assertSame(
            ['Use read for files', 'Output is truncated at 2000 lines'],
            $this->registry->permanentGuidelines(),
        );
        $this->assertSame(['read'], $this->registry->activeToolNames());
    }

    public function testRegisterMultiplePermanentToolsPreservesOrder(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'write', description: 'Write', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'write: Write', promptGuidelines: ['G2']);
        $this->registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'bash: Bash', promptGuidelines: ['G3']);

        $this->assertSame(['read: Read', 'write: Write', 'bash: Bash'], $this->registry->permanentToolLines());
        $this->assertSame(['G1', 'G2', 'G3'], $this->registry->permanentGuidelines());
        $this->assertSame(['read', 'write', 'bash'], $this->registry->activeToolNames());
    }

    public function testIdenticalReRegistrationIsIdempotent(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);

        // Lines should not duplicate
        $this->assertCount(1, $this->registry->permanentToolLines());
        $this->assertCount(1, $this->registry->permanentGuidelines());
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

        $this->assertSame(['same line'], $this->registry->permanentToolLines());
    }

    public function testDedupesDuplicateGuidelinesAcrossTools(): void
    {
        $this->registry->registerTool(name: 'a', description: 'A', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'L1', promptGuidelines: ['shared guideline']);
        $this->registry->registerTool(name: 'b', description: 'B', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'L2', promptGuidelines: ['shared guideline', 'unique g']);

        $this->assertSame(['shared guideline', 'unique g'], $this->registry->permanentGuidelines());
    }

    /* ───────── Dynamic tools ───────── */

    public function testAddDynamicTool(): void
    {
        $this->registry->addDynamicTool(name: 'fg', description: 'Fg tool', parametersJsonSchema: [], handler: $this->dummyHandler());

        $this->assertSame(['fg'], $this->registry->activeToolNames());
    }

    public function testRemoveDynamicTool(): void
    {
        $this->registry->addDynamicTool(name: 'fg', description: 'Fg', parametersJsonSchema: [], handler: $this->dummyHandler());
        $this->registry->addDynamicTool(name: 'bg', description: 'Bg', parametersJsonSchema: [], handler: $this->dummyHandler());

        $this->registry->removeDynamicTool('fg');

        $this->assertSame(['bg'], $this->registry->activeToolNames());
    }

    public function testRemoveNonExistentDynamicToolIsNoOp(): void
    {
        $this->registry->removeDynamicTool('nonexistent');
        $this->assertSame([], $this->registry->activeToolNames());
    }

    public function testSetDynamicToolsReplacesAll(): void
    {
        $this->registry->addDynamicTool(name: 'old', description: 'Old', parametersJsonSchema: [], handler: $this->dummyHandler());
        $this->registry->setDynamicTools([
            ['name' => 'new1', 'description' => 'New1', 'parametersJsonSchema' => [], 'handler' => $this->dummyHandler()],
            ['name' => 'new2', 'description' => 'New2', 'parametersJsonSchema' => [], 'handler' => $this->dummyHandler()],
        ]);

        $this->assertSame(['new1', 'new2'], $this->registry->activeToolNames());
    }

    public function testGetDynamicToolsReturnsOrderedList(): void
    {
        $this->registry->addDynamicTool(name: 'a', description: 'A', parametersJsonSchema: ['type' => 'object'], handler: $this->dummyHandler());
        $this->registry->addDynamicTool(name: 'b', description: 'B', parametersJsonSchema: ['type' => 'array'], handler: $this->dummyHandler());

        $tools = $this->registry->getDynamicTools();
        $this->assertCount(2, $tools);
        $this->assertSame('a', $tools[0]['name']);
        $this->assertSame('b', $tools[1]['name']);
        $this->assertSame(['type' => 'object'], $tools[0]['parametersJsonSchema']);
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

        $this->assertSame(['read', 'write', 'bg'], $this->registry->activeToolNames());
    }

    public function testActiveToolNamesDoesNotIncludeRemovedDynamicTools(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read', promptGuidelines: []);
        $this->registry->addDynamicTool(name: 'bg', description: 'Bg', parametersJsonSchema: [], handler: $this->dummyHandler());
        $this->registry->removeDynamicTool('bg');

        $this->assertSame(['read'], $this->registry->activeToolNames());
    }

    public function testPermanentToolLinesAndGuidelinesExcludeDynamicTools(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read line', promptGuidelines: ['Guideline']);
        $this->registry->addDynamicTool(name: 'bg', description: 'Bg', parametersJsonSchema: [], handler: $this->dummyHandler());

        $this->assertSame(['read line'], $this->registry->permanentToolLines());
        $this->assertSame(['Guideline'], $this->registry->permanentGuidelines());
    }

    /* ───────── ToolDefinitionDTO lookup methods ───────── */

    public function testActiveToolDefinitionsReturnsOrderedList(): void
    {
        $h1 = $this->dummyHandler();
        $h2 = $this->dummyHandler();
        $this->registry->registerTool(name: 'read', description: 'Read files', parametersJsonSchema: [], handler: $h1, promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'write', description: 'Write files', parametersJsonSchema: [], handler: $h2, promptLine: 'write: Write', promptGuidelines: ['G2']);

        $defs = $this->registry->activeToolDefinitions();

        $this->assertCount(2, $defs);
        $this->assertSame('read', $defs[0]->name);
        $this->assertSame('Read files', $defs[0]->description);
        $this->assertSame($h1, $defs[0]->handler);
        $this->assertSame('write', $defs[1]->name);
        $this->assertSame($h2, $defs[1]->handler);
        $this->assertSame('write: Write', $defs[1]->promptLine);
        $this->assertSame(['G2'], $defs[1]->promptGuidelines);
    }

    public function testActiveToolDefinitionsIncludesDynamicAfterPermanent(): void
    {
        $this->registry->registerTool(name: 'perm', description: 'Perm', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'perm: Perm');
        $this->registry->addDynamicTool(name: 'dyn', description: 'Dyn', parametersJsonSchema: [], handler: $this->dummyHandler());

        $defs = $this->registry->activeToolDefinitions();

        $this->assertCount(2, $defs);
        $this->assertSame('perm', $defs[0]->name);
        $this->assertSame('dyn', $defs[1]->name);
    }

    public function testActiveToolDefinitionsReturnsEmptyForEmptyRegistry(): void
    {
        $this->assertSame([], $this->registry->activeToolDefinitions());
    }

    public function testToolDefinitionReturnsDtoForPermanentTool(): void
    {
        $handler = $this->dummyHandler();
        $this->registry->registerTool(name: 'my_tool', description: 'My tool', parametersJsonSchema: ['type' => 'object'], handler: $handler, promptLine: 'my_tool: My tool', promptGuidelines: ['G1']);

        $def = $this->registry->toolDefinition('my_tool');

        $this->assertNotNull($def);
        $this->assertSame('my_tool', $def->name);
        $this->assertSame('My tool', $def->description);
        $this->assertSame($handler, $def->handler);
        $this->assertSame(['type' => 'object'], $def->parametersJsonSchema);
    }

    public function testToolDefinitionReturnsDtoForDynamicTool(): void
    {
        $handler = $this->dummyHandler();
        $this->registry->addDynamicTool(name: 'dyn_tool', description: 'Dynamic tool', parametersJsonSchema: ['type' => 'array'], handler: $handler);

        $def = $this->registry->toolDefinition('dyn_tool');

        $this->assertNotNull($def);
        $this->assertSame('dyn_tool', $def->name);
        $this->assertSame('Dynamic tool', $def->description);
        $this->assertSame($handler, $def->handler);
        $this->assertSame(['type' => 'array'], $def->parametersJsonSchema);
    }

    public function testToolDefinitionReturnsNullForUnknownTool(): void
    {
        $this->assertNull($this->registry->toolDefinition('nonexistent'));
    }

    public function testToolDefinitionReturnsPermanentBeforeDynamicOnNameCollision(): void
    {
        // This test validates that permanent takes priority; the collision
        // is prevented by addDynamicTool throwing, so we verify the permanent
        // logic directly.
        $handler = $this->dummyHandler();
        $this->registry->registerTool(name: 'shared', description: 'Permanent', parametersJsonSchema: [], handler: $handler, promptLine: 'shared: Permanent');

        $def = $this->registry->toolDefinition('shared');

        $this->assertNotNull($def);
        $this->assertSame('Permanent', $def->description);
    }

    /* ───────── Edge cases ───────── */

    public function testEmptyRegistryReturnsEmptyLists(): void
    {
        $this->assertSame([], $this->registry->permanentToolLines());
        $this->assertSame([], $this->registry->permanentGuidelines());
        $this->assertSame([], $this->registry->activeToolNames());
        $this->assertSame([], $this->registry->getDynamicTools());
    }

    public function testToolWithNoGuidelines(): void
    {
        $this->registry->registerTool(name: 'minimal', description: 'Min', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'minimal: Minimal');
        $this->assertSame([], $this->registry->permanentGuidelines());
    }

    /* ───────── Tool filtering (allowlist / denylist) ───────── */

    public function testSetAllowedToolNamesRestrictsVisibleTools(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'write', description: 'Write', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'write: Write', promptGuidelines: ['G2']);
        $this->registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'bash: Bash', promptGuidelines: ['G3']);

        $this->registry->setAllowedToolNames(['read', 'write']);

        $this->assertSame(['read', 'write'], $this->registry->activeToolNames());
        $this->assertSame(['read: Read', 'write: Write'], $this->registry->permanentToolLines());
        $this->assertSame(['G1', 'G2'], $this->registry->permanentGuidelines());
    }

    public function testSetAllowedToolNamesEmptyMakesAllToolsVisible(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->setAllowedToolNames(['read']);
        $this->assertSame(['read'], $this->registry->activeToolNames());

        $this->registry->setAllowedToolNames([]);
        $this->assertSame(['read'], $this->registry->activeToolNames());
    }

    public function testSetExcludedToolNamesHidesSpecificTools(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'bash: Bash', promptGuidelines: ['G3']);

        $this->registry->setExcludedToolNames(['bash']);

        $this->assertSame(['read'], $this->registry->activeToolNames());
        $this->assertSame(['read: Read'], $this->registry->permanentToolLines());
        $this->assertSame(['G1'], $this->registry->permanentGuidelines());
    }

    public function testSetExcludedToolNamesEmptyShowsAll(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->setExcludedToolNames(['read']);
        $this->assertSame([], $this->registry->activeToolNames());

        $this->registry->setExcludedToolNames([]);
        $this->assertSame(['read'], $this->registry->activeToolNames());
    }

    public function testExcludedToolNamesReturnsCurrentList(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'bash: Bash', promptGuidelines: ['G3']);

        $this->registry->setExcludedToolNames(['bash', 'read']);

        $excluded = $this->registry->excludedToolNames();
        $this->assertCount(2, $excluded);
        $this->assertContains('bash', $excluded);
        $this->assertContains('read', $excluded);
    }

    public function testCombinedAllowlistAndDenylist(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'write', description: 'Write', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'write: Write', promptGuidelines: ['G2']);
        $this->registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'bash: Bash', promptGuidelines: ['G3']);
        $this->registry->registerTool(name: 'edit', description: 'Edit', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'edit: Edit', promptGuidelines: ['G4']);

        $this->registry->setAllowedToolNames(['read', 'write', 'edit', 'bash']);
        $this->registry->setExcludedToolNames(['bash', 'edit']);

        $this->assertSame(['read', 'write'], $this->registry->activeToolNames());
        $this->assertSame(['read: Read', 'write: Write'], $this->registry->permanentToolLines());
        $this->assertSame(['G1', 'G2'], $this->registry->permanentGuidelines());
    }

    public function testToolDefinitionReturnsNullForExcludedTool(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'bash: Bash', promptGuidelines: ['G3']);

        // Before exclusion, toolDefinition works
        $this->assertNotNull($this->registry->toolDefinition('bash'));

        $this->registry->setExcludedToolNames(['bash']);

        // After exclusion, toolDefinition returns null for the excluded tool
        $this->assertNull($this->registry->toolDefinition('bash'));

        // Non-excluded tools still work
        $this->assertNotNull($this->registry->toolDefinition('read'));
    }

    public function testToolDefinitionReturnsNullForAllowlistFilteredTool(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'bash: Bash', promptGuidelines: ['G3']);

        // Before allowlist, both are visible
        $this->assertNotNull($this->registry->toolDefinition('bash'));
        $this->assertNotNull($this->registry->toolDefinition('read'));

        $this->registry->setAllowedToolNames(['read']);

        // 'bash' is registered but not in allowlist — must return null
        $this->assertNull($this->registry->toolDefinition('bash'));

        // 'read' is in allowlist — still works
        $this->assertNotNull($this->registry->toolDefinition('read'));
    }

    public function testSetAllowedToolNamesWithUnknownToolThrows(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tool name in allowlist: "unknown_tool"');
        $this->registry->setAllowedToolNames(['read', 'unknown_tool']);
    }

    public function testSetExcludedToolNamesWithUnknownToolThrows(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown tool name in exclusions: "nonexistent"');
        $this->registry->setExcludedToolNames(['nonexistent']);
    }

    public function testExcludedDynamicToolsAreFiltered(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->addDynamicTool(name: 'dyn_tool', description: 'Dyn', parametersJsonSchema: [], handler: $this->dummyHandler());

        $this->registry->setExcludedToolNames(['dyn_tool']);

        $this->assertSame(['read'], $this->registry->activeToolNames());
        $defs = $this->registry->activeToolDefinitions();
        $this->assertCount(1, $defs);
        $this->assertSame('read', $defs[0]->name);
    }

    public function testSetAllowedToolNamesTrimsEmptyStrings(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'bash: Bash', promptGuidelines: ['G3']);

        $this->registry->setAllowedToolNames(['', 'read', '  ']);

        $this->assertSame(['read'], $this->registry->activeToolNames());
    }

    public function testSetExcludedToolNamesTrimsEmptyStrings(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'bash: Bash', promptGuidelines: ['G3']);

        $this->registry->setExcludedToolNames(['', 'bash', '  ']);

        $this->assertSame(['read'], $this->registry->activeToolNames());
    }

    /* ───────── Execution mode ───────── */

    public function testRegisterToolDefaultsToSequentialExecutionMode(): void
    {
        $this->registry->registerTool(name: 'default_tool', description: 'Default', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'default_tool: Default');

        $def = $this->registry->toolDefinition('default_tool');

        $this->assertNotNull($def);
        $this->assertSame(ToolExecutionMode::Sequential, $def->executionMode);
    }

    public function testRegisterToolPreservesExplicitExecutionMode(): void
    {
        $this->registry->registerTool(name: 'explicit_tool', description: 'Explicit', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'explicit_tool: Explicit', promptGuidelines: [], executionMode: ToolExecutionMode::Parallel);

        $def = $this->registry->toolDefinition('explicit_tool');

        $this->assertNotNull($def);
        $this->assertSame(ToolExecutionMode::Parallel, $def->executionMode);
    }

    public function testDynamicToolDefaultsToSequentialExecutionMode(): void
    {
        $this->registry->addDynamicTool(name: 'dyn_tool', description: 'Dynamic', parametersJsonSchema: [], handler: $this->dummyHandler());

        $def = $this->registry->toolDefinition('dyn_tool');

        $this->assertNotNull($def);
        $this->assertSame(ToolExecutionMode::Sequential, $def->executionMode);
    }

    public function testDynamicToolPreservesExplicitExecutionMode(): void
    {
        $this->registry->addDynamicTool(name: 'parallel_dyn', description: 'Parallel dyn', parametersJsonSchema: [], handler: $this->dummyHandler(), executionMode: ToolExecutionMode::Parallel);

        $def = $this->registry->toolDefinition('parallel_dyn');

        $this->assertNotNull($def);
        $this->assertSame(ToolExecutionMode::Parallel, $def->executionMode);
    }

    public function testProviderRegistrationPreservesExecutionMode(): void
    {
        $definition = new ToolDefinitionDTO(
            name: 'custom',
            description: 'Custom mode tool',
            parametersJsonSchema: [],
            handler: $this->dummyHandler(),
            promptLine: 'custom: Custom',
            promptGuidelines: [],
            executionMode: ToolExecutionMode::Parallel,
        );

        $provider = new class($definition) implements HatfieldToolProviderInterface {
            public function __construct(
                private readonly ToolDefinitionDTO $definition,
            ) {
            }

            public function definition(): ToolDefinitionDTO
            {
                return $this->definition;
            }
        };

        $registry = new ToolRegistry([$provider]);
        $def = $registry->toolDefinition('custom');

        $this->assertNotNull($def);
        $this->assertSame(ToolExecutionMode::Parallel, $def->executionMode);
    }

    /* ───────── Private helpers ───────── */

    private function createProvider(
        string $name,
        string $description,
        ToolHandlerInterface $handler,
        string $promptLine,
        array $promptGuidelines = [],
    ): HatfieldToolProviderInterface {
        $definition = new ToolDefinitionDTO(
            name: $name,
            description: $description,
            parametersJsonSchema: [],
            handler: $handler,
            promptLine: $promptLine,
            promptGuidelines: $promptGuidelines,
        );

        return new class($definition) implements HatfieldToolProviderInterface {
            public function __construct(
                private readonly ToolDefinitionDTO $definition,
            ) {
            }

            public function definition(): ToolDefinitionDTO
            {
                return $this->definition;
            }
        };
    }

    private function dummyHandler(): ToolHandlerInterface
    {
        return new class implements ToolHandlerInterface {
            public function __invoke(array $arguments = []): string
            {
                return 'handler result';
            }
        };
    }
}
