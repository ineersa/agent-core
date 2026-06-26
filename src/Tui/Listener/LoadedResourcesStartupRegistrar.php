<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\LoadedResourcesSummaryProviderInterface;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\TickEvent;

/**
 * Defers loaded-resources summary build to the first tick (keeps pre-loop startup fast)
 * and toggles source-path expansion with ctrl+r.
 */
final readonly class LoadedResourcesStartupRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private LoadedResourcesSummaryProviderInterface $loadedResourcesSummaryProvider,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $screen = $context->screen;
        $tui = $context->tui;
        $state = $context->state;
        $provider = $this->loadedResourcesSummaryProvider;

        $loaded = false;
        $context->ticks->add(static function (TickEvent $event) use ($screen, $tui, $state, $provider, &$loaded): ?bool {
            if ($loaded || $state->resuming) {
                return null;
            }

            $loaded = true;
            $screen->setLoadedResourcesSummary($provider->build());
            $tui->requestRender();

            return null;
        });

        $tui->addListener(static function (InputEvent $event) use ($screen, $tui): void {
            if ("\x12" !== $event->getData()) { // ctrl+r
                return;
            }

            if (!$screen->hasLoadedResourcesBlock()) {
                return;
            }

            $event->stopPropagation();

            $screen->toggleLoadedResourcesExpanded();
            $tui->requestRender();
        }, priority: 50);
    }
}
