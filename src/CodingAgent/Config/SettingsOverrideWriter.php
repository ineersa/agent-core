<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Sparse user/project settings mutations only (never defaults).
 *
 * Parse → PropertyAccess mutate → dump. No comment preservation.
 */
final class SettingsOverrideWriter
{
    public function __construct(
        private readonly SettingsPathResolver $pathResolver,
        private readonly PropertyAccessorInterface $propertyAccessor,
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when layer is Defaults or path is invalid
     * @throws \RuntimeException         when the file cannot be read/written or is not a mapping
     */
    public function set(SettingsLayerEnum $layer, string $cwd, string $dottedPath, mixed $value): void
    {
        $this->assertWritableLayer($layer);
        $propertyPath = $this->requirePropertyPath($dottedPath);
        $filePath = $this->layerPath($layer, $cwd);
        $settings = $this->readSettings($filePath);
        $this->propertyAccessor->setValue($settings, $propertyPath, $value);
        $this->writeSettings($filePath, $settings);
    }

    /**
     * Removes one explicit override key. Missing key is a successful no-op.
     *
     * @return bool true when a key was removed
     *
     * @throws \InvalidArgumentException when layer is Defaults or path is invalid
     * @throws \RuntimeException         when the file cannot be read/written or is not a mapping
     */
    public function remove(SettingsLayerEnum $layer, string $cwd, string $dottedPath): bool
    {
        $this->assertWritableLayer($layer);
        $propertyPath = $this->requirePropertyPath($dottedPath);
        $filePath = $this->layerPath($layer, $cwd);
        $settings = $this->readSettings($filePath);

        if (!$this->propertyAccessor->isReadable($settings, $propertyPath)) {
            return false;
        }

        $segments = array_values(array_filter(explode('.', trim($dottedPath)), static fn (string $s): bool => '' !== $s));
        if ([] === $segments) {
            return false;
        }

        // Empty parent maps become PHP [] which AppConfigLoader treats as lists,
        // replacing entire default branches. Prune empty parents bottom-up via
        // PropertyAccessor (no recursive walker).
        $settings = $this->removeWithEmptyParentPrune($settings, $segments);
        $this->writeSettings($filePath, $settings);

        return true;
    }

    /**
     * @param array<string, mixed> $settings
     * @param list<string>         $segments
     *
     * @return array<string, mixed>
     */
    private function removeWithEmptyParentPrune(array $settings, array $segments): array
    {
        for ($depth = \count($segments); $depth >= 1; --$depth) {
            $key = $segments[$depth - 1];
            $parentSegments = \array_slice($segments, 0, $depth - 1);

            if ([] === $parentSegments) {
                unset($settings[$key]);

                // Root-level keys leave the document mapping; no parent prune needed.
                return $settings;
            }

            $parentPath = SettingsValueResolver::propertyPath(implode('.', $parentSegments));
            if (null === $parentPath || !$this->propertyAccessor->isReadable($settings, $parentPath)) {
                return $settings;
            }

            $parent = $this->propertyAccessor->getValue($settings, $parentPath);
            if (!\is_array($parent) || !\array_key_exists($key, $parent)) {
                return $settings;
            }

            // Always write the mutated parent back (PropertyAccess returns a copy).
            unset($parent[$key]);
            $this->propertyAccessor->setValue($settings, $parentPath, $parent);
            /* @var array<string, mixed> $settings */
            if ([] !== $parent) {
                return $settings;
            }

            // Parent is now empty — continue upward so the empty map does not
            // survive as a list-shaped override that wipes defaults.
        }

        return $settings;
    }

    private function assertWritableLayer(SettingsLayerEnum $layer): void
    {
        if (SettingsLayerEnum::Defaults === $layer) {
            throw new \InvalidArgumentException('Built-in defaults are not writable.');
        }
    }

    private function requirePropertyPath(string $dottedPath): string
    {
        $propertyPath = SettingsValueResolver::propertyPath($dottedPath);
        if (null === $propertyPath) {
            throw new \InvalidArgumentException(\sprintf('Invalid settings path "%s".', $dottedPath));
        }

        return $propertyPath;
    }

    private function layerPath(SettingsLayerEnum $layer, string $cwd): string
    {
        return match ($layer) {
            SettingsLayerEnum::User => $this->pathResolver->getHomeDir().'/.hatfield/settings.yaml',
            SettingsLayerEnum::Project => rtrim($cwd, '/').'/.hatfield/settings.yaml',
            SettingsLayerEnum::Defaults => throw new \InvalidArgumentException('Built-in defaults are not writable.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function readSettings(string $filePath): array
    {
        if (!is_file($filePath)) {
            return [];
        }

        if (!is_readable($filePath)) {
            throw new \RuntimeException(\sprintf('Cannot read settings file: %s', $filePath));
        }

        $content = file_get_contents($filePath);
        if (false === $content) {
            throw new \RuntimeException(\sprintf('Cannot read settings file: %s', $filePath));
        }

        if ('' === trim($content)) {
            return [];
        }

        try {
            $parsed = Yaml::parse($content);
        } catch (ParseException $e) {
            throw new \RuntimeException(\sprintf('Cannot parse settings file: %s (%s)', $filePath, $e->getMessage()), 0, $e);
        }

        if (null === $parsed) {
            return [];
        }

        if (!$this->isMapping($parsed)) {
            throw new \RuntimeException(\sprintf('Settings root document must be a mapping in %s; got %s', $filePath, get_debug_type($parsed)));
        }

        /* @var array<string, mixed> $parsed */
        return $parsed;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function writeSettings(string $filePath, array $settings): void
    {
        $yaml = Yaml::dump($settings, 4, 4);
        $this->filesystem->dumpFile($filePath, $yaml);
    }

    private function isMapping(mixed $value): bool
    {
        if (!\is_array($value)) {
            return false;
        }

        return [] === $value || !array_is_list($value);
    }
}
