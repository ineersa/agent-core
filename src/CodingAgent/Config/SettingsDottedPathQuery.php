<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Dotted-path provenance against raw layers and merged effective config.
 */
final class SettingsDottedPathQuery
{
    /**
     * @param array<string, mixed> $defaultsRaw
     * @param array<string, mixed> $homeRaw
     * @param array<string, mixed> $projectRaw
     * @param array<string, mixed> $effective
     */
    public static function query(
        array $defaultsRaw,
        array $homeRaw,
        array $projectRaw,
        array $effective,
        string $dottedPath,
    ): SettingsValueDTO {
        $segments = self::parsePath($dottedPath);
        if ([] === $segments) {
            return new SettingsValueDTO(exists: false);
        }

        if (!self::pathExists($effective, $segments)) {
            return new SettingsValueDTO(exists: false);
        }

        $value = self::getAtPath($effective, $segments);

        if (self::pathExists($projectRaw, $segments)) {
            return new SettingsValueDTO(exists: true, value: $value, layer: SettingsLayerEnum::Project);
        }

        if (self::pathExists($homeRaw, $segments)) {
            return new SettingsValueDTO(exists: true, value: $value, layer: SettingsLayerEnum::Home);
        }

        if (self::pathExists($defaultsRaw, $segments)) {
            return new SettingsValueDTO(exists: true, value: $value, layer: SettingsLayerEnum::Defaults);
        }

        return new SettingsValueDTO(exists: true, value: $value, layer: null);
    }

    /**
     * @return list<string>
     */
    private static function parsePath(string $dottedPath): array
    {
        $dottedPath = trim($dottedPath);
        if ('' === $dottedPath) {
            return [];
        }

        return array_values(array_filter(explode('.', $dottedPath), static fn (string $s): bool => '' !== $s));
    }

    /**
     * @param list<string>         $segments
     * @param array<string, mixed> $data
     */
    private static function pathExists(array $data, array $segments): bool
    {
        $current = $data;
        foreach ($segments as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return false;
            }
            $current = $current[$segment];
        }

        return true;
    }

    /**
     * @param list<string>         $segments
     * @param array<string, mixed> $data
     */
    private static function getAtPath(array $data, array $segments): mixed
    {
        $current = $data;
        foreach ($segments as $segment) {
            if (!\is_array($current) || !\array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
