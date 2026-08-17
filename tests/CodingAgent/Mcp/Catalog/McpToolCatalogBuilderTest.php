<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Catalog;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogBuilder;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Config\McpConfigDTO;
use Ineersa\CodingAgent\Mcp\Config\McpServerDefinitionDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests the catalog-construction policy extracted from
 * McpInitializeSessionHandler: ordering, exclude filters, cross-server
 * name collisions, sanitization, failed-server entries, field fallbacks,
 * and config-hash determinism.
 *
 * Test thesis: the builder reproduces the handler's exact catalog shape
 * and collision/order semantics without lifecycle behavior.
 */
final class McpToolCatalogBuilderTest extends TestCase
{
    private TestLogger $logger;
    private McpToolCatalogBuilder $builder;

    protected function setUp(): void
    {
        $this->logger = new TestLogger();
        $this->builder = new McpToolCatalogBuilder($this->logger);
    }

    public function testBuildPreservesServerAndToolOrdering(): void
    {
        $config = new McpConfigDTO([
            'first' => new McpServerDefinitionDTO(name: 'first'),
            'second' => new McpServerDefinitionDTO(name: 'second'),
        ]);

        $catalog = $this->builder->build($config, 'run-1', 'hash', [
            'first' => [
                'status' => 'connected',
                'transport' => 'stdio',
                'tools' => [
                    ['name' => 'a', 'description' => 'A', 'inputSchema' => []],
                    ['name' => 'b', 'description' => 'B', 'inputSchema' => []],
                ],
            ],
            'second' => [
                'status' => 'connected',
                'transport' => 'http',
                'tools' => [
                    ['name' => 'c', 'description' => 'C', 'inputSchema' => []],
                ],
            ],
        ]);

        $this->assertSame(['first', 'second'], array_keys($catalog->servers));
        $this->assertSame(['first_a', 'first_b'], array_map(
            static fn (McpToolDefinitionDTO $t): string => $t->hatfieldName,
            $catalog->servers['first']->tools,
        ));
        $this->assertSame(['second_c'], array_map(
            static fn (McpToolDefinitionDTO $t): string => $t->hatfieldName,
            $catalog->servers['second']->tools,
        ));
    }

    public function testBuildAppliesExcludeFilter(): void
    {
        $config = new McpConfigDTO([
            'server' => new McpServerDefinitionDTO(name: 'server', excludeTools: ['secret']),
        ]);

        $catalog = $this->builder->build($config, 'run-1', 'hash', [
            'server' => [
                'status' => 'connected',
                'transport' => 'stdio',
                'tools' => [
                    ['name' => 'keep', 'description' => '', 'inputSchema' => []],
                    ['name' => 'secret', 'description' => '', 'inputSchema' => []],
                ],
            ],
        ]);

        $this->assertSame(['server_keep'], array_map(
            static fn (McpToolDefinitionDTO $t): string => $t->hatfieldName,
            $catalog->servers['server']->tools,
        ));

        $excluded = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'debug' === $r['level']
                && ($r['context']['mcp_event'] ?? '') === 'tool.excluded',
        ));
        $this->assertCount(1, $excluded);
        $this->assertSame('secret', $excluded[0]['context']['mcp_tool_name']);
    }

    public function testBuildSkipsCrossServerNameCollisions(): void
    {
        $config = new McpConfigDTO([
            'a.b' => new McpServerDefinitionDTO(name: 'a.b'),
            'a_b' => new McpServerDefinitionDTO(name: 'a_b'),
        ]);

        $catalog = $this->builder->build($config, 'run-1', 'hash', [
            // Both servers sanitize to "a_b", so both tools map to "a_b_tool".
            'a.b' => [
                'status' => 'connected',
                'transport' => 'stdio',
                'tools' => [
                    ['name' => 'tool', 'description' => 'From a.b', 'inputSchema' => []],
                ],
            ],
            'a_b' => [
                'status' => 'connected',
                'transport' => 'stdio',
                'tools' => [
                    ['name' => 'tool', 'description' => 'From a_b', 'inputSchema' => []],
                ],
            ],
        ]);

        $this->assertCount(2, $catalog->servers);
        $this->assertCount(1, $catalog->servers['a.b']->tools);
        $this->assertCount(0, $catalog->servers['a_b']->tools, 'Duplicate mapped name must be skipped');

        $warnings = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'warning' === $r['level']
                && ($r['context']['mcp_event'] ?? '') === 'tool.duplicate',
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame('a_b_tool', $warnings[0]['context']['hatfield_name']);
    }

    public function testBuildSanitizesHatfieldNames(): void
    {
        $config = new McpConfigDTO([
            'my server!' => new McpServerDefinitionDTO(name: 'my server!'),
        ]);

        $catalog = $this->builder->build($config, 'run-1', 'hash', [
            'my server!' => [
                'status' => 'connected',
                'transport' => 'stdio',
                'tools' => [
                    ['name' => 'get$data', 'description' => '', 'inputSchema' => []],
                    ['name' => '___', 'description' => '', 'inputSchema' => []],
                ],
            ],
        ]);

        $names = array_map(static fn (McpToolDefinitionDTO $t): string => $t->hatfieldName, $catalog->servers['my server!']->tools);
        $this->assertSame(['my_server_get_data', 'my_server_unknown'], $names);
    }

    public function testBuildHandlesFailedServers(): void
    {
        $config = new McpConfigDTO([]);

        $catalog = $this->builder->build($config, 'run-1', 'hash', [
            'broken' => [
                'status' => 'failed',
                'transport' => 'http',
                'tools' => [],
                'errorMessage' => 'Connection refused',
            ],
        ]);

        $entry = $catalog->servers['broken'];
        $this->assertSame(McpServerCatalogStatusEnum::FAILED, $entry->status);
        $this->assertSame('Connection refused', $entry->errorMessage);
        $this->assertSame([], $entry->tools);
    }

    public function testBuildFallsBackForMissingToolFields(): void
    {
        $config = new McpConfigDTO([
            'server' => new McpServerDefinitionDTO(name: 'server'),
        ]);

        $catalog = $this->builder->build($config, 'run-1', 'hash', [
            'server' => [
                'status' => 'connected',
                'transport' => 'stdio',
                'tools' => [
                    ['name' => 'bare'],
                    ['name' => ''],
                ],
            ],
        ]);

        $tools = $catalog->servers['server']->tools;
        $this->assertCount(1, $tools, 'Tool without a name must be skipped');
        $this->assertSame('', $tools[0]->description);
        $this->assertSame([], $tools[0]->inputSchema);
    }

    public function testComputeConfigHashIsDeterministic(): void
    {
        $config = new McpConfigDTO([
            'server' => new McpServerDefinitionDTO(
                name: 'server',
                command: 'mcp-server',
                args: ['--port', '9000'],
                env: ['TOKEN' => 'secret-value'],
                excludeTools: ['a'],
            ),
        ]);

        $this->assertSame(
            $this->builder->computeConfigHash($config),
            $this->builder->computeConfigHash($config),
        );
    }

    public function testComputeConfigHashChangesWhenConfigChanges(): void
    {
        $base = [
            'server' => new McpServerDefinitionDTO(name: 'server', command: 'mcp-server'),
        ];

        $hash = $this->builder->computeConfigHash(new McpConfigDTO($base));
        $changedExcludes = $this->builder->computeConfigHash(new McpConfigDTO([
            'server' => new McpServerDefinitionDTO(name: 'server', command: 'mcp-server', excludeTools: ['x']),
        ]));
        $changedEnv = $this->builder->computeConfigHash(new McpConfigDTO([
            'server' => new McpServerDefinitionDTO(name: 'server', command: 'mcp-server', env: ['K' => 'v']),
        ]));

        $this->assertNotSame($hash, $changedExcludes);
        $this->assertNotSame($hash, $changedEnv);
    }

    public function testComputeConfigHashIsOrderIndependent(): void
    {
        $a = new McpServerDefinitionDTO(name: 'a', command: 'cmd-a');
        $b = new McpServerDefinitionDTO(name: 'b', command: 'cmd-b');

        $this->assertSame(
            $this->builder->computeConfigHash(new McpConfigDTO(['a' => $a, 'b' => $b])),
            $this->builder->computeConfigHash(new McpConfigDTO(['b' => $b, 'a' => $a])),
        );
    }
}
