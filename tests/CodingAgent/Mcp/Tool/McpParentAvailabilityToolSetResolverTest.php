<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Tool;

use Ineersa\AgentCore\Contract\Tool\ActiveToolSet;
use Ineersa\AgentCore\Contract\Tool\ToolSetResolverInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogEntryDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Tool\McpParentAvailabilityToolSetResolver;
use Ineersa\CodingAgent\Mcp\Tool\McpServerToolAvailability;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
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
        $resolver = $this->createResolver($parentRunId, isChild: false);

        $result = $resolver->resolve('toolset:run:'.$parentRunId, runId: $parentRunId);

        $this->assertSame(['read', 'context7_resolve'], $result->toolNames);
        $this->assertNotContains('websearch_search', $result->toolNames);
    }

    public function testChildRunPassesThroughBeforeSubagentIntersection(): void
    {
        $parentRunId = 'parent-run-2';
        $childRunId = 'child-run-2';
        $resolver = $this->createResolver($parentRunId, isChild: true, childRunId: $childRunId);

        $result = $resolver->resolve('toolset:run:'.$childRunId, runId: $childRunId);

        $this->assertContains('websearch_search', $result->toolNames);
        $this->assertContains('context7_resolve', $result->toolNames);
    }

    private function createResolver(string $parentRunId, bool $isChild, ?string $childRunId = null): McpParentAvailabilityToolSetResolver
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

        $eventStore = $this->createStub(\Ineersa\AgentCore\Contract\EventStoreInterface::class);
        if ($isChild && null !== $childRunId) {
            $event = new RunEvent(
                runId: $childRunId,
                seq: 1,
                turnNo: 0,
                type: RunEventTypeEnum::RunStarted->value,
                payload: [
                    'payload' => [
                        'metadata' => [
                            'session' => [
                                'kind' => 'agent_child',
                                'parent_run_id' => $parentRunId,
                            ],
                            'tools_scope' => [
                                'allowed_tools' => ['websearch_search'],
                            ],
                        ],
                    ],
                ],
            );
            $eventStore->method('allFor')->willReturnCallback(static function (string $runId) use ($childRunId, $event): array {
                return $childRunId === $runId ? [$event] : [];
            });
        } else {
            $eventStore->method('allFor')->willReturn([]);
        }

        $metadataReader = new SubagentRunMetadataReader($eventStore);

        return new McpParentAvailabilityToolSetResolver(
            inner: $inner,
            metadataReader: $metadataReader,
            catalogStore: $catalogStore,
            configLoader: TestMcpConfigLoaderFactory::smokeLoader(),
            availability: new McpServerToolAvailability(),
        );
    }
}
