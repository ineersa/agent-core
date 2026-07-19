<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Runtime\SubagentLiveMainReturn;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Symfony\Component\Tui\Event\InputEvent;

/**
 * Ctrl+\ toggles subagent live view: open /agents-live from main, return to main from live view.
 */
final class SubagentLiveToggleInputListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SubagentLivePickerController $pickerController,
        private readonly QuestionController $questionController,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $state = $context->state;
        $screen = $context->screen;
        $picker = $this->pickerController;
        $questionController = $this->questionController;

        $context->tui->addListener(
            static function (InputEvent $event) use ($context, $state, $screen, $picker, $questionController): void {
                if ("\x1c" !== $event->getData()) {
                    return;
                }

                $event->stopPropagation();

                if ($state->subagentLiveView->active) {
                    SubagentLiveMainReturn::returnToMain($state, $screen, $context->client);
                    // Visual only: keep coordinator request pending for re-enter child.
                    $questionController->close();
                    $screen->setWorkingMessage('Returned to main session (Ctrl+\\).');

                    return;
                }

                if ($picker->isOpen()) {
                    $picker->closePicker();

                    return;
                }

                $picker->open();
            },
            priority: 90,
        );
    }
}
