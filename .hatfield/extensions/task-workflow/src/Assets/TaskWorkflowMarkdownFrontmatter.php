<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Assets;

use Symfony\Component\Yaml\Yaml;

/**
 * Minimal Markdown frontmatter parse helpers for package-local skill install.
 *
 * Host skill discovery ignores unknown keys such as {@code version}; this
 * helper only exists so the extension can compare bundled skill versions.
 */
final class TaskWorkflowMarkdownFrontmatter
{
    /**
     * @return array{frontmatter: array<string, mixed>, body: string}
     */
    public static function parse(string $raw): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $raw);
        if (!str_starts_with($content, "---\n")) {
            return ['frontmatter' => [], 'body' => $content];
        }

        $end = strpos($content, "\n---\n", 4);
        if (false === $end) {
            return ['frontmatter' => [], 'body' => $content];
        }

        $yamlBlock = trim(substr($content, 4, $end - 4));
        $body = substr($content, $end + \strlen("\n---\n"));
        $parsed = '' !== $yamlBlock ? Yaml::parse($yamlBlock) : [];

        return [
            'frontmatter' => \is_array($parsed) ? $parsed : [],
            'body' => $body,
        ];
    }

    public static function versionOf(string $raw): ?string
    {
        $version = self::parse($raw)['frontmatter']['version'] ?? null;
        if (!\is_string($version) && !\is_int($version) && !\is_float($version)) {
            return null;
        }

        $normalized = trim((string) $version);

        return '' === $normalized ? null : $normalized;
    }

    public static function isOutdated(?string $installedVersion, string $bundledVersion): bool
    {
        if (null === $installedVersion || '' === $installedVersion) {
            return true;
        }

        return 0 !== version_compare($installedVersion, $bundledVersion);
    }
}
