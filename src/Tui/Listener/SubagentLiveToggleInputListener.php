<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Picker\SubagentLivePickerController;
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
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $state = $context->state;
        $screen = $context->screen;
        $picker = $this->pickerController;

        $context->tui->addListener(
            static function (InputEvent $event) use ($state, $screen, $picker): void {
                if ("\x1c" !== $event->getData()) {
                    return;
                }

                $event->stopPropagation();

                if ($state->subagentLiveView->active) {
                    SubagentLiveMainReturn::returnToMain($state, $screen);
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
