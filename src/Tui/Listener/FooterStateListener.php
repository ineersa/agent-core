<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Listener registrar that initialises the TUI footer state and registers
 * a live segment provider.
 *
 * On registration:
 *  - FooterStateInitializer seeds model/reasoning/context/cwd/branch/time
 *  - A FooterStateSegmentProvider is registered on the chat screen
 *  - A tick handler refreshes the screen so elapsed time and throughput
 *    stay live
 */
final readonly class FooterStateListener implements TuiListenerRegistrar
{
    public function __construct(
        private FooterStateInitializer $initializer,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $this->initializer->initialize($context->state);

        $context->screen->addFooterProvider(
            new FooterStateSegmentProvider($context->state),
        );

        $screen = $context->screen;
        $context->ticks->add(static function () use ($screen): ?bool {
            $screen->refresh();

            return null;
        });
    }
}
