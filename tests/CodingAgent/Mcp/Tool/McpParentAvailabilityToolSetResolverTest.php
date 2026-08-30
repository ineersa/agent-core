<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Tool;

use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogEntryDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Tool\McpParentAvailabilityToolSetResolver;
use Ineersa\CodingAgent\Mcp\Tool\McpServerToolAvailability;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
use Ineersa\CodingAgent\Tests\Support\StubRunRelationshipReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: parent/main active toolsets must exclude MCP tools from availability=specific servers
 * while child runs keep full registration and rely on allowed_tools intersection.
 */
#[CoversClass(McpParentAvailabilityToolSetResolver::class)]
final class McpParentAvailabilityToolSetResolverTest extends TestCase
{
    public function testParentRunHidesSpecificAvailabilityMcpTools(): void
    {
        $parentRunId = 'parent-run-1';
        $resolver = $this->createResolver($parentRunId, StubRunRelationshipReader::topLevel($parentRunId));

        $result = $resolver->resolve('toolset:run:'.$parentRunId, runId: $parentRunId);

        $this->assertSame(['read', 'context7_resolve'], $result->toolNames);
        $this->assertNotContains('websearch_search', $result->toolNames);
    }

    public function testChildRunPassesThroughBeforeSubagentIntersection(): void
    {
        $parentRunId = 'parent-run-2';
        $childRunId = 'child-run-2';
        $resolver = $this->createResolver($parentRunId, StubRunRelationshipReader::child($childRunId, $parentRunId));

        $result = $resolver->resolve('toolset:run:'.$childRunId, runId: $childRunId);

        $this->assertContains('websearch_search', $result->toolNames);
        $this->assertContains('context7_resolve', $result->toolNames);
    }

    public function testUnknownRunFailsClosed(): void
    {
        $resolver = $this->createResolver('parent-run-3', StubRunRelationshipReader::empty());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Operational relationship for run "unknown-run" is missing.');

        $resolver->resolve('toolset:run:unknown-run', runId: 'unknown-run');
    }

    private function createResolver(string $parentRunId, StubRunRelationshipReader $relationshipReader): McpParentAvailabilityToolSetResolver
    {
        $inner = new class implements ToolSetResolverInterface {
            public function resolve(string $toolsRef, ?int $turnNo = null, ?string $runId = null): ActiveToolSet
            {
                return new ActiveToolSet(
                    toolNames: ['read', 'context7_resolve', 'websearch_search'],
                    allowListNames: ['read', 'context7_resolve', 'websearch_search'],
                    executionModes: [],
                );
            }
        };

        $catalog = new McpToolCatalogDTO(
            runId: $parentRunId,
            generatedAt: 't',
            configHash: 'h',
            servers: [
                'context7' => new McpServerCatalogEntryDTO('context7', 'http', McpServerCatalogStatusEnum::CONNECTED, tools: [
                    new McpToolDefinitionDTO('context7_resolve', 'context7', 'resolve', 'd', ['type' => 'object']),
                ]),
                'websearch' => new McpServerCatalogEntryDTO('websearch', 'http', McpServerCatalogStatusEnum::CONNECTED, tools: [
                    new McpToolDefinitionDTO('websearch_search', 'websearch', 'search', 'd', ['type' => 'object']),
                ]),
            ],
        );

        $catalogStore = $this->createStub(McpToolCatalogStoreInterface::class);
        $catalogStore->method('read')->willReturnCallback(static function (string $runId) use ($parentRunId, $catalog): ?McpToolCatalogDTO {
            return $parentRunId === $runId ? $catalog : null;
        });

        return new McpParentAvailabilityToolSetResolver(
            inner: $inner,
            relationshipReader: $relationshipReader,
            catalogStore: $catalogStore,
            configLoader: TestMcpConfigLoaderFactory::smokeLoader(),
            availability: new McpServerToolAvailability(),
        );
    }
}
