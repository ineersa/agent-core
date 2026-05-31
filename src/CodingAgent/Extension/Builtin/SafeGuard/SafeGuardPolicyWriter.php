<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Persists "Always allow" patterns to the project's .hatfield/settings.yaml.
 *
 * When the user chooses "Always allow" for a blocked operation, this writer
 * appends the pattern to the appropriate allowlist in the safe_guard settings:
 *
 *   - destructive, dangerous_git, sensitive_info, custom_dangerous → allow_command_patterns
 *   - write_outside_cwd → allow_write_outside_cwd
 *   - protected_read → (not persisted; protected read patterns are always additive)
 *
 * Uses Symfony YAML to safely modify the settings file with atomic writes.
 *
 * @internal SafeGuard internal
 */
final class SafeGuardPolicyWriter
{
    /**
     * @var string|null last parse error message, set when loadSettings() fails
     */
    private ?string $lastParseError;

    /**
     * @param string $settingsPath Absolute path to .hatfield/settings.yaml
     */
    public function __construct(
        private string $settingsPath,
    ) {
        $this->lastParseError = null;
    }

    /**
     * Add an allow pattern for the given category.
     *
     * Writes to .hatfield/settings.yaml under:
     *   extensions.settings.safe_guard.{field}
     *
     * Skips if the pattern is already present (idempotent).
     * Returns early if the settings file had a parse error on load.
     *
     * @throws \RuntimeException if the settings file cannot be written
     */
    public function addAllowPattern(string $category, string $pattern): void
    {
        $field = $this->categoryToField($category);

        if (null === $field) {
            return;
        }

        $settings = $this->loadSettings();

        // If the settings file had a parse error, do NOT overwrite it
        if (null !== $this->lastParseError) {
            return;
        }

        // Ensure the safe_guard settings path exists
        $safeGuard = &$settings;
        foreach (['extensions', 'settings', 'safe_guard'] as $key) {
            if (!isset($safeGuard[$key])) {
                $safeGuard[$key] = [];
            }
            $safeGuard = &$safeGuard[$key];
        }

        // Ensure the target field exists as a list
        if (!isset($safeGuard[$field])) {
            $safeGuard[$field] = [];
        }

        /** @var list<string> $patterns */
        $patterns = $safeGuard[$field];

        // Skip if already present (idempotent)
        if (\in_array($pattern, $patterns, true)) {
            return;
        }

        $patterns[] = $pattern;
        $safeGuard[$field] = $patterns;

        $this->saveSettings($settings);
    }

    /**
     * Get the last parse error message, if any.
     */
    public function lastParseError(): ?string
    {
        return $this->lastParseError;
    }

    /**
     * Map a SafeGuard decision category to the settings field name.
     *
     * Returns null for categories that are not persisted (e.g., protected_read
     * which is always additive from defaults.yaml).
     */
    private function categoryToField(string $category): ?string
    {
        return match ($category) {
            'destructive', 'dangerous_git', 'sensitive_info', 'custom_dangerous' => 'allow_command_patterns',
            'write_outside_cwd' => 'allow_write_outside_cwd',
            default => null,
        };
    }

    /**
     * Load settings.yaml.
     *
     * On parse failure, stores the error in $lastParseError and returns
     * an empty array to prevent silent data loss on subsequent writes.
     *
     * @return array<string, mixed>
     */
    private function loadSettings(): array
    {
        $this->lastParseError = null;

        if (!file_exists($this->settingsPath)) {
            return [];
        }

        $content = file_get_contents($this->settingsPath);

        if (false === $content || '' === $content) {
            return [];
        }

        try {
            return Yaml::parse($content) ?? [];
        } catch (ParseException $e) {
            $this->lastParseError = \sprintf(
                'Failed to parse %s: %s',
                $this->settingsPath,
                $e->getMessage(),
            );

            return [];
        }
    }

    /**
     * Save settings.yaml with atomic write.
     *
     * Uses temp file + rename to avoid partial writes.
     * Ensures the parent directory exists first.
     *
     * @param array<string, mixed> $settings
     *
     * @throws \RuntimeException if directory creation or file write fails
     */
    private function saveSettings(array $settings): void
    {
        $dir = \dirname($this->settingsPath);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0o755, true) && !is_dir($dir)) {
                throw new \RuntimeException(\sprintf('Failed to create directory for SafeGuard policy file: %s', $dir));
            }
        }

        $yaml = Yaml::dump($settings, 4, 2);

        // Atomic write: write to temp file, then rename
        $tmp = $this->settingsPath.'.tmp.'.getmypid();
        $written = file_put_contents($tmp, $yaml, \LOCK_EX);

        if (false === $written) {
            throw new \RuntimeException(\sprintf('Failed to write SafeGuard policy file: %s', $this->settingsPath));
        }

        if (!rename($tmp, $this->settingsPath)) {
            @unlink($tmp);

            throw new \RuntimeException(\sprintf('Failed to atomically replace SafeGuard policy file: %s', $this->settingsPath));
        }
    }
}
