<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Definition;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionCatalog;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDiagnosticDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Definition\SystemPromptModeEnum;
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

        self::assertCount(2, $all);
        $names = array_map(static fn (AgentDefinitionDTO $d): string => $d->name, $all);
        self::assertContains('scout', $names);
        self::assertContains('worker', $names);
    }

    public function testEnabledExcludesDisabled(): void
    {
        $catalog = new AgentDefinitionCatalog([
            $this->scout,
            $this->reviewer,
            $this->workerDisabled,
        ]);

        $enabled = $catalog->enabled();

        self::assertCount(2, $enabled);
        $names = array_map(static fn (AgentDefinitionDTO $d): string => $d->name, $enabled);
        self::assertContains('scout', $names);
        self::assertContains('reviewer', $names);
        self::assertNotContains('worker', $names);
    }

    public function testDisabledOnlyDisabledDefinitions(): void
    {
        $catalog = new AgentDefinitionCatalog([
            $this->scout,
            $this->workerDisabled,
        ]);

        $disabled = $catalog->disabled();

        self::assertCount(1, $disabled);
        self::assertSame('worker', $disabled[0]->name);
    }

    public function testDisabledReturnsEmptyWhenNoneDisabled(): void
    {
        $catalog = new AgentDefinitionCatalog([
            $this->scout,
            $this->reviewer,
        ]);

        self::assertCount(0, $catalog->disabled());
    }

    public function testGetReturnsDefinitionByName(): void
    {
        $catalog = new AgentDefinitionCatalog([$this->scout, $this->reviewer]);

        $scout = $catalog->get('scout');
        self::assertNotNull($scout);
        self::assertSame('Scout agent', $scout->description);

        $reviewer = $catalog->get('reviewer');
        self::assertNotNull($reviewer);
        self::assertSame('Reviewer agent', $reviewer->description);
    }

    public function testGetReturnsNullForMissingName(): void
    {
        $catalog = new AgentDefinitionCatalog([$this->scout]);

        self::assertNull($catalog->get('nonexistent'));
    }

    public function testGetReturnsDisabledDefinition(): void
    {
        $catalog = new AgentDefinitionCatalog([$this->workerDisabled]);

        $worker = $catalog->get('worker');
        self::assertNotNull($worker);
        self::assertTrue($worker->disabled);
    }

    public function testRequireReturnsDefinition(): void
    {
        $catalog = new AgentDefinitionCatalog([$this->scout]);

        $definition = $catalog->require('scout');

        self::assertSame('scout', $definition->name);
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

        self::assertSame('scout', $definition->name);
        self::assertFalse($definition->disabled);
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

        self::assertCount(1, $catalog->diagnostics());
        self::assertSame('collision', $catalog->diagnostics()[0]->type);
    }

    public function testEmptyCatalog(): void
    {
        $catalog = new AgentDefinitionCatalog([]);

        self::assertCount(0, $catalog->all());
        self::assertCount(0, $catalog->enabled());
        self::assertCount(0, $catalog->disabled());
        self::assertCount(0, $catalog->diagnostics());
        self::assertNull($catalog->get('anything'));
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
        self::assertNotNull($scout);
        self::assertSame('Scout v2', $scout->description);
        self::assertCount(1, $catalog->all());
    }
}
