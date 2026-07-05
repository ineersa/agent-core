<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Definition;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDiagnosticDTO;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AgentDefinitionCatalog covering lookup, enabled/disabled
 * filtering, and require methods.
 *
 * Test thesis: The catalog protects the stable contract that all() lists
 * everything, enabled() excludes disabled definitions, require()/requireEnabled()
 * throw for missing/disabled agents.
 */
final class AgentDefinitionCatalogTest extends TestCase
{
    private AgentDefinitionDTO $scout;
    private AgentDefinitionDTO $reviewer;
    private AgentDefinitionDTO $workerDisabled;

    protected function setUp(): void
    {
        $this->scout = new AgentDefinitionDTO(
            name: 'scout',
            description: 'Scout agent',
            tools: ['read'],
            mcp: new McpPolicyDTO(McpAgentModeEnum::None, []),
        );

        $this->reviewer = new AgentDefinitionDTO(
            name: 'reviewer',
            description: 'Reviewer agent',
            tools: ['read', 'ide_find_references'],
            mcp: new McpPolicyDTO(McpAgentModeEnum::None, []),
        );

        $this->workerDisabled = new AgentDefinitionDTO(
            name: 'worker',
            description: 'Worker agent',
            tools: ['read', 'write', 'edit'],
            mcp: new McpPolicyDTO(McpAgentModeEnum::None, []),
            disabled: true,
        );
    }

    public function testAllIncludesDisabledDefinitions(): void
    {
        $catalog = new AgentDefinitionCatalog([
            $this->scout,
            $this->workerDisabled,
        ]);

        $all = $catalog->all();

        $this->assertCount(2, $all);
        $names = array_map(static fn (AgentDefinitionDTO $d): string => $d->name, $all);
        $this->assertContains('scout', $names);
        $this->assertContains('worker', $names);
    }

    public function testEnabledExcludesDisabled(): void
    {
        $catalog = new AgentDefinitionCatalog([
            $this->scout,
            $this->reviewer,
            $this->workerDisabled,
        ]);

        $enabled = $catalog->enabled();

        $this->assertCount(2, $enabled);
        $names = array_map(static fn (AgentDefinitionDTO $d): string => $d->name, $enabled);
        $this->assertContains('scout', $names);
        $this->assertContains('reviewer', $names);
        $this->assertNotContains('worker', $names);
    }

    public function testDisabledOnlyDisabledDefinitions(): void
    {
        $catalog = new AgentDefinitionCatalog([
            $this->scout,
            $this->workerDisabled,
        ]);

        $disabled = $catalog->disabled();

        $this->assertCount(1, $disabled);
        $this->assertSame('worker', $disabled[0]->name);
    }

    public function testDisabledReturnsEmptyWhenNoneDisabled(): void
    {
        $catalog = new AgentDefinitionCatalog([
            $this->scout,
            $this->reviewer,
        ]);

        $this->assertCount(0, $catalog->disabled());
    }

    public function testGetReturnsDefinitionByName(): void
    {
        $catalog = new AgentDefinitionCatalog([$this->scout, $this->reviewer]);

        $scout = $catalog->get('scout');
        $this->assertNotNull($scout);
        $this->assertSame('Scout agent', $scout->description);

        $reviewer = $catalog->get('reviewer');
        $this->assertNotNull($reviewer);
        $this->assertSame('Reviewer agent', $reviewer->description);
    }

    public function testGetReturnsNullForMissingName(): void
    {
        $catalog = new AgentDefinitionCatalog([$this->scout]);

        $this->assertNull($catalog->get('nonexistent'));
    }

    public function testGetReturnsDisabledDefinition(): void
    {
        $catalog = new AgentDefinitionCatalog([$this->workerDisabled]);

        $worker = $catalog->get('worker');
        $this->assertNotNull($worker);
        $this->assertTrue($worker->disabled);
    }

    public function testRequireReturnsDefinition(): void
    {
        $catalog = new AgentDefinitionCatalog([$this->scout]);

        $definition = $catalog->require('scout');

        $this->assertSame('scout', $definition->name);
    }

    public function testRequireThrowsForMissingDefinition(): void
    {
        $catalog = new AgentDefinitionCatalog([$this->scout]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Agent "nonexistent" is not defined.');

        $catalog->require('nonexistent');
    }

    public function testRequireEnabledReturnsEnabledDefinition(): void
    {
        $catalog = new AgentDefinitionCatalog([$this->scout]);

        $definition = $catalog->requireEnabled('scout');

        $this->assertSame('scout', $definition->name);
        $this->assertFalse($definition->disabled);
    }

    public function testRequireEnabledThrowsForDisabledDefinition(): void
    {
        $catalog = new AgentDefinitionCatalog([$this->workerDisabled]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Agent "worker" is disabled.');

        $catalog->requireEnabled('worker');
    }

    public function testRequireEnabledThrowsForMissingDefinition(): void
    {
        $catalog = new AgentDefinitionCatalog([$this->scout]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Agent "nonexistent" is not defined.');

        $catalog->requireEnabled('nonexistent');
    }

    public function testDiagnosticsStored(): void
    {
        $diagnostics = [
            new AgentDefinitionDiagnosticDTO(
                type: 'collision',
                message: 'Agent name collision: "scout" from "/path/b.md" overrides "/path/a.md".',
                path: '/path/b.md',
                name: 'scout',
                winnerPath: '/path/b.md',
                loserPath: '/path/a.md',
            ),
        ];

        $catalog = new AgentDefinitionCatalog([$this->scout], $diagnostics);

        $this->assertCount(1, $catalog->diagnostics());
        $this->assertSame('collision', $catalog->diagnostics()[0]->type);
    }

    public function testEmptyCatalog(): void
    {
        $catalog = new AgentDefinitionCatalog([]);

        $this->assertCount(0, $catalog->all());
        $this->assertCount(0, $catalog->enabled());
        $this->assertCount(0, $catalog->disabled());
        $this->assertCount(0, $catalog->diagnostics());
        $this->assertNull($catalog->get('anything'));
    }

    public function testLastDuplicateWinsByName(): void
    {
        $scout1 = new AgentDefinitionDTO(
            name: 'scout',
            description: 'Scout v1',
            tools: ['read'],
            mcp: new McpPolicyDTO(McpAgentModeEnum::None, []),
        );
        $scout2 = new AgentDefinitionDTO(
            name: 'scout',
            description: 'Scout v2',
            tools: ['read', 'semantic-search'],
            mcp: new McpPolicyDTO(McpAgentModeEnum::None, []),
        );

        $catalog = new AgentDefinitionCatalog([$scout1, $scout2]);

        $scout = $catalog->get('scout');
        $this->assertNotNull($scout);
        $this->assertSame('Scout v2', $scout->description);
        $this->assertCount(1, $catalog->all());
    }
}
