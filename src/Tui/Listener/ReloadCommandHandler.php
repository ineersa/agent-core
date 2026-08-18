<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;

/**
 * Handles the /reload slash command.
 *
 * Triggers a full-process settings reload: the TUI stops, the session
 * client/controller tree is shut down synchronously, and bin/console's
 * outer bootstrap loop rebuilds the kernel/container from scratch and
 * resumes the same session under the freshly-read settings.
 *
 * Rejects the reload while any run is active or any transient input
 * state (queued/paste/editor/question) would be lost by the rebuild.
 *
 * @internal Registered by SessionCommandRegistrar
 */
final class ReloadCommandHandler implements SlashCommandHandler
{
    public function __construct(
        private readonly TuiSessionSwitchServiceInterface $switch,
        private readonly TuiSessionState $state,
        private readonly ChatScreen $screen,
        private readonly QuestionCoordinator $questionCoordinator,
    ) {
    }

    public function handle(SlashCommand $command): CommandResult
    {
        if ($this->state->activity->isActive()) {
            return new TranscriptMessage(
                'Cannot reload while a run is active — wait for it to finish or cancel it first.',
                'system',
                'error',
            );
        }

        if ($this->state->isCompacting) {
            return new TranscriptMessage(
                'Cannot reload during compaction — wait for it to finish.',
                'system',
                'error',
            );
        }

        if (null !== $this->state->queuedFollowUp) {
            return new TranscriptMessage(
                'Cannot reload while a follow-up is queued.',
                'system',
                'error',
            );
        }

        if ([] !== $this->state->queuedUserMessages) {
            return new TranscriptMessage(
                'Cannot reload while messages are queued for submission.',
                'system',
                'error',
            );
        }

        if ([] !== $this->state->pastedImagePendingByIndex) {
            return new TranscriptMessage(
                'Cannot reload while pasted images are pending.',
                'system',
                'error',
            );
        }

        if (null !== $this->state->pastedImagePasteInProgressIndex) {
            return new TranscriptMessage(
                'Cannot reload while a paste is in progress.',
                'system',
                'error',
            );
        }

        if (null !== $this->state->pendingEditorPromptText) {
            return new TranscriptMessage(
                'Cannot reload while editor prompt text is pending.',
                'system',
                'error',
            );
        }

        if ('' !== $this->screen->editorText()) {
            return new TranscriptMessage(
                'Cannot reload while the editor contains text — submit or clear it first.',
                'system',
                'error',
            );
        }

        if ($this->questionCoordinator->actionRequired()) {
            return new TranscriptMessage(
                'Cannot reload while a question is awaiting your answer.',
                'system',
                'error',
            );
        }

        $this->switch->requestReload($this->state->sessionId);

        return new NoOp();
    }
}
