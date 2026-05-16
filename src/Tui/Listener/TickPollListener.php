<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Symfony\Component\Tui\Event\TickEvent;

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

        $context->tui->onTick(static function (TickEvent $event) use ($poller, $state, $client, $screen): ?bool {
            $newEntries = $poller->poll($state, $client);

            if (null !== $newEntries) {
                $screen->setTranscriptEntries($state->transcript);
                $screen->setWorkingMessage(null);
            }

            return null;
        });
    }
}
