<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\Tui\Footer\ContextUsageFormatter;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Listener registrar that initialises the TUI footer state and registers
 * a live segment provider.
 *
 * On registration:
 *  - FooterStateInitializer seeds model/reasoning/context/cwd/branch/time
 *  - A FooterStateSegmentProvider is registered on the chat screen
 *  - A tick handler refreshes only the footer so elapsed time stays live
 *    without invalidating transcript/editor on every stream tick
 */
final readonly class FooterStateListener implements TuiListenerRegistrar
{
    public function __construct(
        private FooterStateInitializer $initializer,
        private readonly AppConfig $appConfig,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $this->initializer->initialize($context->state);

        $footerProvider = new FooterStateSegmentProvider(
            $context->state,
            new ContextUsageFormatter($this->appConfig),
        );
        $context->screen->addFooterProvider($footerProvider);

        $screen = $context->screen;
        $tui = $context->tui;
        $lastFooterFingerprint = null;
        $context->ticks->add(static function () use ($screen, $tui, $footerProvider, &$lastFooterFingerprint): ?bool {
            $fingerprint = $footerProvider->footerFingerprint();
            if ($fingerprint === $lastFooterFingerprint) {
                return null;
            }

            $lastFooterFingerprint = $fingerprint;
            $screen->refreshFooter();
            $tui->requestRender();

            return null;
        });

        // Apply initial editor border colour matching the default reasoning
        // level.  This must happen after FooterStateInitializer seeds
        // $state->footerReasoning.
        if ('' !== $context->state->footerReasoning) {
            $context->screen->applyEditorBorderColor($context->state->footerReasoning);
        }
    }
}
