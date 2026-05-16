<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Config\AppConfigResolver;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeLoader;
use Ineersa\Tui\Theme\ThemeRegistry;
use Ineersa\Tui\Theme\TuiTheme;

/**
 * Creates the active TuiTheme from Hatfield config.
 *
 * Extracted from InteractiveMode so theme construction is:
 *   - independently testable
 *   - reusable outside the interactive run loop
 *   - not repeated on every run() call via inline method
 */
final class ThemeFactory
{
    public function __construct(
        private readonly AppConfigResolver $configResolver,
    ) {
    }

    /**
     * Resolve and build the theme for a given project directory.
     *
     * @param string        $cwd  Project working directory
     * @param TuiTheme|null $hint Pre-resolved theme (skips config lookup)
     */
    public function create(string $cwd, ?TuiTheme $hint = null): TuiTheme
    {
        if (null !== $hint) {
            return $hint;
        }

        $appConfig = $this->configResolver->resolve($cwd);

        return $this->buildTheme($appConfig->tui->theme, $appConfig->tui->themePaths);
    }

    /**
     * Build a theme from explicit name and search paths.
     *
     * @param string       $name  Theme name from config
     * @param list<string> $paths Theme search directories
     */
    public function buildTheme(string $name, array $paths): TuiTheme
    {
        $loader = new ThemeLoader();

        $allPalettes = [];
        foreach ($paths as $path) {
            $palettes = $loader->loadDirectory($path);
            foreach ($palettes as $palette) {
                if (!isset($allPalettes[$palette->name])) {
                    $allPalettes[$palette->name] = $palette;
                }
            }
        }

        $builtinPath = \dirname(__DIR__, 3).'/config/themes';
        $builtins = $loader->loadDirectory($builtinPath);
        foreach ($builtins as $palette) {
            if (!isset($allPalettes[$palette->name])) {
                $allPalettes[$palette->name] = $palette;
            }
        }

        $registry = new ThemeRegistry(
            builtin: array_values($allPalettes),
        );

        return new DefaultTheme($registry->getOrThrow($name));
    }
}
