<?php

declare(strict_types=1);

namespace Ineersa\Tui\Theme;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceConflictDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceItemDTO;
use Ineersa\CodingAgent\Runtime\Contract\ThemeLoadedResourcesProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Exception\InvalidArgumentException;
use Symfony\Component\Tui\Style\Color;
use Symfony\Component\Yaml\Yaml;

/**
 * Registry of built-in and user-loaded TUI themes.
 *
 * Self-loads palettes from config/hatfield theme paths at construction.
 * User-configured theme paths override built-in themes of the same name.
 *
 * Color specs are validated once at load: an entry Symfony TUI cannot parse is
 * logged and dropped, so it renders with the default terminal foreground and
 * render code can pass palette specs to a Style without guarding.
 *
 * The default theme name is driven by application config
 * ({@see config/hatfield.defaults.yaml}) — this registry itself carries
 * no opinion about which theme is "default".
 */
final class ThemeRegistry implements ThemeLoadedResourcesProviderInterface
{
    /** @var array<string, ThemePalette> */
    private array $themes = [];

    /** @var array<string, ThemeLoadedEntryDTO> */
    private array $loadedByName = [];

    /** @var list<array{name: string, winnerPath: string, loserPath: string}> */
    private array $themeCollisions = [];

    public function __construct(
        AppConfig $appConfig,
        AppResourceLocator $resources,
        private readonly LoggerInterface $logger,
    ) {
        $tuiConfig = $appConfig->tui;

        // Load user-configured theme paths first (higher priority — they
        // override built-in themes with the same name).
        foreach ($tuiConfig->themePaths as $path) {
            $this->ingestDirectory($path);
        }

        // Load built-in themes second (lower priority — only fills gaps).
        $builtinPath = $resources->getBuiltinThemesPath();
        $this->ingestDirectory($builtinPath);
    }

    /**
     * @return list<ThemeLoadedEntryDTO>
     */
    public function getLoadedThemes(): array
    {
        $entries = array_values($this->loadedByName);
        usort($entries, static fn (ThemeLoadedEntryDTO $a, ThemeLoadedEntryDTO $b): int => strcmp($a->name, $b->name));

        return $entries;
    }

    /**
     * @return list<array{name: string, winnerPath: string, loserPath: string}>
     */
    public function getThemeCollisions(): array
    {
        return $this->themeCollisions;
    }

    public function getLoadedThemeResourceItems(): array
    {
        $items = [];
        foreach ($this->getLoadedThemes() as $theme) {
            $items[] = new LoadedResourceItemDTO(
                name: $theme->name,
                sourcePath: $theme->sourcePath,
            );
        }

        return $items;
    }

    public function getThemeResourceConflicts(): array
    {
        $conflicts = [];
        foreach ($this->getThemeCollisions() as $collision) {
            $conflicts[] = new LoadedResourceConflictDTO(
                name: $collision['name'],
                winnerPath: $collision['winnerPath'],
                loserPath: $collision['loserPath'],
            );
        }

        return $conflicts;
    }

    /**
     * Look up a palette by name, throwing if not found.
     *
     * Used by the theme factory when the name is driven by config
     * and a missing theme is a configuration error.
     *
     * @throws \RuntimeException if the theme is not registered
     */
    public function getOrThrow(string $name): ThemePalette
    {
        $palette = $this->themes[$name] ?? null;
        if (null === $palette) {
            $allNames = $this->getNames();
            $names = [] !== $allNames ? implode(', ', $allNames) : '(none)';

            throw new \RuntimeException(\sprintf('Theme "%s" is not registered. Available themes: %s.', $name, $names));
        }

        return $palette;
    }

    /**
     * Return all registered theme names.
     *
     * @return list<string>
     */
    public function getNames(): array
    {
        $names = array_keys($this->themes);
        sort($names);

        return $names;
    }

    private function ingestDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            if (!str_ends_with($entry, '.yaml') && !str_ends_with($entry, '.yml')) {
                continue;
            }

            $file = $dir.'/'.$entry;
            if (!is_file($file)) {
                continue;
            }

            try {
                $palette = $this->loadFile($file);
            } catch (\RuntimeException $e) {
                $this->logger->warning('Skipping unparseable theme file', [
                    'file' => $file,
                    'exception' => $e,
                ]);

                continue;
            }

            $this->registerPalette($palette, $file);
        }
    }

    private function registerPalette(ThemePalette $palette, string $sourcePath): void
    {
        $name = $palette->name;
        if (isset($this->themes[$name])) {
            $winner = $this->loadedByName[$name];
            if ($this->themeSourcePathsEqual($winner->sourcePath, $sourcePath)) {
                return;
            }

            $this->themeCollisions[] = [
                'name' => $name,
                'winnerPath' => $winner->sourcePath,
                'loserPath' => $sourcePath,
            ];

            return;
        }

        $this->themes[$name] = $palette;
        $this->loadedByName[$name] = new ThemeLoadedEntryDTO($name, $sourcePath);
    }

    /**
     * True when two theme file paths refer to the same on-disk file.
     *
     * Used to skip bogus collisions when built-in themes are ingested from both
     * configured theme_paths and the builtin directory (same YAML file).
     */
    private function themeSourcePathsEqual(string $a, string $b): bool
    {
        if ('' === $a || '' === $b) {
            return $a === $b;
        }

        $realA = realpath($a);
        $realB = realpath($b);
        if (false !== $realA && false !== $realB) {
            return $realA === $realB;
        }

        return $a === $b;
    }

    /**
     * Load a palette from a YAML file path.
     *
     * @throws \RuntimeException if the file is not readable or parseable
     */
    private function loadFile(string $path): ThemePalette
    {
        if (!is_readable($path)) {
            throw new \RuntimeException("Theme file not readable: {$path}");
        }

        $yaml = file_get_contents($path);
        if (false === $yaml) {
            throw new \RuntimeException("Failed to read theme file: {$path}");
        }

        $data = Yaml::parse($yaml);
        if (!\is_array($data)) {
            throw new \RuntimeException("Invalid YAML structure in theme file: {$path}");
        }

        foreach (['vars', 'colors'] as $section) {
            if (!\is_array($data[$section] ?? null)) {
                continue;
            }
            foreach ($data[$section] as $key => $value) {
                if (!\is_string($value)) {
                    $this->logInvalidColor($path, $section, (string) $key, 'Expected a string, got '.get_debug_type($value).'.');
                    unset($data[$section][$key]);
                }
            }
        }

        return $this->withoutInvalidColors(ThemePalette::fromArray($data), $path);
    }

    private function withoutInvalidColors(ThemePalette $palette, string $path): ThemePalette
    {
        $colors = $palette->colors;
        foreach ($colors as $token => $spec) {
            if ('' === $spec) {
                continue;
            }

            try {
                Color::from($spec);
            } catch (InvalidArgumentException $e) {
                // Intentional degradation: the token renders unstyled instead of
                // throwing when a Style is built from it.
                $this->logInvalidColor($path, 'colors', $token, $e->getMessage());
                unset($colors[$token]);
            }
        }

        return new ThemePalette($palette->name, $colors);
    }

    private function logInvalidColor(string $path, string $section, string $key, string $reason): void
    {
        $this->logger->warning('tui.theme.invalid_color', [
            'component' => 'tui_theme',
            'event_type' => 'tui.theme.invalid_color',
            'file' => $path,
            'section' => $section,
            'key' => $key,
            'reason' => $reason,
        ]);
    }
}
