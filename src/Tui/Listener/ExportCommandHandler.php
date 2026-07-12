<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Export\SessionEventsExportService;
use Ineersa\Tui\Runtime\TuiSessionState;

/**
 * Handles the /export (aliases: /exp) slash command.
 *
 * @internal Registered by ExportCommandRegistrar
 */
final class ExportCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly TuiSessionState $state,
        private readonly HatfieldSessionStore $sessionStore,
        private readonly SessionEventsExportService $exportService,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        try {
            return $this->doHandle($command);
        } catch (\Throwable $e) {
            return new TranscriptMessage(
                \sprintf('Failed to export session: %s', $e->getMessage()),
                'error',
            );
        }
    }

    private function doHandle(SlashCommand $command): CommandResult
    {
        $sessionId = $this->state->sessionId;
        if ('' === $sessionId) {
            return new TranscriptMessage(
                'No active session — start a conversation first.',
                'system',
                'muted',
            );
        }

        $eventsPath = $this->sessionStore->resolveSessionsBasePath().'/'.$sessionId.'/events.jsonl';

        $parseResult = $this->parsePathArg($command->args);
        if (null === $parseResult) {
            $outputPath = getcwd().'/hatfield-session-'.$sessionId.'.html';
        } elseif (false === $parseResult) {
            return new TranscriptMessage(
                'Malformed path — if using quotes, the path must have matching opening and closing quotes.',
                'error',
            );
        } else {
            $outputPath = $parseResult;
        }

        if (!str_starts_with($outputPath, '/')) {
            $outputPath = getcwd().'/'.$outputPath;
        }

        $sessionName = '';
        $sessionCwd = '';
        $createdAt = '';
        if (!str_ends_with($outputPath, '.jsonl')) {
            /** @var array<string, mixed> $metadata */
            $metadata = $this->sessionStore->loadMetadata($sessionId) ?? [];
            $sessionName = SessionEventsExportService::strFromArray($metadata, 'name', 'Session '.$sessionId);
            $sessionCwd = SessionEventsExportService::strFromArray($metadata, 'cwd');
            $createdAt = SessionEventsExportService::strFromArray($metadata, 'created_at');
        }

        try {
            $message = $this->exportService->exportEventsFile(
                $eventsPath,
                $outputPath,
                $sessionId,
                $sessionName,
                $sessionCwd,
                $createdAt,
            );

            return new TranscriptMessage($message);
        } catch (\RuntimeException $e) {
            $text = $e->getMessage();
            if (str_contains($text, 'no events') || str_contains($text, 'No events') || str_contains($text, 'empty')) {
                return new TranscriptMessage($text, 'system', 'muted');
            }

            return new TranscriptMessage($text, 'error');
        }
    }

    private function parsePathArg(string $args): string|false|null
    {
        $trimmed = trim($args);
        if ('' === $trimmed) {
            return null;
        }

        $firstChar = $trimmed[0];
        if ("'" === $firstChar || '"' === $firstChar) {
            $closingPos = strpos($trimmed, $firstChar, 1);
            if (false === $closingPos) {
                return false;
            }

            $path = substr($trimmed, 1, $closingPos - 1);
            if ('' === $path) {
                return null;
            }

            return $path;
        }

        $spacePos = strpos($trimmed, ' ');
        if (false === $spacePos) {
            return $trimmed;
        }

        return substr($trimmed, 0, $spacePos);
    }
}
