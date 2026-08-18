<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Catalog;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogBuilder;
use Ineersa\CodingAgent\Mcp\Config\McpConfigDTO;
use Ineersa\CodingAgent\Mcp\Config\McpServerDefinitionDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests the catalog-construction policy moved out of
 * McpInitializeSessionHandler: ordering, sanitization, field fallbacks,
 * failed-server entries, exclude filters, cross-server collisions, and
 * config-hash determinism — without lifecycle behavior.
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

    public function testBuildProducesFullCatalogShape(): void
    {
        $config = new McpConfigDTO([
            'first' => new McpServerDefinitionDTO(name: 'first'),
            'my server!' => new McpServerDefinitionDTO(name: 'my server!'),
            'broken' => new McpServerDefinitionDTO(name: 'broken'),
        ]);

        $catalog = $this->builder->build($config, 'run-1', 'cfg-hash', [
            'first' => self::connected('stdio', [
                ['name' => 'get$data', 'description' => 'A', 'inputSchema' => ['type' => 'object']],
                ['name' => '___'],
                ['name' => ''],
            ]),
            'my server!' => self::connected('http', [
                ['name' => 'read', 'description' => 'B', 'inputSchema' => []],
            ]),
            'broken' => [
                'status' => 'failed',
                'transport' => 'http',
                'tools' => [],
                'errorMessage' => 'Connection refused',
            ],
        ]);

        $array = $catalog->toArray();

        // Root keys, metadata, and server ordering.
        $this->assertSame(
            ['schemaVersion', 'runId', 'generatedAt', 'generation', 'configHash', 'servers'],
            array_keys($array),
        );
        $this->assertSame(1, $array['schemaVersion']);
        $this->assertSame('run-1', $array['runId']);
        $this->assertSame(1, $array['generation']);
        $this->assertSame('cfg-hash', $array['configHash']);
        $this->assertSame(['first', 'my server!', 'broken'], array_keys($array['servers']));

        // Sanitized mapping, field fallbacks, empty-name skip.
        $this->assertSame([
            ['hatfieldName' => 'first_get_data', 'serverName' => 'first', 'mcpName' => 'get$data', 'description' => 'A', 'inputSchema' => ['type' => 'object']],
            ['hatfieldName' => 'first_unknown', 'serverName' => 'first', 'mcpName' => '___', 'description' => '', 'inputSchema' => []],
        ], $array['servers']['first']['tools']);
        $this->assertSame('my_server_read', $array['servers']['my server!']['tools'][0]['hatfieldName']);

        // Failed server entry with diagnostic-safe error.
        $this->assertSame([
            'serverName' => 'broken',
            'transport' => 'http',
            'status' => 'failed',
            'errorMessage' => 'Connection refused',
            'tools' => [],
        ], $array['servers']['broken']);
    }

    public function testBuildAppliesExcludesAndSkipsCrossServerCollisions(): void
    {
        $config = new McpConfigDTO([
            'a.b' => new McpServerDefinitionDTO(name: 'a.b', excludeTools: ['secret']),
            'a_b' => new McpServerDefinitionDTO(name: 'a_b'),
        ]);

        $catalog = $this->builder->build($config, 'run-1', 'hash', [
            'a.b' => self::connected('stdio', [
                ['name' => 'secret', 'description' => '', 'inputSchema' => []],
                ['name' => 'tool', 'description' => 'From a.b', 'inputSchema' => []],
            ]),
            // "a.b" and "a_b" both sanitize to "a_b" — second tool collides.
            'a_b' => self::connected('stdio', [
                ['name' => 'tool', 'description' => 'From a_b', 'inputSchema' => []],
            ]),
        ]);

        $this->assertSame(
            ['a_b_tool'],
            array_map(static fn ($t): string => $t->hatfieldName, $catalog->servers['a.b']->tools),
        );
        $this->assertCount(0, $catalog->servers['a_b']->tools, 'Duplicate mapped name must be skipped');

        $this->assertSame('tool.excluded', $this->logger->records[0]['context']['mcp_event']);
        $this->assertSame('secret', $this->logger->records[0]['context']['mcp_tool_name']);
        $this->assertSame('tool.duplicate', $this->logger->records[1]['context']['mcp_event']);
        $this->assertSame('a_b_tool', $this->logger->records[1]['context']['hatfield_name']);
    }

    public function testComputeConfigHashIsDeterministicOrderIndependentAndChangeSensitive(): void
    {
        $a = new McpServerDefinitionDTO(name: 'a', command: 'cmd-a');
        $b = new McpServerDefinitionDTO(name: 'b', command: 'cmd-b');

        $hash = $this->builder->computeConfigHash(new McpConfigDTO(['a' => $a, 'b' => $b]));

        $this->assertSame($hash, $this->builder->computeConfigHash(new McpConfigDTO(['a' => $a, 'b' => $b])));
        $this->assertSame($hash, $this->builder->computeConfigHash(new McpConfigDTO(['b' => $b, 'a' => $a])));
        $this->assertNotSame($hash, $this->builder->computeConfigHash(new McpConfigDTO([
            'a' => new McpServerDefinitionDTO(name: 'a', command: 'cmd-a', excludeTools: ['x']),
            'b' => $b,
        ])));
        $this->assertNotSame($hash, $this->builder->computeConfigHash(new McpConfigDTO([
            'a' => new McpServerDefinitionDTO(name: 'a', command: 'cmd-a', env: ['K' => 'v']),
            'b' => $b,
        ])));
    }

    public function testComputeConfigHashPropagatesJsonException(): void
    {
        $config = new McpConfigDTO([
            'broken' => new McpServerDefinitionDTO(
                name: 'broken',
                command: 'cmd',
                env: ['K' => "\xB1\x31"],
            ),
        ]);

        $this->expectException(\JsonException::class);
        $this->builder->computeConfigHash($config);
    }

    /**
     * @param list<array<string, mixed>> $tools
     *
     * @return array{status: 'connected', transport: string, tools: list<array<string, mixed>>}
     */
    private static function connected(string $transport, array $tools): array
    {
        return ['status' => 'connected', 'transport' => $transport, 'tools' => $tools];
    }
}
