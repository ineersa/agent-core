<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Tick listener that polls for new runtime events.
 *
 * Delegates polling logic to RuntimeEventPoller and updates the
 * transcript display and working status when new events arrive.
 *
 * Implements TuiListenerRegistrar for DI-driven registration.
 * The service itself is stateless; per-run state comes from the context.
 */
final class TickPollListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly RuntimeEventPoller $poller,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $poller = $this->poller;
        $state = $context->state;
        $client = $context->client;
        $screen = $context->screen;

        $context->ticks->add(static function () use ($poller, $state, $client, $screen): ?bool {
            $changedBlocks = $poller->poll($state, $client);

            if (null !== $changedBlocks) {
                $screen->setTranscriptBlocks($state->transcript);
            }

            // Update working status based on authoritative activity state.
            // SubmitListener sets 'Working...' optimistically on send;
            // this keeps it visible while active and clears it when idle/terminal.
            static $lastMsg = null;
            $msg = (RunActivityStateEnum::Idle === $state->activity || $state->activity->isTerminal())
                ? null
                : 'Working...';

            if ($msg !== $lastMsg) {
                $screen->setWorkingMessage($msg);
                $lastMsg = $msg;
            }

            return null;
        });
    }
}
