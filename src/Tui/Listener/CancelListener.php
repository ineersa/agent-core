<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Event\CancelEvent;

/**
 * Handles Escape key — cancels the active run or clears the editor.
 *
 * When a run is active (Starting/Running/WaitingHuman/Cancelling),
 * sends a cancel command to the runtime via AgentSessionClient,
 * transitions activity to Cancelling, and shows a status message.
 *
 * When idle or in a terminal state, clears the editor text.
 *
 * Implements TuiListenerRegistrar for DI-driven registration.
 */
final class CancelListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $client = $context->client;
        $state = $context->state;
        $screen = $context->screen;
        $logger = $this->logger;

        $context->tui->addListener(static function (CancelEvent $event) use ($client, $state, $screen, $logger): void {
            if ($state->activity->isActive() && null !== $state->handle) {
                $logger->info('ESC cancel requested', [
                    'run_id' => $state->handle->runId,
                    'activity' => $state->activity->value,
                ]);

                try {
                    $client->cancel($state->handle->runId);
                } catch (\Throwable $e) {
                    $logger->warning('Cancel command failed', [
                        'run_id' => $state->handle->runId,
                        'exception' => $e,
                    ]);
                }

                $state->activity = RunActivityStateEnum::Cancelling;
                $screen->setWorkingMessage('Cancelling...');

                return;
            }

            // Idle/terminal — just clear the editor
            $screen->clearEditor();
        });
    }
}
