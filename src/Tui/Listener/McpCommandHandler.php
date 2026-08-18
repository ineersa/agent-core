<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\CompactHeader\McpServerStatusView;
use Ineersa\Tui\CompactHeader\McpStatusSnapshot;
use Ineersa\Tui\CompactHeader\McpStatusSnapshotProvider;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Utility\ThrowableMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Tui;

/**
 * Handles /mcp and /mcp reconnect.
 *
 * List reads via {@see McpStatusSnapshotProvider}. Reconnect dispatches
 * refreshMcpCatalog and polls catalog generation markers.
 *
 * @internal Registered by McpCommandRegistrar
 */
final class McpCommandHandler implements SlashCommandHandler
{
    private const WORKING_MESSAGE = 'Reconnecting MCP servers...';
    private const POLL_INTERVAL_US = 250_000;
    private const POLL_TIMEOUT_SECONDS = 10.0;

    public function __construct(
        private readonly McpStatusSnapshotProvider $statusProvider,
        private readonly AgentSessionClient $client,
        private readonly TuiSessionState $state,
        private readonly ChatScreen $screen,
        private readonly Tui $tui,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $args = trim($command->args);
        if ('' === $args) {
            return new TranscriptMessage($this->renderList(), 'system', 'markdown');
        }

        if ('reconnect' === $args) {
            return $this->handleReconnect();
        }

        return new TranscriptMessage(
            "Usage:\n- `/mcp` — list MCP servers, statuses, and tools\n- `/mcp reconnect` — reconnect all MCP servers",
            'system',
            'markdown',
        );
    }

    private function handleReconnect(): CommandResult
    {
        if ($this->state->activity->isActive()) {
            return new TranscriptMessage(
                'Cannot reconnect MCP while a run is active — wait for it to finish or cancel it first.',
                'system',
                'error',
            );
        }

        $sessionId = $this->state->sessionId;
        if ('' === $sessionId) {
            return new TranscriptMessage(
                'Cannot reconnect MCP on a draft session — send a message first so a session exists.',
                'system',
                'error',
            );
        }

        $before = $this->statusProvider->build($sessionId);
        $beforeGeneration = $before->generation;
        $beforeGeneratedAt = $before->generatedAt;

        $this->showWorkingIndicator();
        try {
            try {
                $this->client->refreshMcpCatalog($sessionId);
            } catch (\Throwable $e) {
                $this->logger->warning('MCP reconnect dispatch failed', [
                    'component' => 'McpCommandHandler',
                    'event_type' => 'mcp_reconnect.dispatch_failed',
                    'session_id' => $sessionId,
                    'run_id' => $sessionId,
                    'exception_class' => $e::class,
                    'error_message' => ThrowableMessage::sanitize($e),
                ]);

                return new TranscriptMessage(
                    'MCP reconnect failed to dispatch: '.ThrowableMessage::sanitize($e),
                    'system',
                    'error',
                );
            }

            $deadline = microtime(true) + self::POLL_TIMEOUT_SECONDS;
            while (microtime(true) < $deadline) {
                usleep(self::POLL_INTERVAL_US);
                $after = $this->statusProvider->build($sessionId);
                if ($after->generation !== $beforeGeneration || $after->generatedAt !== $beforeGeneratedAt) {
                    $text = $this->renderList($after);
                    foreach ($after->servers as $server) {
                        if ('not_initialized' === $server->status) {
                            $text .= "\n\n_Discovery may still be in progress — check `/mcp` again shortly._";
                            break;
                        }
                    }

                    return new TranscriptMessage($text, 'system', 'markdown');
                }
            }

            return new TranscriptMessage(
                'MCP reconnect dispatched; discovery may still be in progress (slow servers can take `startupTimeoutMs`). Check `/mcp` shortly.',
                'system',
                'markdown',
            );
        } finally {
            $this->clearWorkingIndicator();
        }
    }

    private function renderList(?McpStatusSnapshot $snapshot = null): string
    {
        $snapshot ??= $this->statusProvider->build($this->state->sessionId);

        if (null !== $snapshot->configError) {
            return "## MCP servers\n\nFailed to load MCP config: ".$snapshot->configError;
        }

        if ([] === $snapshot->servers) {
            return "## MCP servers\n\nNo MCP servers configured (see docs/mcp.md).\n\n`/mcp reconnect` to reconnect all servers.";
        }

        $lines = ['## MCP servers', ''];
        foreach ($snapshot->servers as $server) {
            $lines[] = '### `'.$server->name.'`';
            $lines[] = '- **Transport:** `'.$server->transport.'`';
            $lines[] = '- **Status:** '.$this->formatStatus($server);
            if ('connected' === $server->status) {
                $count = \count($server->toolNames);
                $lines[] = '- **Tools ('.$count.'):** '.([] === $server->toolNames
                    ? '_(none)_'
                    : '`'.implode('`, `', $server->toolNames).'`');
            } elseif (null !== $server->errorMessage && '' !== $server->errorMessage) {
                $lines[] = '- **Error:** '.$server->errorMessage;
            }
            $lines[] = '';
        }

        $lines[] = '`/mcp reconnect` to reconnect all servers.';

        // Empty catalog after a failed discovery/refresh still maps every configured
        // server to not_initialized — surface that so it does not look like never-init.
        if ($snapshot->generation > 0) {
            $allNotInitialized = true;
            foreach ($snapshot->servers as $server) {
                if ('not_initialized' !== $server->status) {
                    $allNotInitialized = false;
                    break;
                }
            }
            if ($allNotInitialized) {
                $lines[] = '';
                $lines[] = '> ⚠ **MCP catalog invalidated (failed discovery) — see controller logs; try `/mcp reconnect`.**';
            }
        }

        return implode("\n", $lines);
    }

    private function formatStatus(McpServerStatusView $server): string
    {
        return match ($server->status) {
            'connected' => '✅ Connected',
            'failed' => '❌ Failed',
            default => 'not initialized',
        };
    }

    private function showWorkingIndicator(): void
    {
        $this->screen->setWorkingMessage(self::WORKING_MESSAGE);
        try {
            $this->tui->requestRender();
            $this->tui->processRender();
        } catch (\Throwable $e) {
            $this->logger->debug('McpCommandHandler: working indicator render failed (non-fatal)', [
                'component' => 'McpCommandHandler',
                'event_type' => 'mcp_reconnect.indicator_render_failed',
                'session_id' => $this->state->sessionId,
                'exception_class' => $e::class,
            ]);
        }
    }

    private function clearWorkingIndicator(): void
    {
        if (self::WORKING_MESSAGE === $this->screen->workingMessage()) {
            $this->screen->setWorkingMessage('');
        }
    }
}
