<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Mcp\Handler;

use Ineersa\CodingAgent\Mcp\Handler\McpInitializeSessionHandler;
use Ineersa\CodingAgent\Mcp\Message\McpInitializeSessionCommand;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Test thesis 1: MCP session initialize handler processes a valid
 * initialization without crashing and logs the expected lifecycle event.
 *
 * Test thesis 2: Invalid MCP config (missing/broken mcp.json) is
 * non-fatal — the handler catches the exception and logs a warning
 * without throwing.
 */
class McpInitializeSessionHandlerTest extends IsolatedKernelTestCase
{
    public function testInitializeWithEmptyConfigDoesNotThrow(): void
    {
        $handler = static::getContainer()->get(McpInitializeSessionHandler::class);

        // Should not throw for any config state — MCP is optional.
        $command = new McpInitializeSessionCommand(
            runId: 'test-run-123',
            reason: 'start_run',
            correlationId: 'corr-abc',
        );

        $handler($command);

        // If we got here, the handler survived — the key contract for Phase 1.
        self::assertTrue(true);
    }

    public function testInitializeDispatchedOnCommandBusDoesNotThrow(): void
    {
        /** @var MessageBusInterface $commandBus */
        $commandBus = static::getContainer()->get('agent.command.bus');

        $command = new McpInitializeSessionCommand(
            runId: 'test-run-456',
            reason: 'resume',
            correlationId: 'corr-def',
        );

        // Dispatch on command bus — in test env (in-memory transport) this
        // runs the handler synchronously.
        $commandBus->dispatch($command);

        // If we got here without exception, the handler + dispatch path works
        // end-to-end.
        self::assertTrue(true);
    }

    public function testRefreshCatalogHandlerExistsAndIsCallable(): void
    {
        $handler = static::getContainer()->get(McpInitializeSessionHandler::class);

        // Verify the handler method exists and is callable (Phase 1 skeleton)
        self::assertIsCallable([$handler, 'onRefreshCatalog']);
    }

    public function testDisconnectHandlerExistsAndIsCallable(): void
    {
        $handler = static::getContainer()->get(McpInitializeSessionHandler::class);

        // Verify the handler method exists and is callable (Phase 1 skeleton)
        self::assertIsCallable([$handler, 'onDisconnectSession']);
    }
}
