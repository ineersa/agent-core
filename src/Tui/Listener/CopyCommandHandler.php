<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Utility\Clipboard;

/**
 * Handles the /copy (aliases: /cp) slash command.
 *
 * Scans the transcript for the last assistant message block and copies
 * its text to the system clipboard via {@see Clipboard::copy()}.
 *
 * The copy closure is injectable for testability without affecting
 * autowiring: when not injected, it defaults to Clipboard::copy().
 *
 * @internal Registered by CopyCommandRegistrar
 */
final class CopyCommandHandler implements SlashCommandHandler
{
    /** @var \Closure(string): bool */
    private readonly \Closure $copyFn;

    public function __construct(
        private readonly TuiSessionState $state,
        ?\Closure $copyFn = null,
    ) {
        $this->copyFn = $copyFn ?? static fn (string $text): bool => Clipboard::copy($text);
    }

    public function handle(SlashCommand $command): CommandResult
    {
        // Find the last assistant message block (scan from end using index)
        $blocks = $this->state->transcript;
        $lastAssistant = null;
        for ($i = \count($blocks) - 1; $i >= 0; --$i) {
            if (TranscriptBlockKindEnum::AssistantMessage === $blocks[$i]->kind) {
                $lastAssistant = $blocks[$i];
                break;
            }
        }

        if (null === $lastAssistant) {
            return new TranscriptMessage(
                'Nothing to copy — no model output yet.',
                'system',
                'muted',
            );
        }

        $success = ($this->copyFn)($lastAssistant->text);

        if ($success) {
            return new TranscriptMessage(
                'Copied last model output to clipboard.',
                'system',
            );
        }

        return new TranscriptMessage(
            'Failed to copy last model output to clipboard.',
            'system',
            'muted',
        );
    }
}
