<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Mcp\McpSessionLifecycleDispatcher;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles JSONL mcp_refresh commands from the parent TUI/controller process.
 *
 * Dispatches {@see \Ineersa\CodingAgent\Mcp\Message\McpRefreshCatalogCommand}
 * onto agent.command.bus so the mcp consumer reconnects and rewrites the
 * session catalog. Does not wait for discovery to finish.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class McpRefreshHandler
{
    public function __construct(
        private readonly McpSessionLifecycleDispatcher $mcpDispatcher,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if ('mcp_refresh' !== $event->command->type) {
            return;
        }

        $runId = $event->command->runId ?? '';
        if ('' === $runId) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: '',
                seq: 0,
                payload: ['error' => 'mcp_refresh requires runId'],
            ));

            return;
        }

        $this->logger->info('Handling mcp_refresh command', [
            'component' => 'McpRefreshHandler',
            'event_type' => 'mcp_refresh.dispatch',
            'run_id' => $runId,
            'session_id' => $runId,
        ]);

        $this->mcpDispatcher->dispatchRefresh($runId);
    }
}
