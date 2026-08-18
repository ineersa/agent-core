<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogEntryDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Catalog\SessionFileMcpToolCatalogStore;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\CompactHeader\McpStatusSnapshotProvider;
use Ineersa\Tui\Listener\McpCommandHandler;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: /mcp lists config+catalog statuses; /mcp reconnect guards active runs
 * and polls for a generation change after refreshMcpCatalog.
 */
#[CoversClass(McpCommandHandler::class)]
final class McpCommandHandlerTest extends TestCase
{
    private string $projectRoot;
    private string $homeDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = TestDirectoryIsolation::createProjectTempDir('mcp-cmd');
        $this->homeDir = TestDirectoryIsolation::createOsTempDir('mcp-cmd-home');
        TestDirectoryIsolation::createHatfieldTree($this->projectRoot, withSessions: true);
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectRoot);
        TestDirectoryIsolation::removeDirectory($this->homeDir);
        parent::tearDown();
    }

    #[Test]
    public function testListRendersConnectedFailedAndNotInitializedServers(): void
    {
        $this->writeMcpJson([
            'browser' => ['url' => 'https://example.test/browser'],
            'bad' => ['url' => 'https://example.test/bad'],
            'pending' => ['url' => 'https://example.test/pending'],
        ]);

        $store = new SessionFileMcpToolCatalogStore($this->projectRoot);
        $store->write('sess-1', new McpToolCatalogDTO(
            schemaVersion: 1,
            runId: 'sess-1',
            generatedAt: '2026-08-18T00:00:00Z',
            generation: 1,
            servers: [
                'browser' => new McpServerCatalogEntryDTO(
                    serverName: 'browser',
                    transport: 'http',
                    status: McpServerCatalogStatusEnum::CONNECTED,
                    tools: [
                        new McpToolDefinitionDTO('browser_navigate', 'browser', 'navigate', 'Go', []),
                        new McpToolDefinitionDTO('browser_click', 'browser', 'click', 'Click', []),
                    ],
                ),
                'bad' => new McpServerCatalogEntryDTO(
                    serverName: 'bad',
                    transport: 'http',
                    status: McpServerCatalogStatusEnum::FAILED,
                    errorMessage: 'connection refused',
                    tools: [],
                ),
            ],
        ));

        $handler = $this->createHandler(
            provider: $this->provider($store),
            client: $this->createStub(AgentSessionClient::class),
            state: new TuiSessionState('sess-1', true),
        );

        $result = $handler->handle(new SlashCommand('mcp', '', '/mcp'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('markdown', $result->style);
        $this->assertStringContainsString('## MCP servers', $result->text);
        $this->assertStringContainsString('### `browser`', $result->text);
        $this->assertStringContainsString('✅ Connected', $result->text);
        $this->assertStringContainsString('`browser_navigate`', $result->text);
        $this->assertStringContainsString('`browser_click`', $result->text);
        $this->assertStringContainsString('### `bad`', $result->text);
        $this->assertStringContainsString('❌ Failed', $result->text);
        $this->assertStringContainsString('connection refused', $result->text);
        $this->assertStringContainsString('### `pending`', $result->text);
        $this->assertStringContainsString('not initialized', $result->text);
        $this->assertStringContainsString('`/mcp reconnect` to reconnect all servers.', $result->text);
    }

    #[Test]
    public function testListEmptyConfigShowsDocsHint(): void
    {
        $this->writeMcpJson([]);
        $handler = $this->createHandler(
            provider: $this->provider(new SessionFileMcpToolCatalogStore($this->projectRoot)),
            client: $this->createStub(AgentSessionClient::class),
            state: new TuiSessionState('sess-empty', true),
        );

        $result = $handler->handle(new SlashCommand('mcp', '', '/mcp'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('No MCP servers configured (see docs/mcp.md)', $result->text);
    }

    #[Test]
    public function testUnknownArgsShowUsage(): void
    {
        $this->writeMcpJson([]);
        $handler = $this->createHandler(
            provider: $this->provider(new SessionFileMcpToolCatalogStore($this->projectRoot)),
            client: $this->createStub(AgentSessionClient::class),
            state: new TuiSessionState('sess-1', true),
        );

        $result = $handler->handle(new SlashCommand('mcp', 'wat', '/mcp wat'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Usage:', $result->text);
        $this->assertStringContainsString('`/mcp reconnect`', $result->text);
    }

    #[Test]
    public function testReconnectRejectsActiveRun(): void
    {
        $this->writeMcpJson(['browser' => ['url' => 'https://example.test/browser']]);
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('refreshMcpCatalog');

        $state = new TuiSessionState('sess-1', true);
        $state->activity = RunActivityStateEnum::Running;

        $handler = $this->createHandler(
            provider: $this->provider(new SessionFileMcpToolCatalogStore($this->projectRoot)),
            client: $client,
            state: $state,
        );

        $result = $handler->handle(new SlashCommand('mcp', 'reconnect', '/mcp reconnect'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('error', $result->style);
        $this->assertStringContainsString('Cannot reconnect MCP while a run is active', $result->text);
    }

    #[Test]
    public function testReconnectHappyPathPollsUpdatedCatalog(): void
    {
        $this->writeMcpJson(['browser' => ['url' => 'https://example.test/browser']]);
        $store = new SessionFileMcpToolCatalogStore($this->projectRoot);
        $store->write('sess-1', new McpToolCatalogDTO(
            schemaVersion: 1,
            runId: 'sess-1',
            generatedAt: '2026-08-18T00:00:00Z',
            generation: 1,
            servers: [
                'browser' => new McpServerCatalogEntryDTO(
                    serverName: 'browser',
                    transport: 'http',
                    status: McpServerCatalogStatusEnum::FAILED,
                    errorMessage: 'stale',
                    tools: [],
                ),
            ],
        ));

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('refreshMcpCatalog')
            ->with('sess-1')
            ->willReturnCallback(static function () use ($store): void {
                $store->write('sess-1', new McpToolCatalogDTO(
                    schemaVersion: 1,
                    runId: 'sess-1',
                    generatedAt: '2026-08-18T00:00:10Z',
                    generation: 2,
                    servers: [
                        'browser' => new McpServerCatalogEntryDTO(
                            serverName: 'browser',
                            transport: 'http',
                            status: McpServerCatalogStatusEnum::CONNECTED,
                            tools: [
                                new McpToolDefinitionDTO('browser_navigate', 'browser', 'navigate', 'Go', []),
                            ],
                        ),
                    ],
                ));
            });

        $handler = $this->createHandler(
            provider: $this->provider($store),
            client: $client,
            state: new TuiSessionState('sess-1', true),
        );

        $result = $handler->handle(new SlashCommand('mcp', 'reconnect', '/mcp reconnect'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('markdown', $result->style);
        $this->assertStringContainsString('✅ Connected', $result->text);
        $this->assertStringContainsString('`browser_navigate`', $result->text);
        $this->assertStringNotContainsString('stale', $result->text);
    }

    /**
     * @param array<string, array<string, mixed>> $servers
     */
    private function writeMcpJson(array $servers): void
    {
        $payload = ['mcpServers' => []];
        foreach ($servers as $name => $fields) {
            $payload['mcpServers'][$name] = array_merge(['enabled' => true], $fields);
        }
        file_put_contents(
            $this->projectRoot.'/.hatfield/mcp.json',
            json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT),
        );
    }

    private function provider(SessionFileMcpToolCatalogStore $store): McpStatusSnapshotProvider
    {
        return new McpStatusSnapshotProvider(
            $store,
            TestMcpConfigLoaderFactory::create(
                new SettingsPathResolver($this->projectRoot, $this->homeDir),
                $this->projectRoot,
            ),
        );
    }

    private function createHandler(
        McpStatusSnapshotProvider $provider,
        AgentSessionClient $client,
        TuiSessionState $state,
    ): McpCommandHandler {
        $harness = new VirtualTuiHarness(sessionId: $state->sessionId);

        return new McpCommandHandler(
            $provider,
            $client,
            $state,
            $harness->screen(),
            $harness->tui(),
            new TestLogger(),
        );
    }
}
