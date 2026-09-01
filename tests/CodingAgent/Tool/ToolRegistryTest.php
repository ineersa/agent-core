<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
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
        $this->assertSame(['G1'], $this->flattenGuidelines($registry->permanentGuidelinesByTool()));

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
            $this->flattenGuidelines($this->registry->permanentGuidelinesByTool()),
        );
        $this->assertSame(['read'], $this->registry->activeToolNames());
    }

    public function testRegisterMultiplePermanentToolsPreservesOrder(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'write', description: 'Write', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'write: Write', promptGuidelines: ['G2']);
        $this->registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'bash: Bash', promptGuidelines: ['G3']);

        $this->assertSame(['read: Read', 'write: Write', 'bash: Bash'], $this->registry->permanentToolLines());
        $this->assertSame(['G1', 'G2', 'G3'], $this->flattenGuidelines($this->registry->permanentGuidelinesByTool()));
        $this->assertSame(['read', 'write', 'bash'], $this->registry->activeToolNames());
    }

    public function testIdenticalReRegistrationIsIdempotent(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);

        // Lines should not duplicate
        $this->assertCount(1, $this->registry->permanentToolLines());
        $this->assertCount(1, $this->flattenGuidelines($this->registry->permanentGuidelinesByTool()));
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

        $this->assertSame(['shared guideline', 'unique g'], $this->flattenGuidelines($this->registry->permanentGuidelinesByTool()));
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
        $this->assertSame(['Guideline'], $this->flattenGuidelines($this->registry->permanentGuidelinesByTool()));
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

    public function testRegisterToolPreservesTimeoutSeconds(): void
    {
        $this->registry->registerTool(
            name: 'timed_tool',
            description: 'Timed tool',
            parametersJsonSchema: [],
            handler: $this->dummyHandler(),
            promptLine: 'timed_tool: Timed',
            timeoutSeconds: 42,
        );

        $def = $this->registry->toolDefinition('timed_tool');

        $this->assertNotNull($def);
        $this->assertSame(42, $def->timeoutSeconds);
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
        $this->assertSame([], $this->flattenGuidelines($this->registry->permanentGuidelinesByTool()));
        $this->assertSame([], $this->registry->activeToolNames());
    }

    public function testToolWithNoGuidelines(): void
    {
        $this->registry->registerTool(name: 'minimal', description: 'Min', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'minimal: Minimal');
        $this->assertSame([], $this->flattenGuidelines($this->registry->permanentGuidelinesByTool()));
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
        $this->assertSame(['G1', 'G2'], $this->flattenGuidelines($this->registry->permanentGuidelinesByTool()));
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
        $this->assertSame(['G1'], $this->flattenGuidelines($this->registry->permanentGuidelinesByTool()));
    }

    public function testSetExcludedToolNamesEmptyShowsAll(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->setExcludedToolNames(['read']);
        $this->assertSame([], $this->registry->activeToolNames());

        $this->registry->setExcludedToolNames([]);
        $this->assertSame(['read'], $this->registry->activeToolNames());
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
        $this->assertSame(['G1', 'G2'], $this->flattenGuidelines($this->registry->permanentGuidelinesByTool()));
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

    /* ───────── Permanent subset prompt snapshots (TOOLS-R00) ───────── */

    public function testPermanentToolLinesForNamesRespectsRegistrationOrderAndDedupes(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G-read']);
        $this->registry->registerTool(name: 'write', description: 'Write', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'write: Write', promptGuidelines: ['G-write']);
        $this->registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G-bash']);

        $this->assertSame(
            ['read: Read', 'write: Write'],
            $this->registry->permanentToolLinesForNames(['write', 'read', 'bash']),
        );
        $this->assertSame(
            ['G-read', 'G-write', 'G-bash'],
            $this->flattenGuidelines($this->registry->permanentGuidelinesByTool(['bash', 'read', 'write'])),
        );
    }

    public function testPermanentSubsetOmitsExcludedPermanentTools(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->registerTool(name: 'fork', description: 'Fork', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'fork: Fork', promptGuidelines: ['G-fork']);
        $this->registry->setExcludedToolNames(['fork']);

        $this->assertSame(['read: Read'], $this->registry->permanentToolLinesForNames(['read', 'fork']));
        $this->assertSame(['G1'], $this->flattenGuidelines($this->registry->permanentGuidelinesByTool(['read', 'fork'])));
    }

    public function testPermanentSubsetIgnoresDynamicAndUnknownNames(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G1']);
        $this->registry->addDynamicTool(
            name: 'mcp_tool',
            description: 'CHANGED_PROVIDER_DESCRIPTION_MUST_NOT_LEAK',
            parametersJsonSchema: [],
            handler: $this->dummyHandler(),
        );

        $this->assertSame(['read: Read'], $this->registry->permanentToolLinesForNames(['read', 'mcp_tool', 'unknown']));
        $this->assertSame(['G1'], $this->flattenGuidelines($this->registry->permanentGuidelinesByTool(['read', 'mcp_tool'])));
        $this->assertStringNotContainsString('CHANGED_PROVIDER_DESCRIPTION_MUST_NOT_LEAK', implode('
', $this->registry->permanentToolLinesForNames(['mcp_tool'])));
    }

    public function testPermanentSubsetUsesExplicitPromptLineNotProviderDescription(): void
    {
        $this->registry->registerTool(
            name: 'read',
            description: 'Provider description is not prompt metadata',
            parametersJsonSchema: [],
            handler: $this->dummyHandler(),
            promptLine: 'AUTHORITATIVE_PROMPT_LINE',
            promptGuidelines: [],
        );

        $lines = $this->registry->permanentToolLinesForNames(['read']);
        $this->assertSame(['AUTHORITATIVE_PROMPT_LINE'], $lines);
        $this->assertStringNotContainsString('Provider description', implode('
', $lines));
    }

    public function testPermanentGuidelinesByToolGroupsInRegistrationOrderAndOmitsEmpty(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G-read-a', 'G-read-a', 'G-read-b']);
        $this->registry->registerTool(name: 'write', description: 'Write', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'write: Write', promptGuidelines: []);
        $this->registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'bash: Bash', promptGuidelines: ['G-bash']);

        $this->assertSame(
            [
                'read' => ['G-read-a', 'G-read-b'],
                'bash' => ['G-bash'],
            ],
            $this->registry->permanentGuidelinesByTool(),
        );
        $this->assertSame(['G-read-a', 'G-read-b', 'G-bash'], $this->flattenGuidelines($this->registry->permanentGuidelinesByTool()));
    }

    public function testPermanentGuidelinesByToolSubsetAndVisibility(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read', promptGuidelines: ['G-read']);
        $this->registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'bash: Bash', promptGuidelines: ['G-bash']);
        $this->registry->registerTool(name: 'fork', description: 'Fork', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'fork: Fork', promptGuidelines: ['G-fork']);
        $this->registry->setExcludedToolNames(['fork']);
        $this->registry->addDynamicTool(name: 'mcp_tool', description: 'Dynamic', parametersJsonSchema: [], handler: $this->dummyHandler());

        $this->assertSame(
            [
                'read' => ['G-read'],
                'bash' => ['G-bash'],
            ],
            $this->registry->permanentGuidelinesByTool(['bash', 'read', 'fork', 'mcp_tool', 'unknown']),
        );
        $this->assertSame([], $this->registry->permanentGuidelinesByTool([]));
    }

    /* ───────── Private helpers ───────── */

    /* ───────── Definition identity (cache contract) ───────── */

    public function testIdenticalPermanentReRegistrationKeepsFirstDefinition(): void
    {
        $this->registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'read: Read');
        $first = $this->registry->toolDefinition('read');

        // Same name re-registration is a no-op (first wins) regardless of payload.
        $this->registry->registerTool(name: 'read', description: 'Different', parametersJsonSchema: [], handler: $this->dummyHandler(), promptLine: 'other');

        $this->assertSame($first, $this->registry->toolDefinition('read'));
    }

    public function testIdenticalDynamicReRegistrationKeepsDefinitionIdentity(): void
    {
        $handler = $this->dummyHandler();
        $schema = ['type' => 'object'];

        $this->registry->addDynamicTool(name: 'mcp_x', description: 'X', parametersJsonSchema: $schema, handler: $handler);
        $first = $this->registry->toolDefinition('mcp_x');

        // Identical re-add: same handler object, description, and schema.
        $this->registry->addDynamicTool(name: 'mcp_x', description: 'X', parametersJsonSchema: $schema, handler: $handler);

        $this->assertSame($first, $this->registry->toolDefinition('mcp_x'));
        $this->assertSame($handler, $first?->handler);
    }

    public function testDynamicReplaceCreatesNewDefinitionIdentity(): void
    {
        $this->registry->addDynamicTool(name: 'mcp_x', description: 'X', parametersJsonSchema: [], handler: $this->dummyHandler());
        $first = $this->registry->toolDefinition('mcp_x');

        $this->registry->addDynamicTool(name: 'mcp_x', description: 'X2', parametersJsonSchema: [], handler: $this->dummyHandler());

        $replacement = $this->registry->toolDefinition('mcp_x');
        $this->assertNotSame($first, $replacement);
        $this->assertSame('X2', $replacement?->description);
    }

    public function testRemoveAndReAddDynamicToolCreatesNewDefinitionIdentity(): void
    {
        $this->registry->addDynamicTool(name: 'mcp_x', description: 'X', parametersJsonSchema: [], handler: $this->dummyHandler());
        $first = $this->registry->toolDefinition('mcp_x');

        $this->registry->removeDynamicTool('mcp_x');
        $this->registry->addDynamicTool(name: 'mcp_x', description: 'X', parametersJsonSchema: [], handler: $this->dummyHandler());

        $this->assertNotSame($first, $this->registry->toolDefinition('mcp_x'));
    }

    /**
     * @param array<string, list<string>> $grouped
     *
     * @return list<string>
     */
    private function flattenGuidelines(array $grouped): array
    {
        $flat = [];
        $seen = [];
        foreach ($grouped as $guidelines) {
            foreach ($guidelines as $guideline) {
                if (isset($seen[$guideline])) {
                    continue;
                }
                $seen[$guideline] = true;
                $flat[] = $guideline;
            }
        }

        return $flat;
    }

    private function createProvider(
        string $name,
        string $description,
        object $handler,
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

    private function dummyHandler(): object
    {
        return new class {
            public function __invoke(array $arguments = []): string
            {
                return 'handler result';
            }
        };
    }
}
