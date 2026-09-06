<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\LoadedResources\LoadedResourcesSummaryBuilder;
use Ineersa\Tui\Layout\InputPriority;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\TickEvent;

/**
 * Pre-seeds the loaded-resources block during registrar setup so the first paint
 * includes startup chrome in one batch (avoids a second tick pop-in). First-tick
 * handler remains for legacy safety; ctrl+r toggles source-path expansion.
 */
final readonly class LoadedResourcesStartupRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private LoadedResourcesSummaryBuilder $loadedResourcesSummaryBuilder,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $screen = $context->screen;
        $tui = $context->tui;
        $state = $context->state;
        $provider = $this->loadedResourcesSummaryBuilder;

        $loaded = false;

        if (!$state->resuming) {
            $screen->setLoadedResourcesSummary($provider->build());
            $loaded = true;
        }

        $context->ticks->add(static function (TickEvent $event) use ($screen, $tui, $state, $provider, $loaded): ?bool {
            if ($loaded || $state->resuming) {
                return null;
            }

            $loaded = true;
            $screen->setLoadedResourcesSummary($provider->build());
            $tui->requestRender();

            return null;
        });

        $tui->addListener(static function (InputEvent $event) use ($screen, $tui): void {
            $keys = $screen->editorWidget()->getKeybindings();
            if (!$keys->matches($event->getData(), 'toggle_loaded_resources')) {
                return;
            }

            if (!$screen->hasLoadedResourcesBlock()) {
                return;
            }

            $event->stopPropagation();

            $screen->toggleLoadedResourcesExpanded();
            $tui->requestRender();
        }, priority: InputPriority::EXTENSION_DEFAULT);
    }
}
