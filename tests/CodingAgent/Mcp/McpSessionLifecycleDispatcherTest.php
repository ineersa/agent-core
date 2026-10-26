<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogEntryDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\SessionFileMcpToolCatalogStore;
use Ineersa\CodingAgent\Mcp\McpSessionLifecycleDispatcher;
use Ineersa\CodingAgent\Mcp\Message\McpInitializeSessionCommand;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

final class McpSessionLifecycleDispatcherTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = TestDirectoryIsolation::createProjectTempDir('mcp-lifecycle');
        TestDirectoryIsolation::createHatfieldTree($this->directory);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->directory);
    }

    public function testAbsentCapabilityDoesNotQueueInitializationButOldCatalogStillGetsInvalidated(): void
    {
        $bus = new TestMessageBus();
        $catalogs = new SessionFileMcpToolCatalogStore($this->directory);
        $dispatcher = new McpSessionLifecycleDispatcher($bus, new TestLogger(), TestMcpConfigLoaderFactory::create(new SettingsPathResolver($this->directory), $this->directory), $catalogs);
        $this->assertFalse($dispatcher->requiresConsumer('run'));
        $dispatcher->dispatchInitialize('run', 'start_run');
        $this->assertSame([], $bus->messages);

        $catalogs->write('run', new McpToolCatalogDTO(runId: 'run'));
        $this->assertFalse($dispatcher->requiresConsumer('run'));
        $dispatcher->dispatchInitialize('run', 'attach');
        $this->assertSame([], $bus->messages);

        $catalogs->write('run', new McpToolCatalogDTO(runId: 'run', servers: [
            'stale' => new McpServerCatalogEntryDTO('stale', 'stdio', McpServerCatalogStatusEnum::CONNECTED),
        ]));
        $this->assertTrue($dispatcher->requiresConsumer('run'));
        $dispatcher->dispatchInitialize('run', 'attach');
        $this->assertCount(1, $bus->messages);
        $this->assertInstanceOf(McpInitializeSessionCommand::class, $bus->messages[0]);
    }
}
