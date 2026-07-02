<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\CompactHeader\CompactHeaderSnapshot;
use Ineersa\Tui\CompactHeader\CompactHeaderSnapshotProvider;
use Ineersa\Tui\CompactHeader\CompactHeaderWidget;
use Ineersa\Tui\Layout\TuiSlotRegistry;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Widget\WidgetPlacementEnum;
use Symfony\Component\Tui\Event\TickEvent;

/**
 * Registers the pinned compact-header widget on the first tick and refreshes its snapshot on a throttle.
 */
final class CompactHeaderRegistrar implements TuiListenerRegistrar
{
    private const REFRESH_INTERVAL_SECONDS = 2.5;

    public function __construct(
        private readonly CompactHeaderSnapshotProvider $provider,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $widget = new CompactHeaderWidget();
        $screen = $context->screen;
        $tui = $context->tui;
        $state = $context->state;
        $provider = $this->provider;

        $registered = false;
        $lastSnapshot = null;
        $lastBuildAt = 0.0;

        $context->ticks->add(static function (TickEvent $event) use ($widget, $screen, $tui, $state, $provider, &$registered, &$lastSnapshot, &$lastBuildAt): ?bool {
            $now = microtime(true);

            if (!$registered) {
                $registered = true;
                $snap = $provider->build($state->sessionId);
                $widget->setSnapshot($snap);
                $lastSnapshot = $snap;
                $lastBuildAt = $now;
                $screen->extensionContext()->setWidget(
                    'compact-header',
                    $widget,
                    WidgetPlacementEnum::AboveEditor,
                    TuiSlotRegistry::ORDER_PINNED_LAST,
                );
                $screen->refresh();
                $tui->requestRender();

                return null;
            }

            if (($now - $lastBuildAt) < self::REFRESH_INTERVAL_SECONDS) {
                return null;
            }

            $snap = $provider->build($state->sessionId);
            if ($lastSnapshot instanceof CompactHeaderSnapshot && $lastSnapshot->equals($snap)) {
                $lastBuildAt = $now;

                return null;
            }

            $widget->setSnapshot($snap);
            $lastSnapshot = $snap;
            $lastBuildAt = $now;
            $screen->refresh();
            $tui->requestRender();

            return null;
        });
    }
}
