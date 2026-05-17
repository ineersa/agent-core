<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeRegistry;
use Ineersa\Tui\Theme\TuiTheme;

/**
 * Creates the active TuiTheme from Hatfield config.
 *
 * Thin wrapper around {@see ThemeRegistry} — the registry handles all
 * palette loading and lookup. This factory only wraps the result in a
 * {@see DefaultTheme} renderer.
 */
final class ThemeFactory
{
    public function __construct(
        private readonly AppConfig $appConfig,
        private readonly ThemeRegistry $registry,
    ) {
    }

    /**
     * Resolve and build the theme.
     *
     * @param TuiTheme|null $hint Pre-resolved theme (skips config lookup)
     */
    public function create(?TuiTheme $hint = null): TuiTheme
    {
        if (null !== $hint) {
            return $hint;
        }

        return new DefaultTheme($this->registry->getOrThrow($this->appConfig->tui->theme));
    }
}
