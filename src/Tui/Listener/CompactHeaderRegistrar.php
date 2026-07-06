<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\CompactHeader\CompactHeaderSnapshotProvider;
use Ineersa\Tui\CompactHeader\CompactHeaderWidget;
use Ineersa\Tui\Layout\TuiSlotRegistry;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Widget\WidgetPlacementEnum;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Tui\Event\TickEvent;

/**
 * Registers the pinned compact-header widget on the first tick and refreshes its snapshot on a throttle.
 *
 * The ~2.5s refresh re-reads the MCP catalog each cycle; skills, agents, and prompt templates stay
 * cached for the discovery service instance lifetime (mid-session additions need a restart).
 */
final class CompactHeaderRegistrar implements TuiListenerRegistrar
{
    private const REFRESH_INTERVAL_SECONDS = 2.5;
    private const COMPACT_HEADER_KEY = 'compact-header';

    public function __construct(
        private readonly CompactHeaderSnapshotProvider $provider,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $widget = new CompactHeaderWidget();
        $screen = $context->screen;
        $tui = $context->tui;
        $state = $context->state;
        $provider = $this->provider;
        $logger = $this->logger;

        $registered = false;
        $lastSnapshot = null;
        $lastBuildAt = 0.0;

        $context->ticks->add(static function (TickEvent $event) use ($widget, $screen, $tui, $state, $provider, $logger, &$registered, &$lastSnapshot, &$lastBuildAt): ?bool {
            $now = microtime(true);

            if ($state->subagentLiveView->active) {
                if ($registered) {
                    $screen->extensionContext()->setWidget(self::COMPACT_HEADER_KEY, null);
                    $screen->refreshAboveEditorWidgets();
                    $tui->requestRender();
                    $registered = false;
                    $lastSnapshot = null;
                }

                return null;
            }

            if (!$registered) {
                $registered = true;
                try {
                    $snap = $provider->build($state->sessionId);
                } catch (\Throwable $e) {
                    $logger->warning('Compact header snapshot failed', ['exception' => $e]);
                    $lastBuildAt = $now;

                    return null;
                }
                $widget->setSnapshot($snap);
                $lastSnapshot = $snap;
                $lastBuildAt = $now;
                $screen->extensionContext()->setWidget(
                    self::COMPACT_HEADER_KEY,
                    $widget,
                    WidgetPlacementEnum::AboveEditor,
                    TuiSlotRegistry::ORDER_PINNED_LAST,
                );
                $screen->refreshAboveEditorWidgets();
                $tui->requestRender();

                return null;
            }

            if (($now - $lastBuildAt) < self::REFRESH_INTERVAL_SECONDS) {
                return null;
            }

            try {
                $snap = $provider->build($state->sessionId);
            } catch (\Throwable $e) {
                $logger->warning('Compact header snapshot failed', ['exception' => $e]);
                $lastBuildAt = $now;

                return null;
            }

            if (null !== $lastSnapshot && $lastSnapshot->equals($snap)) {
                $lastBuildAt = $now;

                return null;
            }

            $widget->setSnapshot($snap);
            $lastSnapshot = $snap;
            $lastBuildAt = $now;
            $screen->refreshAboveEditorWidgets();
            $tui->requestRender();

            return null;
        });
    }
}
