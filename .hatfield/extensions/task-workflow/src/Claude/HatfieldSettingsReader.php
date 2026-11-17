<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Claude;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads extension settings from the same files Hatfield uses, so one
 * committed configuration serves both agents.
 *
 * Layering matches Hatfield's AppConfigLoader: user `~/.hatfield/settings.yaml`
 * < project `<cwd>/.hatfield/settings.yaml`; maps merge per key and a higher
 * layer's list replaces the lower list whole. Missing files contribute nothing.
 */
final readonly class HatfieldSettingsReader
{
    public function __construct(private ?string $home)
    {
    }

    /** @return array<string, array<string, mixed>> */
    public function extensionSettings(string $cwd): array
    {
        $merged = [];
        $files = null === $this->home || '' === $this->home ? [] : [$this->home.'/.hatfield/settings.yaml'];
        $files[] = rtrim($cwd, '/').'/.hatfield/settings.yaml';
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $settings = Yaml::parseFile($file)['extensions']['settings'] ?? [];
            if (\is_array($settings)) {
                $merged = self::overlay($merged, $settings);
            }
        }

        return $merged;
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $overlay
     *
     * @return array<array-key, mixed>
     */
    private static function overlay(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            $current = $base[$key] ?? null;
            $base[$key] = \is_array($value) && !array_is_list($value) && \is_array($current) && !array_is_list($current)
                ? self::overlay($current, $value)
                : $value;
        }

        return $base;
    }
}
