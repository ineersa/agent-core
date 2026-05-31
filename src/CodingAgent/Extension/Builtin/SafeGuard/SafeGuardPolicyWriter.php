<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard;

/**
 * Persists "Always allow" patterns to the project's .hatfield/settings.yaml.
 *
 * When the user chooses "Always allow" for a blocked operation, this writer
 * appends the pattern to the appropriate allowlist in the safe_guard settings:
 *
 *   - destructive, dangerous_git, sensitive_info, custom_dangerous → allow_command_patterns
 *   - write_outside → allow_write_outside_cwd
 *   - protected_read → (not persisted; protected read patterns are always additive)
 *
 * Uses Symfony YAML to safely modify the settings file.
 *
 * @internal SafeGuard internal
 */
final readonly class SafeGuardPolicyWriter
{
    /**
     * @param string $settingsPath Absolute path to .hatfield/settings.yaml
     */
    public function __construct(
        private string $settingsPath,
    ) {
    }

    /**
     * Add an allow pattern for the given category.
     *
     * Writes to .hatfield/settings.yaml under:
     *   extensions.settings.safe_guard.{field}
     *
     * Skips if the pattern is already present (idempotent).
     */
    public function addAllowPattern(string $category, string $pattern): void
    {
        $field = $this->categoryToField($category);

        if (null === $field) {
            return;
        }

        $settings = $this->loadSettings();

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
     * @return array<string, mixed>
     */
    private function loadSettings(): array
    {
        if (!file_exists($this->settingsPath)) {
            return [];
        }

        $content = file_get_contents($this->settingsPath);

        if (false === $content || '' === $content) {
            return [];
        }

        try {
            return \Symfony\Component\Yaml\Yaml::parse($content) ?? [];
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Save settings.yaml, preserving existing content.
     *
     * @param array<string, mixed> $settings
     */
    private function saveSettings(array $settings): void
    {
        $dir = \dirname($this->settingsPath);

        if (!is_dir($dir)) {
            // Attempt to create the directory
            if (!@mkdir($dir, 0o755, true) && !is_dir($dir)) {
                return;
            }
        }

        $yaml = \Symfony\Component\Yaml\Yaml::dump($settings, 4, 2);

        // Use atomic write to avoid partial writes
        $tmp = $this->settingsPath.'.tmp.'.getmypid();
        $written = file_put_contents($tmp, $yaml, \LOCK_EX);

        if (false !== $written) {
            rename($tmp, $this->settingsPath);
        }
    }
}
