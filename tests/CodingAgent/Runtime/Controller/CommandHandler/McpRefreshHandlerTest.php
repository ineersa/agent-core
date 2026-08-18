<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Mcp\McpSessionLifecycleDispatcher;
use Ineersa\CodingAgent\Mcp\Message\McpRefreshCatalogCommand;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\McpRefreshHandler;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: JSONL mcp_refresh dispatches McpRefreshCatalogCommand; missing runId emits protocol.error.
 */
#[CoversClass(McpRefreshHandler::class)]
final class McpRefreshHandlerTest extends TestCase
{
    public function testMcpRefreshDispatchesRefreshCatalogCommand(): void
    {
        $bus = new TestMessageBus();
        $handler = new McpRefreshHandler(
            new McpSessionLifecycleDispatcher($bus, new TestLogger()),
            new TestLogger(),
        );

        $emitted = [];
        $emit = static function (RuntimeEvent $event) use (&$emitted): void {
            $emitted[] = $event;
        };

        $handler(new ControllerCommandEvent(
            new RuntimeCommand(id: 'cmd_mcp_1', type: 'mcp_refresh', runId: 'run-42'),
            $emit,
        ));

        $this->assertCount(1, $bus->messages);
        $this->assertInstanceOf(McpRefreshCatalogCommand::class, $bus->messages[0]);
        $this->assertSame('run-42', $bus->messages[0]->runId);
        $this->assertSame([], $emitted);
    }

    public function testEmitsProtocolErrorWhenRunIdMissing(): void
    {
        $bus = new TestMessageBus();
        $handler = new McpRefreshHandler(
            new McpSessionLifecycleDispatcher($bus, new TestLogger()),
            new TestLogger(),
        );

        $emitted = [];
        $emit = static function (RuntimeEvent $event) use (&$emitted): void {
            $emitted[] = $event;
        };

        $handler(new ControllerCommandEvent(
            new RuntimeCommand(id: 'cmd_mcp_2', type: 'mcp_refresh', runId: ''),
            $emit,
        ));

        $this->assertSame([], $bus->messages);
        $this->assertCount(1, $emitted);
        $this->assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emitted[0]->type);
        $this->assertStringContainsString('mcp_refresh requires runId', (string) ($emitted[0]->payload['error'] ?? ''));
    }

    public function testIgnoresOtherCommandTypes(): void
    {
        $bus = new TestMessageBus();
        $handler = new McpRefreshHandler(
            new McpSessionLifecycleDispatcher($bus, new TestLogger()),
            new TestLogger(),
        );

        $handler(new ControllerCommandEvent(
            new RuntimeCommand(id: 'cmd_other', type: 'compact', runId: 'run-1'),
            static function (RuntimeEvent $event): void {},
        ));

        $this->assertSame([], $bus->messages);
    }
}
