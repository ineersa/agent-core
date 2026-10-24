<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\DTO\ProvidedExtensionDTO;
use Ineersa\CodingAgent\Config\ProvidedExtensionsCatalog;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\SettingsResolutionDTO;
use Ineersa\CodingAgent\Extension\Exception\ExtensionPackageInstallException;
use Ineersa\CodingAgent\Extension\ExtensionPackageInstaller;
use Ineersa\Tui\Setup\ExtensionsSetupFlowInterface;

/**
 * Headless extensions:setup mutations. Owns scoped install + sparse settings
 * writes; the TUI screen drives I/O against {@see ExtensionsSetupFlowInterface}.
 */
final class ExtensionsSetupFlow implements ExtensionsSetupFlowInterface
{
    private const SAFE_GUARD_ID = 'safe-guard';
    private const TASK_WORKFLOW_ID = 'task-workflow';
    private const FILE_REWIND_ID = 'file-rewind';
    private const OM_ID = 'observational-memory';

    /** @var list<string> */
    private array $summary = [];

    private bool $wroteSomething = false;

    public function __construct(
        private readonly ProvidedExtensionsCatalog $catalog,
        private readonly ExtensionPackageInstaller $installer,
        private readonly AppConfigLoader $configLoader,
        private readonly AppResourceLocator $resourceLocator,
        private readonly SettingsOverrideWriter $settingsWriter,
        private readonly SettingsPathResolver $pathResolver,
        private readonly SettingsLayerEnum $layer,
        private readonly string $cwd,
    ) {
        if (SettingsLayerEnum::Defaults === $this->layer) {
            throw new \InvalidArgumentException('Built-in defaults are not an extensions:setup target.');
        }
    }

    public static function for(
        ProvidedExtensionsCatalog $catalog,
        ExtensionPackageInstaller $installer,
        AppConfigLoader $configLoader,
        AppResourceLocator $resourceLocator,
        SettingsOverrideWriter $settingsWriter,
        SettingsPathResolver $pathResolver,
        AppConfig $appConfig,
        bool $project = true,
    ): self {
        $layer = $project ? SettingsLayerEnum::Project : SettingsLayerEnum::User;
        $cwd = '' !== $appConfig->cwd ? $appConfig->cwd : ((string) getcwd());
        if ('' === $cwd) {
            $cwd = '.';
        }

        return new self(
            $catalog,
            $installer,
            $configLoader,
            $resourceLocator,
            $settingsWriter,
            $pathResolver,
            $layer,
            $cwd,
        );
    }

    public function extensionRows(): array
    {
        $resolution = $this->resolve();
        $rows = [];
        foreach ($this->catalog->all() as $extension) {
            $parentEnabled = $this->classEnabled($resolution, $extension->extensionClass);
            $installAvailable = $this->isInstallAvailable($extension);
            $needsInstall = !$extension->builtin && !$installAvailable;
            $rows[] = [
                'id' => $extension->id,
                'name' => $extension->name,
                'builtin' => $extension->builtin,
                'status' => $this->statusLabel($extension, $parentEnabled, $installAvailable),
                'package' => $extension->package,
                'version' => $extension->version,
                'extensionClass' => $extension->extensionClass,
                'parentEnabled' => $parentEnabled,
                'installAvailable' => $installAvailable,
                'needsInstall' => $needsInstall,
            ];
        }

        return $rows;
    }

    public function scopeLabel(): string
    {
        return SettingsLayerEnum::Project === $this->layer
            ? 'project (.hatfield/settings.yaml)'
            : 'user (~/.hatfield/settings.yaml)';
    }

    public function settingsPath(): string
    {
        return SettingsLayerEnum::Project === $this->layer
            ? rtrim($this->cwd, '/').'/.hatfield/settings.yaml'
            : rtrim($this->pathResolver->getHomeDir(), '/').'/.hatfield/settings.yaml';
    }

    public function wroteSomething(): bool
    {
        return $this->wroteSomething;
    }

    public function summaryLines(): array
    {
        return $this->summary;
    }

    public function formStateFor(string $id): array
    {
        $extension = $this->requireCatalogEntry($id);
        $resolution = $this->resolve();
        $source = $this->prefillSource($resolution);
        $settingsKey = $this->settingsKeyFor($extension->id);
        $settings = $this->guidedSettingsPrefill($extension->id, $resolution, $settingsKey);

        return [
            'id' => $extension->id,
            'name' => $extension->name,
            'builtin' => $extension->builtin,
            'extensionClass' => $extension->extensionClass,
            'package' => $extension->package,
            'version' => $extension->version,
            'needsInstall' => !$extension->builtin && !$this->isInstallAvailable($extension),
            'parentEnabled' => $this->classEnabled($resolution, $extension->extensionClass),
            'forksAlwaysOn' => $this->classInList($this->listAt($source, ['forks', 'extensions', 'always_on']), $extension->extensionClass),
            'forksEnabled' => $this->classInList($this->listAt($source, ['forks', 'extensions', 'enabled']), $extension->extensionClass),
            'agentsAlwaysOn' => $this->classInList($this->listAt($source, ['agents', 'extensions', 'always_on']), $extension->extensionClass),
            'settings' => $settings,
        ];
    }

    public function needsInstallConfirmation(string $id): bool
    {
        $extension = $this->requireCatalogEntry($id);
        if ($extension->builtin) {
            return false;
        }

        return !$this->isInstallAvailable($extension);
    }

    public function install(string $id): void
    {
        $extension = $this->requireCatalogEntry($id);
        if ($extension->builtin) {
            throw new \InvalidArgumentException(\sprintf('%s is built into Hatfield and does not need Composer.', $extension->name));
        }
        if (null === $extension->package || null === $extension->version) {
            throw new \InvalidArgumentException(\sprintf('Catalog entry "%s" is missing package/version.', $extension->id));
        }

        try {
            $result = $this->installer->ensureInstalled(
                $this->layer,
                $this->cwd,
                $extension->package,
                $extension->version,
            );
        } catch (ExtensionPackageInstallException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $this->summary[] = $result->alreadyInstalled
            ? \sprintf('Reused installed package %s in %s.', $extension->package, $result->root)
            : \sprintf('Installed %s:%s into %s.', $extension->package, $extension->version, $result->root);
    }

    public function save(array $formState): void
    {
        $id = $this->requireString($formState, 'id');
        $extension = $this->requireCatalogEntry($id);
        $class = $extension->extensionClass;
        $parentEnabled = $this->requireBool($formState, 'parentEnabled');
        $forksAlwaysOn = $this->requireBool($formState, 'forksAlwaysOn');
        $forksEnabled = $this->requireBool($formState, 'forksEnabled');
        $agentsAlwaysOn = $this->requireBool($formState, 'agentsAlwaysOn');
        $settings = \is_array($formState['settings'] ?? null) ? $formState['settings'] : [];

        if (($forksAlwaysOn || $forksEnabled || $agentsAlwaysOn) && !$parentEnabled) {
            throw new \InvalidArgumentException('Child selections require the extension to be enabled for the parent (extensions.enabled). Enable it, or clear forks/agents membership first.');
        }

        if (!$extension->builtin && !$this->isInstallAvailable($extension)) {
            throw new \InvalidArgumentException(\sprintf('%s is not installed for this scope yet. Confirm Composer installation before saving.', $extension->name));
        }

        $guided = $this->normalizeGuidedSettings($extension->id, $settings);
        $resolution = $this->resolve();
        $layerRaw = $this->layerRaw($resolution);
        $source = $this->prefillSource($resolution);

        $enabled = $this->listAt($source, ['extensions', 'enabled']);
        $enabled = $parentEnabled
            ? $this->ensureClassInList($enabled, $class)
            : $this->removeClassFromList($enabled, $class);

        $forksAlways = $this->listAt($source, ['forks', 'extensions', 'always_on']);
        $forksAlways = $forksAlwaysOn
            ? $this->ensureClassInList($forksAlways, $class)
            : $this->removeClassFromList($forksAlways, $class);

        $forksOptional = $this->listAt($source, ['forks', 'extensions', 'enabled']);
        $forksOptional = $forksEnabled
            ? $this->ensureClassInList($forksOptional, $class)
            : $this->removeClassFromList($forksOptional, $class);

        $agentsAlways = $this->listAt($source, ['agents', 'extensions', 'always_on']);
        $agentsAlways = $agentsAlwaysOn
            ? $this->ensureClassInList($agentsAlways, $class)
            : $this->removeClassFromList($agentsAlways, $class);

        $settingsBag = \is_array($layerRaw['extensions']['settings'] ?? null)
            ? $layerRaw['extensions']['settings']
            : [];
        if ([] !== $settingsBag && array_is_list($settingsBag)) {
            throw new \InvalidArgumentException('extensions.settings must be a mapping.');
        }

        $settingsKey = $this->settingsKeyFor($extension->id);
        $existingSection = \is_array($settingsBag[$settingsKey] ?? null) ? $settingsBag[$settingsKey] : [];
        if ([] !== $existingSection && array_is_list($existingSection)) {
            throw new \InvalidArgumentException(\sprintf('extensions.settings.%s must be a mapping.', $settingsKey));
        }
        $settingsBag[$settingsKey] = $this->configLoader->overlayConfig($existingSection, $guided);

        // Preserve unrelated keys by rewriting only the known sparse branches.
        $this->settingsWriter->set($this->layer, $this->cwd, 'extensions.enabled', $enabled);
        $this->settingsWriter->set($this->layer, $this->cwd, 'extensions.settings.'.$settingsKey, $settingsBag[$settingsKey]);
        $this->settingsWriter->set($this->layer, $this->cwd, 'forks.extensions.always_on', $forksAlways);
        $this->settingsWriter->set($this->layer, $this->cwd, 'forks.extensions.enabled', $forksOptional);
        $this->settingsWriter->set($this->layer, $this->cwd, 'agents.extensions.always_on', $agentsAlways);

        $this->wroteSomething = true;
        $this->summary[] = \sprintf(
            'Saved %s to %s (%s). Restart Hatfield so registration picks up the change. Optional per-agent frontmatter extensions remain separate from agents.extensions.always_on.',
            $extension->name,
            $this->settingsPath(),
            $parentEnabled ? 'enabled' : 'disabled',
        );
    }

    public function enable(string $id): void
    {
        $extension = $this->requireCatalogEntry($id);
        if (!$extension->builtin && !$this->isInstallAvailable($extension)) {
            throw new \InvalidArgumentException(\sprintf('%s is not installed for this scope yet. Install or configure it first.', $extension->name));
        }

        $resolution = $this->resolve();
        $enabled = $this->ensureClassInList(
            $this->listAt($this->prefillSource($resolution), ['extensions', 'enabled']),
            $extension->extensionClass,
        );
        $this->settingsWriter->set($this->layer, $this->cwd, 'extensions.enabled', $enabled);
        $this->wroteSomething = true;
        $this->summary[] = \sprintf('Enabled %s in %s. Restart Hatfield to load it.', $extension->name, $this->settingsPath());
    }

    public function disable(string $id): void
    {
        $extension = $this->requireCatalogEntry($id);
        $resolution = $this->resolve();
        $enabled = $this->removeClassFromList(
            $this->listAt($this->prefillSource($resolution), ['extensions', 'enabled']),
            $extension->extensionClass,
        );
        $this->settingsWriter->set($this->layer, $this->cwd, 'extensions.enabled', $enabled);
        $this->wroteSomething = true;
        $this->summary[] = \sprintf('Disabled %s in %s. Restart Hatfield so it unloads.', $extension->name, $this->settingsPath());
    }

    private function resolve(): SettingsResolutionDTO
    {
        return $this->configLoader->load($this->resourceLocator->getDefaultsPath(), $this->cwd);
    }

    /**
     * Prefill uses the selected layer's effective view:
     *  - project = defaults < user < project
     *  - user = defaults < user (project overrides are excluded)
     *
     * @return array<string, mixed>
     */
    private function prefillSource(SettingsResolutionDTO $resolution): array
    {
        if (SettingsLayerEnum::Project === $this->layer) {
            return $resolution->effective;
        }

        return $this->configLoader->overlayConfig($resolution->defaultsRaw, $resolution->userRaw);
    }

    /**
     * @return array<string, mixed>
     */
    private function layerRaw(SettingsResolutionDTO $resolution): array
    {
        return SettingsLayerEnum::Project === $this->layer
            ? $resolution->projectRaw
            : $resolution->userRaw;
    }

    private function isInstallAvailable(ProvidedExtensionDTO $extension): bool
    {
        if ($extension->builtin || null === $extension->package) {
            return true;
        }

        if ($this->installer->isInstalled($this->layer, $this->cwd, $extension->package)) {
            return true;
        }

        // Project setup may reuse a user-installed package. User setup may not
        // rely on the current project's package root.
        if (SettingsLayerEnum::Project === $this->layer) {
            return $this->installer->isInstalled(SettingsLayerEnum::User, $this->cwd, $extension->package);
        }

        return false;
    }

    private function statusLabel(ProvidedExtensionDTO $extension, bool $parentEnabled, bool $installAvailable): string
    {
        if ($extension->builtin) {
            return $parentEnabled ? '✓ enabled (built-in)' : '✗ disabled (built-in)';
        }
        if (!$installAvailable) {
            return 'needs Composer install';
        }

        return $parentEnabled ? '✓ enabled' : 'installed · disabled';
    }

    private function requireCatalogEntry(string $id): ProvidedExtensionDTO
    {
        $extension = $this->catalog->get($id);
        if (null === $extension) {
            throw new \InvalidArgumentException(\sprintf('Unknown provided extension "%s".', $id));
        }

        return $extension;
    }

    private function settingsKeyFor(string $id): string
    {
        return match ($id) {
            self::SAFE_GUARD_ID => 'safe_guard',
            self::TASK_WORKFLOW_ID => 'task_workflow',
            self::FILE_REWIND_ID => 'file_rewind',
            self::OM_ID => 'observational_memory',
            default => throw new \InvalidArgumentException(\sprintf('No guided settings key for "%s".', $id)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function guidedSettingsPrefill(string $id, SettingsResolutionDTO $resolution, string $settingsKey): array
    {
        $source = $this->prefillSource($resolution);
        $section = $source['extensions']['settings'][$settingsKey] ?? [];
        if (!\is_array($section) || ([] !== $section && array_is_list($section))) {
            $section = [];
        }

        return match ($id) {
            self::SAFE_GUARD_ID => [
                'allow_command_patterns' => $this->stringList($section['allow_command_patterns'] ?? []),
                'allow_write_outside_cwd' => $this->stringList($section['allow_write_outside_cwd'] ?? []),
                'protected_read_patterns' => $this->stringList($section['protected_read_patterns'] ?? []),
                'dangerous_command_patterns' => $this->stringList($section['dangerous_command_patterns'] ?? []),
                'auto_deny_in_noninteractive' => (bool) ($section['auto_deny_in_noninteractive'] ?? true),
            ],
            self::TASK_WORKFLOW_ID => [
                'task_root' => \is_string($section['task_root'] ?? null) ? $section['task_root'] : '',
            ],
            self::FILE_REWIND_ID => [
                'enabled' => (bool) ($section['enabled'] ?? true),
                'max_retained_turns' => max(1, (int) ($section['max_retained_turns'] ?? 100)),
                'git_timeout_seconds' => max(1, (int) ($section['git_timeout_seconds'] ?? 30)),
            ],
            self::OM_ID => [
                'model' => \is_string($section['model'] ?? null) ? $section['model'] : '',
                'storage' => [
                    'database' => \is_array($section['storage'] ?? null) && \is_string($section['storage']['database'] ?? null)
                        ? $section['storage']['database']
                        : '.hatfield/extensions-data/observational-memory/om.sqlite',
                ],
                'observer' => [
                    'context_window_ratio' => $this->ratio(
                        \is_array($section['observer'] ?? null) ? ($section['observer']['context_window_ratio'] ?? null) : null,
                        0.65,
                    ),
                ],
                'reflector' => [
                    'reflect_after_observation_tokens' => max(
                        1,
                        (int) (\is_array($section['reflector'] ?? null)
                            ? ($section['reflector']['reflect_after_observation_tokens'] ?? 40000)
                            : 40000),
                    ),
                ],
                'pools' => [
                    'observations_max_tokens' => max(
                        1,
                        (int) (\is_array($section['pools'] ?? null)
                            ? ($section['pools']['observations_max_tokens'] ?? 30000)
                            : 30000),
                    ),
                ],
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function normalizeGuidedSettings(string $id, array $settings): array
    {
        return match ($id) {
            self::SAFE_GUARD_ID => [
                'allow_command_patterns' => $this->parseCsvList($settings['allow_command_patterns'] ?? []),
                'allow_write_outside_cwd' => $this->parseAbsolutePathList($settings['allow_write_outside_cwd'] ?? []),
                'protected_read_patterns' => $this->parseCsvList($settings['protected_read_patterns'] ?? []),
                'dangerous_command_patterns' => $this->parseCsvList($settings['dangerous_command_patterns'] ?? []),
                'auto_deny_in_noninteractive' => $this->boolish($settings['auto_deny_in_noninteractive'] ?? true, 'auto_deny_in_noninteractive'),
            ],
            self::TASK_WORKFLOW_ID => [
                'task_root' => $this->optionalPath($settings['task_root'] ?? ''),
            ],
            self::FILE_REWIND_ID => [
                'enabled' => $this->boolish($settings['enabled'] ?? true, 'file_rewind.enabled'),
                'max_retained_turns' => $this->positiveInt($settings['max_retained_turns'] ?? 100, 'max_retained_turns'),
                'git_timeout_seconds' => $this->positiveInt($settings['git_timeout_seconds'] ?? 30, 'git_timeout_seconds'),
            ],
            self::OM_ID => $this->normalizeOmSettings($settings),
            default => throw new \InvalidArgumentException(\sprintf('No guided settings normalizer for "%s".', $id)),
        };
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function normalizeOmSettings(array $settings): array
    {
        $model = trim((string) ($settings['model'] ?? ''));
        if ('' === $model || !str_contains($model, '/')) {
            throw new \InvalidArgumentException('observational_memory.model must be an exact provider/model id (example: llama_cpp/flash).');
        }

        $database = trim((string) (
            \is_array($settings['storage'] ?? null)
                ? ($settings['storage']['database'] ?? '')
                : ($settings['database'] ?? '')
        ));
        if ('' === $database) {
            throw new \InvalidArgumentException('observational_memory.storage.database must be a non-empty path.');
        }

        $observerRatio = $this->ratio(
            \is_array($settings['observer'] ?? null)
                ? ($settings['observer']['context_window_ratio'] ?? ($settings['context_window_ratio'] ?? null))
                : ($settings['context_window_ratio'] ?? null),
            0.65,
        );
        if ($observerRatio <= 0.0 || $observerRatio > 1.0) {
            throw new \InvalidArgumentException('observer.context_window_ratio must be between 0 exclusive and 1 inclusive.');
        }

        return [
            'model' => $model,
            'storage' => ['database' => $database],
            'observer' => ['context_window_ratio' => $observerRatio],
            'reflector' => [
                'reflect_after_observation_tokens' => $this->positiveInt(
                    \is_array($settings['reflector'] ?? null)
                        ? ($settings['reflector']['reflect_after_observation_tokens'] ?? ($settings['reflect_after_observation_tokens'] ?? 40000))
                        : ($settings['reflect_after_observation_tokens'] ?? 40000),
                    'reflect_after_observation_tokens',
                ),
            ],
            'pools' => [
                'observations_max_tokens' => $this->positiveInt(
                    \is_array($settings['pools'] ?? null)
                        ? ($settings['pools']['observations_max_tokens'] ?? ($settings['observations_max_tokens'] ?? 30000))
                        : ($settings['observations_max_tokens'] ?? 30000),
                    'observations_max_tokens',
                ),
            ],
        ];
    }

    private function classEnabled(SettingsResolutionDTO $resolution, string $class): bool
    {
        return $this->classInList($this->listAt($this->prefillSource($resolution), ['extensions', 'enabled']), $class);
    }

    /**
     * @param array<string, mixed> $source
     * @param list<string>         $path
     *
     * @return list<string>
     */
    private function listAt(array $source, array $path): array
    {
        $cursor = $source;
        foreach ($path as $segment) {
            if (!\is_array($cursor) || !\array_key_exists($segment, $cursor)) {
                return [];
            }
            $cursor = $cursor[$segment];
        }
        if (!\is_array($cursor) || !array_is_list($cursor)) {
            return [];
        }

        $out = [];
        foreach ($cursor as $item) {
            if (\is_string($item) && '' !== trim($item)) {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    /**
     * @param list<string> $classes
     */
    private function classInList(array $classes, string $class): bool
    {
        return \in_array($class, $classes, true);
    }

    /**
     * @param list<string> $classes
     *
     * @return list<string>
     */
    private function ensureClassInList(array $classes, string $class): array
    {
        if ($this->classInList($classes, $class)) {
            return $classes;
        }
        $classes[] = $class;

        return array_values($classes);
    }

    /**
     * @param list<string> $classes
     *
     * @return list<string>
     */
    private function removeClassFromList(array $classes, string $class): array
    {
        return array_values(array_filter(
            $classes,
            static fn (string $item): bool => $item !== $class,
        ));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (\is_string($value)) {
            return $this->parseCsvList($value);
        }
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (\is_string($item) && '' !== trim($item)) {
                $out[] = trim($item);
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function parseCsvList(mixed $value): array
    {
        if (\is_array($value)) {
            return $this->stringList($value);
        }
        $raw = trim((string) $value);
        if ('' === $raw) {
            return [];
        }
        $parts = preg_split('/\s*,\s*/', $raw);
        if (false === $parts) {
            return [];
        }
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ('' !== $part) {
                $out[] = $part;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function parseAbsolutePathList(mixed $value): array
    {
        $paths = $this->parseCsvList($value);
        foreach ($paths as $path) {
            if (!str_starts_with($path, '/')) {
                throw new \InvalidArgumentException(\sprintf('allow_write_outside_cwd entries must be absolute paths; got "%s".', $path));
            }
        }

        return $paths;
    }

    private function optionalPath(mixed $value): string
    {
        return trim((string) $value);
    }

    private function positiveInt(mixed $value, string $label): int
    {
        if (\is_string($value) && '' === trim($value)) {
            throw new \InvalidArgumentException(\sprintf('%s must be a positive integer.', $label));
        }
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(\sprintf('%s must be a positive integer.', $label));
        }
        $int = (int) $value;
        if ((string) $int !== trim((string) $value) && !\is_int($value)) {
            // Allow "100" / 100; reject floats like 1.5.
            if (!preg_match('/^-?\d+$/', trim((string) $value))) {
                throw new \InvalidArgumentException(\sprintf('%s must be a positive integer.', $label));
            }
        }
        if ($int < 1) {
            throw new \InvalidArgumentException(\sprintf('%s must be a positive integer.', $label));
        }

        return $int;
    }

    private function ratio(mixed $value, float $default): float
    {
        if (null === $value || '' === $value) {
            return $default;
        }
        if (!is_numeric($value)) {
            throw new \InvalidArgumentException('context_window_ratio must be numeric.');
        }

        return (float) $value;
    }

    private function boolish(mixed $value, string $label): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_string($value)) {
            $normalized = strtolower(trim($value));
            if (\in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true)) {
                return true;
            }
            if (\in_array($normalized, ['0', 'false', 'no', 'off', 'disabled'], true)) {
                return false;
            }
        }
        if (1 === $value || 0 === $value) {
            return 1 === $value;
        }

        throw new \InvalidArgumentException(\sprintf('%s must be yes/no.', $label));
    }

    /**
     * @param array<string, mixed> $formState
     */
    private function requireString(array $formState, string $key): string
    {
        $value = $formState[$key] ?? null;
        if (!\is_string($value) || '' === trim($value)) {
            throw new \InvalidArgumentException(\sprintf('Missing %s.', $key));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $formState
     */
    private function requireBool(array $formState, string $key): bool
    {
        $value = $formState[$key] ?? null;
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_string($value)) {
            return $this->boolish($value, $key);
        }

        throw new \InvalidArgumentException(\sprintf('%s must be a boolean.', $key));
    }
}
