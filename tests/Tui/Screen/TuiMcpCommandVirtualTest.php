<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogEntryDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolDefinitionDTO;
use Ineersa\CodingAgent\Mcp\Catalog\SessionFileMcpToolCatalogStore;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\CompactHeader\McpStatusSnapshotProvider;
use Ineersa\Tui\Listener\McpCommandRegistrar;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use Ineersa\Tui\Transcript\TranscriptBlockWidgetFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\MarkdownWidget;

/**
 * Thesis: production parser/router/registrar/handler for /mcp renders
 * configured server names and statuses on the virtual screen.
 */
final class TuiMcpCommandVirtualTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private string $projectRoot;
    private string $homeDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = TestDirectoryIsolation::createProjectTempDir('mcp-virtual');
        $this->homeDir = TestDirectoryIsolation::createOsTempDir('mcp-home');
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
    public function testMcpRoutesAndRendersServerStatuses(): void
    {
        file_put_contents($this->projectRoot.'/.hatfield/mcp.json', json_encode([
            'mcpServers' => [
                'browser' => [
                    'enabled' => true,
                    'url' => 'https://example.test/browser',
                ],
                'bad' => [
                    'enabled' => true,
                    'url' => 'https://example.test/bad',
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $store = new SessionFileMcpToolCatalogStore($this->projectRoot);
        $store->write('mcp-virtual', new McpToolCatalogDTO(
            schemaVersion: 1,
            runId: 'mcp-virtual',
            generatedAt: '2026-08-18T00:00:00Z',
            generation: 1,
            servers: [
                'browser' => new McpServerCatalogEntryDTO(
                    serverName: 'browser',
                    transport: 'http',
                    status: McpServerCatalogStatusEnum::CONNECTED,
                    tools: [
                        new McpToolDefinitionDTO('browser_navigate', 'browser', 'navigate', 'Go', []),
                    ],
                ),
                'bad' => new McpServerCatalogEntryDTO(
                    serverName: 'bad',
                    transport: 'http',
                    status: McpServerCatalogStatusEnum::FAILED,
                    errorMessage: 'boom',
                    tools: [],
                ),
            ],
        ));

        $provider = new McpStatusSnapshotProvider(
            $store,
            TestMcpConfigLoaderFactory::create(
                new SettingsPathResolver($this->projectRoot, $this->homeDir),
                $this->projectRoot,
            ),
        );
        $catalog = new SlashCommandCatalog();
        $state = new TuiSessionState('mcp-virtual', true);
        $harness = new VirtualTuiHarness(sessionId: 'mcp-virtual');
        $registrar = new McpCommandRegistrar($provider, new TestLogger());
        $registrar->registerCatalog($catalog);
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->withSessionServices($this->createSessionServices(catalog: $catalog))
            ->build();
        $registrar->register($context);

        $this->assertTrue($catalog->has('mcp'));
        $result = (new SubmissionRouter(new CommandParser(), $context->sessionServices->commandRegistry))->route('/mcp');
        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('markdown', $result->style);
        $this->assertStringContainsString('## MCP servers', $result->text);
        $this->assertStringContainsString('### `browser`', $result->text);
        $this->assertStringContainsString('✅ Connected', $result->text);
        $this->assertStringContainsString('### `bad`', $result->text);
        $this->assertStringContainsString('❌ Failed', $result->text);

        $block = (new TranscriptBlockFactory())->system('mcp-virtual', $result->text, 1, $result->style);
        $this->assertInstanceOf(MarkdownWidget::class, (new TranscriptBlockWidgetFactory())->buildWidget($block, $harness->screen()->theme()));
        $harness->screen()->setTranscriptBlocks([$block]);
        $screen = $harness->plainScreenText();
        $this->assertStringContainsString('browser', $screen);
        $this->assertStringContainsString('Connected', $screen);
        $this->assertStringContainsString('Failed', $screen);
    }
}
