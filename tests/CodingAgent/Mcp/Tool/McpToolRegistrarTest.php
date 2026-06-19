<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogEntryDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Tool\McpToolHandler;
use Ineersa\CodingAgent\Mcp\Tool\McpToolRegistrar;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis 1: A session catalog with connected server tools registers
 * MCP dynamic tools with correct Hatfield name, input schema, sequential
 * execution mode, and handler reverse mapping.
 *
 * Test thesis 2: Missing catalog is a no-op and stale MCP-owned dynamic
 * tools are removed without removing unrelated dynamic tools.
 *
 * Test thesis 3: Collided names are skipped with structured warning
 * while non-colliding tools register.
 */
final class McpToolRegistrarTest extends TestCase
{
    private ToolRegistry $registry;
    private TestLogger $logger;
    /** @var array<string, McpToolCatalogDTO> */
    private array $catalogStoreData = [];

    protected function setUp(): void
    {
        $this->registry = new ToolRegistry();
        $this->logger = new TestLogger();
        $this->catalogStoreData = [];
    }

    /* ── Test thesis 1: Catalog tools become dynamic registry tools ── */

    public function testRegistersConnectedServerToolsAsDynamic(): void
    {
        $catalog = new McpToolCatalogDTO(
            runId: 'run-abc',
            servers: [
                'my-server' => new McpServerCatalogEntryDTO(
                    serverName: 'my-server',
                    transport: 'stdio',
                    status: McpServerCatalogStatusEnum::CONNECTED,
                    tools: [
                        new McpToolDefinitionDTO(
                            hatfieldName: 'my_server_read',
                            serverName: 'my-server',
                            mcpName: 'read',
                            description: 'Read a file',
                            inputSchema: ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
                        ),
                    ],
                ),
            ],
        );

        $store = $this->makeCatalogStore(['run-abc' => $catalog]);
        $registrar = new McpToolRegistrar($store, $this->registry, $this->logger);

        $registrar->registerForRun('run-abc');

        $names = $this->registry->activeToolNames();
        $this->assertContains('my_server_read', $names, 'MCP tool should appear in active names');

        $definitions = $this->registry->activeToolDefinitions();
        $this->assertCount(1, $definitions);
        $def = $definitions[0];
        $this->assertSame('my_server_read', $def->name);
        $this->assertSame('Read a file', $def->description);
        $this->assertSame(['type' => 'object', 'properties' => ['path' => ['type' => 'string']]], $def->parametersJsonSchema);
        $this->assertSame(ToolExecutionMode::Sequential, $def->executionMode);

        // Handler reverse mapping
        $this->assertInstanceOf(McpToolHandler::class, $def->handler);
        $this->assertSame('my-server', $def->handler->serverName);
        $this->assertSame('read', $def->handler->mcpName);
    }

    public function testHandlerThrowsStructuredToolCallException(): void
    {
        $handler = new McpToolHandler('my-server', 'read');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not yet available for invocation');

        ($handler)(['path' => 'test.txt']);
    }

    public function testHandlerExceptionIsNotRetryable(): void
    {
        $handler = new McpToolHandler('my-server', 'read');

        try {
            ($handler)([]);
        } catch (ToolCallException $e) {
            $this->assertFalse($e->retryable(), 'MCP tool handler must be non-retryable');
            $this->assertNotNull($e->hint());
        }
    }

    public function testUsesDescriptionFallbackWhenEmpty(): void
    {
        $catalog = new McpToolCatalogDTO(
            runId: 'run-x',
            servers: [
                'srv' => new McpServerCatalogEntryDTO(
                    serverName: 'srv',
                    transport: 'stdio',
                    status: McpServerCatalogStatusEnum::CONNECTED,
                    tools: [
                        new McpToolDefinitionDTO(
                            hatfieldName: 'srv_tool',
                            serverName: 'srv',
                            mcpName: 'tool',
                            description: '',  // empty
                            inputSchema: [],
                        ),
                    ],
                ),
            ],
        );

        $store = $this->makeCatalogStore(['run-x' => $catalog]);
        $registrar = new McpToolRegistrar($store, $this->registry, $this->logger);

        $registrar->registerForRun('run-x');

        $defs = $this->registry->activeToolDefinitions();
        $this->assertCount(1, $defs);
        // Fallback description must be non-empty (registry rejects empty)
        $this->assertNotEmpty($defs[0]->description);
        $this->assertStringContainsString('MCP tool', $defs[0]->description);
        $this->assertStringContainsString('srv', $defs[0]->description);
    }

    public function testSkipsFailedServerEntries(): void
    {
        $catalog = new McpToolCatalogDTO(
            runId: 'run-fail',
            servers: [
                'conn' => new McpServerCatalogEntryDTO(
                    serverName: 'conn',
                    transport: 'stdio',
                    status: McpServerCatalogStatusEnum::CONNECTED,
                    tools: [
                        new McpToolDefinitionDTO(
                            hatfieldName: 'conn_tool',
                            serverName: 'conn',
                            mcpName: 'tool',
                            description: 'Connected tool',
                            inputSchema: [],
                        ),
                    ],
                ),
                'fail' => new McpServerCatalogEntryDTO(
                    serverName: 'fail',
                    transport: 'stdio',
                    status: McpServerCatalogStatusEnum::FAILED,
                    errorMessage: 'Server unreachable',
                    tools: [],
                ),
            ],
        );

        $store = $this->makeCatalogStore(['run-fail' => $catalog]);
        $registrar = new McpToolRegistrar($store, $this->registry, $this->logger);

        $registrar->registerForRun('run-fail');

        $names = $this->registry->activeToolNames();
        $this->assertCount(1, $names, 'Only connected server tools should register');
        $this->assertSame('conn_tool', $names[0]);
    }

    /* ── Test thesis 2: Missing catalog / stale cleanup ── */

    public function testMissingCatalogIsNoOp(): void
    {
        $store = $this->makeCatalogStore([]); // empty — read returns null
        $registrar = new McpToolRegistrar($store, $this->registry, $this->logger);

        $registrar->registerForRun('nonexistent');

        $this->assertSame([], $this->registry->activeToolNames());
        $this->assertCount(0, $this->logger->records);
    }

    public function testRemovesOnlyOwnedDynamicToolsOnReRegistration(): void
    {
        // First registration adds MCP tools
        $catalog1 = new McpToolCatalogDTO(
            runId: 'run-1',
            servers: [
                'srv' => new McpServerCatalogEntryDTO(
                    serverName: 'srv',
                    transport: 'stdio',
                    status: McpServerCatalogStatusEnum::CONNECTED,
                    tools: [
                        new McpToolDefinitionDTO(
                            hatfieldName: 'srv_x',
                            serverName: 'srv',
                            mcpName: 'x',
                            description: 'Tool X',
                            inputSchema: [],
                        ),
                    ],
                ),
            ],
        );

        // Use a mutable store so the registrar sees catalog updates across calls.
        $storeData = ['run-1' => $catalog1];
        $store = $this->makeMutableStore($storeData);
        $registrar = new McpToolRegistrar($store, $this->registry, $this->logger);
        $registrar->registerForRun('run-1');
        $this->assertContains('srv_x', $this->registry->activeToolNames());

        // Add an unrelated dynamic tool directly to the registry
        $unrelatedHandler = $this->dummyHandler();
        $this->registry->addDynamicTool(
            name: 'unrelated',
            description: 'Unrelated',
            parametersJsonSchema: [],
            handler: $unrelatedHandler,
        );
        $this->assertContains('unrelated', $this->registry->activeToolNames());

        // Second registration with a different catalog (only tool Y)
        $catalog2 = new McpToolCatalogDTO(
            runId: 'run-2',
            servers: [
                'srv' => new McpServerCatalogEntryDTO(
                    serverName: 'srv',
                    transport: 'stdio',
                    status: McpServerCatalogStatusEnum::CONNECTED,
                    tools: [
                        new McpToolDefinitionDTO(
                            hatfieldName: 'srv_y',
                            serverName: 'srv',
                            mcpName: 'y',
                            description: 'Tool Y',
                            inputSchema: [],
                        ),
                    ],
                ),
            ],
        );
        $storeData['run-2'] = $catalog2;
        $registrar->registerForRun('run-2');

        $names = $this->registry->activeToolNames();
        $this->assertNotContains('srv_x', $names, 'Stale MCP-owned tool should be removed');
        $this->assertContains('srv_y', $names, 'New MCP-owned tool should be registered');
        $this->assertContains('unrelated', $names, 'Unrelated dynamic tool should survive');
    }

    /* ── Test thesis 3: Collision handling ── */

    public function testSkipsCollidedToolNameAndLogsWarning(): void
    {
        // Register a permanent tool that will collide with the MCP tool name
        $this->registry->registerTool(
            name: 'collision_target',
            description: 'Permanent tool',
            parametersJsonSchema: [],
            handler: $this->dummyHandler(),
            promptLine: 'collision_target: Perm',
        );

        $catalog = new McpToolCatalogDTO(
            runId: 'run-collide',
            servers: [
                'srv1' => new McpServerCatalogEntryDTO(
                    serverName: 'srv1',
                    transport: 'stdio',
                    status: McpServerCatalogStatusEnum::CONNECTED,
                    tools: [
                        new McpToolDefinitionDTO(
                            hatfieldName: 'collision_target',  // collides with permanent
                            serverName: 'srv1',
                            mcpName: 'target',
                            description: 'Collision',
                            inputSchema: [],
                        ),
                        new McpToolDefinitionDTO(
                            hatfieldName: 'srv1_good',
                            serverName: 'srv1',
                            mcpName: 'good',
                            description: 'Non-colliding',
                            inputSchema: [],
                        ),
                    ],
                ),
            ],
        );

        $store = $this->makeCatalogStore(['run-collide' => $catalog]);
        $registrar = new McpToolRegistrar($store, $this->registry, $this->logger);

        $registrar->registerForRun('run-collide');

        $names = $this->registry->activeToolNames();
        $this->assertContains('collision_target', $names, 'Permanent tool should remain');
        $this->assertContains('srv1_good', $names, 'Non-colliding MCP tool should register');

        // Verify collision warning
        $warnings = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'warning' === $r['level']
                && ($r['context']['mcp_event'] ?? '') === 'tool.collision',
        ));
        $this->assertCount(1, $warnings, 'Expected one collision warning');
        $this->assertSame('srv1', $warnings[0]['context']['server_name']);
        $this->assertSame('target', $warnings[0]['context']['mcp_tool_name']);
        $this->assertSame('collision_target', $warnings[0]['context']['hatfield_tool_name']);
        $this->assertSame('tool_name_collision', $warnings[0]['context']['reason']);
        $this->assertSame('run-collide', $warnings[0]['context']['run_id']);
        $this->assertSame('run-collide', $warnings[0]['context']['session_id']);
        $this->assertSame('tool.collision', $warnings[0]['context']['event_type']);
    }

    public function testCollisionWithUnrelatedDynamicToolIsAlsoSkipped(): void
    {
        // Add an unrelated dynamic tool (not MCP-owned)
        $this->registry->addDynamicTool(
            name: 'existing_dynamic',
            description: 'Already here',
            parametersJsonSchema: [],
            handler: $this->dummyHandler(),
        );

        $catalog = new McpToolCatalogDTO(
            runId: 'run-dyn-collide',
            servers: [
                'srv' => new McpServerCatalogEntryDTO(
                    serverName: 'srv',
                    transport: 'stdio',
                    status: McpServerCatalogStatusEnum::CONNECTED,
                    tools: [
                        new McpToolDefinitionDTO(
                            hatfieldName: 'existing_dynamic',  // collides with unrelated dynamic
                            serverName: 'srv',
                            mcpName: 'tool',
                            description: 'Collision',
                            inputSchema: [],
                        ),
                    ],
                ),
            ],
        );

        $store = $this->makeCatalogStore(['run-dyn-collide' => $catalog]);
        $registrar = new McpToolRegistrar($store, $this->registry, $this->logger);

        $registrar->registerForRun('run-dyn-collide');

        // Only the unrelated dynamic should be present
        $names = $this->registry->activeToolNames();
        $this->assertSame(['existing_dynamic'], $names);

        // Collision warning logged
        $warnings = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'warning' === $r['level']
                && ($r['context']['mcp_event'] ?? '') === 'tool.collision',
        ));
        $this->assertCount(1, $warnings);
        $this->assertSame('run-dyn-collide', $warnings[0]['context']['run_id']);
        $this->assertSame('run-dyn-collide', $warnings[0]['context']['session_id']);
        $this->assertSame('tool.collision', $warnings[0]['context']['event_type']);
    }

    /**
     * Test: A permanent tool hidden by visibility filtering (allowlist
     * excluding its name) bypasses the toolDefinition() collision pre-check
     * (which returns null for hidden tools), but addDynamicTool still throws
     * because the permanent tool exists.  The registrar must catch that
     * exception and skip the collided tool while still registering non-
     * colliding tools from the same server.
     *
     * This exercises the catch-and-continue path in registerOneTool for
     * hidden permanent-tool collisions.
     */
    public function testSkipsHiddenPermanentCollisionAndLogsWarning(): void
    {
        // Register a permanent tool, then hide it via exclusions so
        // toolDefinition() returns null but addDynamicTool still throws.
        $this->registry->registerTool(
            name: 'hidden_perm',
            description: 'Hidden permanent tool',
            parametersJsonSchema: [],
            handler: $this->dummyHandler(),
            promptLine: 'hidden_perm: Hidden',
        );
        $this->registry->setExcludedToolNames(['hidden_perm']);

        $catalog = new McpToolCatalogDTO(
            runId: 'run-hidden',
            servers: [
                'srv' => new McpServerCatalogEntryDTO(
                    serverName: 'srv',
                    transport: 'stdio',
                    status: McpServerCatalogStatusEnum::CONNECTED,
                    tools: [
                        new McpToolDefinitionDTO(
                            hatfieldName: 'hidden_perm',  // collides with hidden permanent
                            serverName: 'srv',
                            mcpName: 'hidden',
                            description: 'Collision',
                            inputSchema: [],
                        ),
                        new McpToolDefinitionDTO(
                            hatfieldName: 'srv_good',
                            serverName: 'srv',
                            mcpName: 'good',
                            description: 'Non-colliding',
                            inputSchema: [],
                        ),
                    ],
                ),
            ],
        );

        $store = $this->makeCatalogStore(['run-hidden' => $catalog]);
        $registrar = new McpToolRegistrar($store, $this->registry, $this->logger);

        $registrar->registerForRun('run-hidden');

        // hidden_perm should NOT be registered as a dynamic tool
        // (the collision was caught); but srv_good should be registered.
        $dynamicTools = $this->registry->getDynamicTools();
        $dynamicNames = array_map(static fn (array $t): string => $t['name'], $dynamicTools);
        $this->assertNotContains('hidden_perm', $dynamicNames, 'Hidden permanent collision should not be registered as dynamic');

        // Non-colliding MCP tool should be registered
        $names = $this->registry->activeToolNames();
        // hidden_perm is excluded from visibility but srv_good should be visible
        $this->assertNotContains('hidden_perm', $names, 'Hidden permanent should not appear in active names');
        $this->assertContains('srv_good', $names, 'Non-colliding MCP tool should register and be visible');

        // Verify register_failed warning was logged with run_id/session_id
        $warnings = array_values(array_filter(
            $this->logger->records,
            static fn (array $r): bool => 'warning' === $r['level']
                && ($r['context']['mcp_event'] ?? '') === 'tool.register_failed',
        ));
        $this->assertCount(1, $warnings, 'Expected one register_failed warning for hidden collision');
        $this->assertSame('hidden_perm', $warnings[0]['context']['hatfield_tool_name']);
        $this->assertSame('srv', $warnings[0]['context']['server_name']);
        $this->assertSame('run-hidden', $warnings[0]['context']['run_id']);
        $this->assertSame('run-hidden', $warnings[0]['context']['session_id']);
        $this->assertSame('tool.register_failed', $warnings[0]['context']['event_type']);
    }

    /* ── Helpers ── */

    /**
     * @param array<string, McpToolCatalogDTO> $data runId → catalog
     */
    private function makeCatalogStore(array $data): McpToolCatalogStoreInterface
    {
        return new class($data) implements McpToolCatalogStoreInterface {
            /** @param array<string, McpToolCatalogDTO> $data */
            public function __construct(private array $data)
            {
            }

            public function write(string $runId, McpToolCatalogDTO $catalog): void
            {
                $this->data[$runId] = $catalog;
            }

            public function read(string $runId): ?McpToolCatalogDTO
            {
                return $this->data[$runId] ?? null;
            }
        };
    }

    /**
     * @param array<string, McpToolCatalogDTO> $data runId → catalog (reference)
     */
    private function makeMutableStore(array &$data): McpToolCatalogStoreInterface
    {
        return new class($data) implements McpToolCatalogStoreInterface {
            /** @param array<string, McpToolCatalogDTO> $data */
            public function __construct(private array &$data)
            {
            }

            public function write(string $runId, McpToolCatalogDTO $catalog): void
            {
                $this->data[$runId] = $catalog;
            }

            public function read(string $runId): ?McpToolCatalogDTO
            {
                return $this->data[$runId] ?? null;
            }
        };
    }

    private function dummyHandler(): ToolHandlerInterface
    {
        return new class implements ToolHandlerInterface {
            public function __invoke(array $arguments = []): string
            {
                return 'dummy';
            }
        };
    }
}
