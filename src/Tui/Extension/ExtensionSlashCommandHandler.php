<?php

declare(strict_types=1);

namespace Ineersa\Tui\Extension;

use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;

/**
 * Adapter that wraps an extension CommandHandlerInterface into a
 * native TUI SlashCommandHandler.
 *
 * Creates an ExtensionCommandContext to collect notify() messages,
 * delegates to the extension handler, then maps the collected
 * messages into the appropriate TUI CommandResult (NoOp when
 * nothing was notified, or TranscriptMessage with severity-mapped
 * role/style).
 */
final readonly class ExtensionSlashCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private ExtensionCommandHandlerInterface $extensionHandler,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        $context = new ExtensionCommandContext();

        $this->extensionHandler->handle($command->args, $context);

        if ([] === $context->messages) {
            return new NoOp();
        }

        $text = implode("\n", $context->messages);

        $role = 'system';
        $style = '';
        if ($context->highestSeverity >= 3) {
            $role = 'error';
            $style = 'error';
        } elseif ($context->highestSeverity >= 2) {
            $style = 'accent';
        }

        return new TranscriptMessage($text, $role, $style);
    }
}
