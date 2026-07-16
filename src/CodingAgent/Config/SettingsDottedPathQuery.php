<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Dotted-path provenance against raw layers and merged effective config.
 *
 * Terminal paths (scalar, null, sequential list) report the winning layer.
 * Non-list associative maps are composite: they exist in effective config but
 * may mix child provenance, so no single layer is claimed.
 */
final class SettingsDottedPathQuery
{
    /**
     * @param array<string, mixed> $defaultsRaw
     * @param array<string, mixed> $userRaw
     * @param array<string, mixed> $projectRaw
     * @param array<string, mixed> $effective
     */
    public static function query(
        array $defaultsRaw,
        array $userRaw,
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

        if (\is_array($value) && self::isAssoc($value)) {
            return new SettingsValueDTO(
                exists: true,
                value: $value,
                layer: null,
                composite: true,
            );
        }

        if (self::pathExists($projectRaw, $segments)) {
            return new SettingsValueDTO(exists: true, value: $value, layer: SettingsLayerEnum::Project);
        }

        if (self::pathExists($userRaw, $segments)) {
            return new SettingsValueDTO(exists: true, value: $value, layer: SettingsLayerEnum::User);
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

        $segments = [];
        foreach (explode('.', $dottedPath) as $segment) {
            if ('' === $segment) {
                continue;
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $segment)) {
                return [];
            }
            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * @param array<mixed> $arr
     */
    private static function isAssoc(array $arr): bool
    {
        if ([] === $arr) {
            return false;
        }

        return array_keys($arr) !== range(0, \count($arr) - 1);
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
