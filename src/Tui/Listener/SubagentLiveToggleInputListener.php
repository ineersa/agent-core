<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Layout\InputPriority;
use Ineersa\Tui\Runtime\SubagentLiveMainReturn;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Symfony\Component\Tui\Event\InputEvent;

/**
 * Ctrl+\ toggles subagent live view: open /agents-live from main, return to main from live view.
 */
final class SubagentLiveToggleInputListener implements TuiListenerRegistrar
{
    public function register(TuiRuntimeContext $context): void
    {
        $services = $context->sessionServices;
        $state = $context->state;
        $screen = $context->screen;
        $picker = $services->subagentLivePicker;
        $questionCoordinator = $services->questionCoordinator;
        $questionController = $services->questionController;

        $context->tui->addListener(
            static function (InputEvent $event) use ($context, $state, $screen, $picker, $questionCoordinator, $questionController): void {
                $keys = $screen->editorWidget()->getKeybindings();
                if (!$keys->matches($event->getData(), 'toggle_subagent_live')) {
                    return;
                }

                $event->stopPropagation();

                if ($state->subagentLiveView->active) {
                    $selected = $state->subagentLiveView->selected;
                    if (null !== $selected) {
                        $questionCoordinator->removeForRun($selected->agentRunId);
                        $questionController->close();
                    }
                    SubagentLiveMainReturn::returnToMain($state, $screen, $context->client);
                    $screen->setWorkingMessage('Returned to main session (Ctrl+\\).');

                    return;
                }

                if ($picker->isOpen()) {
                    $picker->closePicker();

                    return;
                }

                $picker->open();
            },
            priority: InputPriority::COMPLETION_SUBAGENT,
        );
    }
}
