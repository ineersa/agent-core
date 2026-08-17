<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Catalog;

use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogEntryDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Catalog\SessionFileMcpToolCatalogStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis 1: Catalog store round-trip — write a catalog with connected
 * and failed servers, read it back, verify all fields survive.
 *
 * Test thesis 2: Atomic write behavior — a new generation replaces
 * previous tools; stale tools do not survive a failed/empty refresh.
 *
 * Test thesis 3: Read returns null when no catalog has been written.
 *
 * Test thesis 4: Run ID sanitization rejects path traversal sequences.
 */
class SessionFileMcpToolCatalogStoreTest extends TestCase
{
    private string $projectDir;
    private SessionFileMcpToolCatalogStore $store;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('mcp-catalog-store');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, true);
        $this->store = new SessionFileMcpToolCatalogStore($this->projectDir);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testReadReturnsNullWhenNoCatalogWritten(): void
    {
        $catalog = $this->store->read('no-catalog-run');
        $this->assertNull($catalog, 'Read should return null when catalog does not exist');
    }

    public function testRoundTripWithConnectedAndFailedServers(): void
    {
        $runId = 'test-run-roundtrip';

        $tools = [
            new McpToolDefinitionDTO(
                hatfieldName: 'filesystem_read_file',
                serverName: 'filesystem',
                mcpName: 'read_file',
                description: 'Read a file from the filesystem',
                inputSchema: ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
            ),
            new McpToolDefinitionDTO(
                hatfieldName: 'filesystem_write_file',
                serverName: 'filesystem',
                mcpName: 'write_file',
                description: 'Write a file',
                inputSchema: ['type' => 'object', 'properties' => []],
            ),
        ];

        $servers = [
            'filesystem' => new McpServerCatalogEntryDTO(
                serverName: 'filesystem',
                transport: 'stdio',
                status: McpServerCatalogStatusEnum::CONNECTED,
                tools: $tools,
            ),
            'github' => new McpServerCatalogEntryDTO(
                serverName: 'github',
                transport: 'http',
                status: McpServerCatalogStatusEnum::FAILED,
                errorMessage: 'Connection refused',
                tools: [],
            ),
        ];

        $catalog = new McpToolCatalogDTO(
            schemaVersion: 1,
            runId: $runId,
            generatedAt: '2026-06-18T12:00:00Z',
            generation: 1,
            configHash: 'abc123',
            servers: $servers,
        );

        // Write and read back
        $this->store->write($runId, $catalog);
        $read = $this->store->read($runId);

        $this->assertNotNull($read, 'Catalog should exist after write');
        $this->assertSame(1, $read->schemaVersion);
        $this->assertSame($runId, $read->runId);
        $this->assertSame('2026-06-18T12:00:00Z', $read->generatedAt);
        $this->assertSame(1, $read->generation);
        $this->assertSame('abc123', $read->configHash);

        // Verify connected server
        $this->assertArrayHasKey('filesystem', $read->servers);
        $this->assertSame(McpServerCatalogStatusEnum::CONNECTED, $read->servers['filesystem']->status);
        $this->assertSame('stdio', $read->servers['filesystem']->transport);
        $this->assertCount(2, $read->servers['filesystem']->tools);
        $this->assertSame('filesystem_read_file', $read->servers['filesystem']->tools[0]->hatfieldName);
        $this->assertSame('read_file', $read->servers['filesystem']->tools[0]->mcpName);
        $this->assertSame('filesystem', $read->servers['filesystem']->tools[0]->serverName);
        $this->assertSame('Read a file from the filesystem', $read->servers['filesystem']->tools[0]->description);
        $this->assertSame('object', $read->servers['filesystem']->tools[0]->inputSchema['type']);

        // Verify failed server
        $this->assertArrayHasKey('github', $read->servers);
        $this->assertSame(McpServerCatalogStatusEnum::FAILED, $read->servers['github']->status);
        $this->assertSame('Connection refused', $read->servers['github']->errorMessage);
        $this->assertCount(0, $read->servers['github']->tools);
    }

    public function testWriteReplacesPreviousCatalog(): void
    {
        $runId = 'test-run-replace';

        // Write first catalog with tools
        $catalog1 = McpToolCatalogDTO::empty($runId, 1, 'hash-v1');
        $this->store->write($runId, $catalog1);
        $read1 = $this->store->read($runId);
        $this->assertSame(1, $read1->generation);
        $this->assertSame('hash-v1', $read1->configHash);

        // Write second catalog with different config hash — replaces first
        $tools = [
            new McpToolDefinitionDTO(
                hatfieldName: 'echo_hello',
                serverName: 'echo',
                mcpName: 'hello',
                description: '',
                inputSchema: [],
            ),
        ];
        $servers = [
            'echo' => new McpServerCatalogEntryDTO(
                serverName: 'echo',
                transport: 'stdio',
                status: McpServerCatalogStatusEnum::CONNECTED,
                tools: $tools,
            ),
        ];
        $catalog2 = new McpToolCatalogDTO(
            schemaVersion: 1,
            runId: $runId,
            generatedAt: '2026-06-18T13:00:00Z',
            generation: 2,
            configHash: 'hash-v2',
            servers: $servers,
        );
        $this->store->write($runId, $catalog2);

        // Read back — should be the new catalog, not the old one
        $read2 = $this->store->read($runId);
        $this->assertSame(2, $read2->generation);
        $this->assertSame('hash-v2', $read2->configHash);
        $this->assertCount(1, $read2->servers);
        $this->assertArrayHasKey('echo', $read2->servers);
        $this->assertCount(1, $read2->servers['echo']->tools);
    }

    public function testEmptyCatalogReplacesPreviousTools(): void
    {
        $runId = 'test-run-empty-replace';

        // First write a catalog with tools
        $tools = [
            new McpToolDefinitionDTO(
                hatfieldName: 'server_tool1',
                serverName: 'server',
                mcpName: 'tool1',
                description: '',
                inputSchema: [],
            ),
        ];
        $servers = [
            'server' => new McpServerCatalogEntryDTO(
                serverName: 'server',
                transport: 'stdio',
                status: McpServerCatalogStatusEnum::CONNECTED,
                tools: $tools,
            ),
        ];
        $catalog1 = new McpToolCatalogDTO(
            schemaVersion: 1,
            runId: $runId,
            generatedAt: '2026-06-18T12:00:00Z',
            generation: 1,
            configHash: 'old',
            servers: $servers,
        );
        $this->store->write($runId, $catalog1);

        // Now overwrite with empty catalog — stale tools should be gone
        $catalog2 = McpToolCatalogDTO::empty($runId, 2, 'new');
        $this->store->write($runId, $catalog2);

        $read = $this->store->read($runId);
        $this->assertSame(2, $read->generation);
        $this->assertSame('new', $read->configHash);
        $this->assertCount(0, $read->servers, 'Stale tools must not survive empty refresh');
    }

    public function testRunIdRejectsEmptyString(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must not be empty');

        $this->store->write('', new McpToolCatalogDTO());
    }

    public function testRunIdRejectsPathSeparators(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid characters');

        $this->store->write('evil/run', new McpToolCatalogDTO());
    }

    public function testRunIdRejectsDotDotSegments(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('".." segment');

        // Use a run ID with ".." but no path separators (slashes)
        $this->store->write('run..escape', new McpToolCatalogDTO());
    }

    public function testCatalogFileIsWrittenToSessionDirectory(): void
    {
        $runId = 'test-run-session-dir';
        $catalog = McpToolCatalogDTO::empty($runId, 1);

        $this->store->write($runId, $catalog);

        $catalogPath = $this->projectDir.'/.hatfield/sessions/'.$runId.'/mcp-tools.json';
        $this->assertFileExists($catalogPath, 'Catalog file should exist at the expected session path');

        $content = file_get_contents($catalogPath);
        $this->assertIsString($content);

        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('schemaVersion', $decoded);
        $this->assertArrayHasKey('runId', $decoded);
        $this->assertArrayHasKey('servers', $decoded);
    }

    public function testCrossRunIdsAreIsolated(): void
    {
        $run1 = 'test-run-aaa';
        $run2 = 'test-run-bbb';

        $tools1 = [
            new McpToolDefinitionDTO(
                hatfieldName: 'aaa_tool',
                serverName: 'aaa',
                mcpName: 'tool',
                description: '',
                inputSchema: [],
            ),
        ];
        $servers1 = [
            'aaa' => new McpServerCatalogEntryDTO(
                serverName: 'aaa',
                transport: 'stdio',
                status: McpServerCatalogStatusEnum::CONNECTED,
                tools: $tools1,
            ),
        ];
        $catalog1 = new McpToolCatalogDTO(
            schemaVersion: 1,
            runId: $run1,
            generatedAt: '2026-06-18T12:00:00Z',
            generation: 1,
            servers: $servers1,
        );
        $this->store->write($run1, $catalog1);

        // Write a different catalog for run2
        $catalog2 = McpToolCatalogDTO::empty($run2, 1);
        $this->store->write($run2, $catalog2);

        // Run1 should still have its tools
        $read1 = $this->store->read($run1);
        $this->assertCount(1, $read1->servers);
        $this->assertArrayHasKey('aaa', $read1->servers);

        // Run2 should be empty
        $read2 = $this->store->read($run2);
        $this->assertCount(0, $read2->servers);
    }
}
