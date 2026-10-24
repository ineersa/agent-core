<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads the curated Hatfield-provided extensions catalog from YAML.
 *
 * Catalog entries drive extensions:setup package selection. Built-in SafeGuard
 * has no Composer package; installable entries pin exact versions.
 */
final class ProvidedExtensionsCatalog
{
    /** @var list<ProvidedExtensionDTO>|null */
    private ?array $packages = null;

    public function __construct(
        private readonly string $catalogPath,
    ) {
    }

    /**
     * @return list<ProvidedExtensionDTO>
     */
    public function all(): array
    {
        return $this->packages ??= $this->load();
    }

    /**
     * @return list<ProvidedExtensionDTO>
     */
    public function installable(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ProvidedExtensionDTO $package): bool => !$package->builtin,
        ));
    }

    public function get(string $id): ?ProvidedExtensionDTO
    {
        foreach ($this->all() as $package) {
            if ($package->id === $id) {
                return $package;
            }
        }

        return null;
    }

    /**
     * @return list<ProvidedExtensionDTO>
     */
    private function load(): array
    {
        if (!is_readable($this->catalogPath)) {
            throw new \RuntimeException(\sprintf('Provided extensions catalog is missing or unreadable: %s', $this->catalogPath));
        }

        $content = file_get_contents($this->catalogPath);
        if (false === $content || '' === trim($content)) {
            throw new \RuntimeException(\sprintf('Provided extensions catalog is empty: %s', $this->catalogPath));
        }

        try {
            $parsed = Yaml::parse($content);
        } catch (ParseException $e) {
            throw new \RuntimeException(\sprintf('Provided extensions catalog is invalid YAML (%s): %s', $this->catalogPath, $e->getMessage()), 0, $e);
        }

        if (!\is_array($parsed) || !\array_key_exists('packages', $parsed) || !\is_array($parsed['packages']) || !array_is_list($parsed['packages'])) {
            throw new \RuntimeException(\sprintf('Provided extensions catalog must define a packages list: %s', $this->catalogPath));
        }

        $packages = [];
        $seenIds = [];
        foreach ($parsed['packages'] as $index => $raw) {
            $path = \sprintf('packages[%d]', $index);
            if (!\is_array($raw) || array_is_list($raw)) {
                throw new \RuntimeException(\sprintf('Invalid %s in %s: expected mapping.', $path, $this->catalogPath));
            }

            $id = $this->requireNonEmptyString($raw, 'id', $path);
            if (isset($seenIds[$id])) {
                throw new \RuntimeException(\sprintf('Duplicate provided extension id "%s" in %s.', $id, $this->catalogPath));
            }
            $seenIds[$id] = true;

            $name = $this->requireNonEmptyString($raw, 'name', $path);
            $extensionClass = $this->requireNonEmptyString($raw, 'extension_class', $path);
            $builtin = $this->requireBool($raw, 'builtin', $path);

            $package = null;
            $version = null;
            if ($builtin) {
                if (\array_key_exists('package', $raw) || \array_key_exists('version', $raw)) {
                    throw new \RuntimeException(\sprintf('Builtin entry %s must not declare package/version in %s.', $path, $this->catalogPath));
                }
            } else {
                $package = $this->requireNonEmptyString($raw, 'package', $path);
                $version = $this->requireNonEmptyString($raw, 'version', $path);
            }

            $unknown = array_diff(array_keys($raw), ['id', 'name', 'builtin', 'extension_class', 'package', 'version']);
            if ([] !== $unknown) {
                throw new \RuntimeException(\sprintf('Unknown key "%s" for %s in %s.', reset($unknown), $path, $this->catalogPath));
            }

            $packages[] = new ProvidedExtensionDTO(
                id: $id,
                name: $name,
                builtin: $builtin,
                extensionClass: $extensionClass,
                package: $package,
                version: $version,
            );
        }

        return $packages;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function requireNonEmptyString(array $raw, string $key, string $path): string
    {
        if (!\array_key_exists($key, $raw) || !\is_string($raw[$key]) || '' === trim($raw[$key])) {
            throw new \RuntimeException(\sprintf('Invalid %s.%s in %s: expected non-empty string.', $path, $key, $this->catalogPath));
        }

        return trim($raw[$key]);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function requireBool(array $raw, string $key, string $path): bool
    {
        if (!\array_key_exists($key, $raw) || !\is_bool($raw[$key])) {
            throw new \RuntimeException(\sprintf('Invalid %s.%s in %s: expected bool.', $path, $key, $this->catalogPath));
        }

        return $raw[$key];
    }
}
