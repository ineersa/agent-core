<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp;

use Ineersa\CodingAgent\Mcp\Message\McpInitializeSessionCommand;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches MCP lifecycle commands from the session client layer.
 *
 * Called after start_run and resume so the MCP consumer receives
 * an initialize command.  Failure to dispatch is logged but never
 * propagated — MCP is optional infrastructure and must not block
 * normal session flow.
 *
 * Intentionally safe for both in-process (sync://) and controller
 * (Doctrine) transports.
 */
final readonly class McpSessionLifecycleDispatcher
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Dispatch an MCP initialize command for the given session.
     *
     * @param string $runId  the run/session identifier
     * @param string $reason why initialization was triggered: 'start_run' | 'resume'
     */
    public function dispatchInitialize(string $runId, string $reason): void
    {
        $correlationId = bin2hex(random_bytes(12));

        try {
            $this->commandBus->dispatch(new McpInitializeSessionCommand(
                runId: $runId,
                reason: $reason,
                correlationId: $correlationId,
            ));
        } catch (ExceptionInterface $e) {
            // Messenger dispatch failure is non-fatal for MCP.
            // The session continues without MCP tools.
            $this->logger->warning('Failed to dispatch MCP initialize command', [
                'component' => 'mcp',
                'mcp_event' => 'dispatch.initialize.failed',
                'run_id' => $runId,
                'session_id' => $runId,
                'reason' => $reason,
                'correlation_id' => $correlationId,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            // Unexpected dispatch error — still non-fatal.
            $this->logger->warning('Unexpected error dispatching MCP initialize command', [
                'component' => 'mcp',
                'mcp_event' => 'dispatch.initialize.failed',
                'run_id' => $runId,
                'session_id' => $runId,
                'reason' => $reason,
                'correlation_id' => $correlationId,
                'error_class' => $e::class,
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
