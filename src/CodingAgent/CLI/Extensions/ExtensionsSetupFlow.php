<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions;

use Ineersa\CodingAgent\CLI\Extensions\DTO\ExtensionsSetupFormDTO;
use Ineersa\CodingAgent\CLI\Extensions\DTO\FileRewindGuidedSettingsDTO;
use Ineersa\CodingAgent\CLI\Extensions\DTO\ObservationalMemoryGuidedSettingsDTO;
use Ineersa\CodingAgent\CLI\Extensions\DTO\SafeGuardGuidedSettingsDTO;
use Ineersa\CodingAgent\CLI\Extensions\DTO\TaskWorkflowGuidedSettingsDTO;
use Ineersa\CodingAgent\CLI\Extensions\Exception\ExtensionsSetupValidationException;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\DTO\ProvidedExtensionDTO;
use Ineersa\CodingAgent\Config\DTO\ProvidedExtensionGuidedFieldDTO;
use Ineersa\CodingAgent\Config\ProvidedExtensionsCatalog;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\SettingsResolutionDTO;
use Ineersa\CodingAgent\Extension\Exception\ExtensionPackageInstallException;
use Ineersa\CodingAgent\Extension\ExtensionPackageInstaller;
use Ineersa\Tui\Setup\ExtensionsSetupFlowInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Headless extensions:setup mutations. Owns scoped install + sparse settings
 * writes; the TUI screen drives I/O against {@see ExtensionsSetupFlowInterface}.
 */
final class ExtensionsSetupFlow implements ExtensionsSetupFlowInterface
{
    /** @var list<string> */
    private array $summary = [];

    private bool $wroteSomething = false;

    private SettingsLayerEnum $layer;

    private readonly PropertyAccessorInterface $propertyAccessor;

    public function __construct(
        private readonly ProvidedExtensionsCatalog $catalog,
        private readonly ExtensionPackageInstaller $installer,
        private readonly AppConfigLoader $configLoader,
        private readonly AppResourceLocator $resourceLocator,
        private readonly SettingsOverrideWriter $settingsWriter,
        private readonly SettingsPathResolver $pathResolver,
        private readonly DenormalizerInterface $denormalizer,
        private readonly NormalizerInterface $normalizer,
        private readonly ValidatorInterface $validator,
        SettingsLayerEnum $layer,
        private readonly string $cwd,
        ?PropertyAccessorInterface $propertyAccessor = null,
    ) {
        if (SettingsLayerEnum::Defaults === $layer) {
            throw new \InvalidArgumentException('Built-in defaults are not an extensions:setup target.');
        }
        $this->layer = $layer;
        $this->propertyAccessor = $propertyAccessor ?? PropertyAccess::createPropertyAccessor();
    }

    public static function for(
        ProvidedExtensionsCatalog $catalog,
        ExtensionPackageInstaller $installer,
        AppConfigLoader $configLoader,
        AppResourceLocator $resourceLocator,
        SettingsOverrideWriter $settingsWriter,
        SettingsPathResolver $pathResolver,
        DenormalizerInterface $denormalizer,
        NormalizerInterface $normalizer,
        ValidatorInterface $validator,
        AppConfig $appConfig,
        bool $project = true,
        ?PropertyAccessorInterface $propertyAccessor = null,
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
            $denormalizer,
            $normalizer,
            $validator,
            $layer,
            $cwd,
            $propertyAccessor,
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
                'settingsKey' => $extension->settingsKey,
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

    public function isProjectScope(): bool
    {
        return SettingsLayerEnum::Project === $this->layer;
    }

    public function setProjectScope(bool $project): void
    {
        $this->layer = $project ? SettingsLayerEnum::Project : SettingsLayerEnum::User;
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
        $settings = $this->guidedSettingsPrefill($extension, $resolution);

        return [
            'id' => $extension->id,
            'name' => $extension->name,
            'builtin' => $extension->builtin,
            'extensionClass' => $extension->extensionClass,
            'settingsKey' => $extension->settingsKey,
            'package' => $extension->package,
            'version' => $extension->version,
            'needsInstall' => !$extension->builtin && !$this->isInstallAvailable($extension),
            'parentEnabled' => $this->classEnabled($resolution, $extension->extensionClass),
            'forksAlwaysOn' => $this->classInList($this->listAt($source, ['forks', 'extensions', 'always_on']), $extension->extensionClass),
            'forksEnabled' => $this->classInList($this->listAt($source, ['forks', 'extensions', 'enabled']), $extension->extensionClass),
            'agentsAlwaysOn' => $this->classInList($this->listAt($source, ['agents', 'extensions', 'always_on']), $extension->extensionClass),
            'settings' => $settings,
            'guidedFields' => array_map(
                static fn (ProvidedExtensionGuidedFieldDTO $field): array => [
                    'id' => $field->id,
                    'path' => $field->path,
                    'type' => $field->type,
                    'label' => $field->label,
                    'description' => $field->description,
                    'default' => $field->default,
                ],
                $extension->guidedFields,
            ),
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
        $form = $this->denormalizeForm($formState);
        $extension = $this->requireCatalogEntry($form->id);
        $class = $extension->extensionClass;

        if (($form->forksAlwaysOn || $form->forksEnabled || $form->agentsAlwaysOn) && !$form->parentEnabled) {
            throw ExtensionsSetupValidationException::forMessage('Child selections require the extension to be enabled for the parent (extensions.enabled). Enable it, or clear forks/agents membership first.');
        }

        $resolution = $this->resolve();
        $layerRaw = $this->layerRaw($resolution);
        $source = $this->prefillSource($resolution);

        $enabled = $this->listAt($source, ['extensions', 'enabled']);
        $enabled = $form->parentEnabled
            ? $this->ensureClassInList($enabled, $class)
            : $this->removeClassFromList($enabled, $class);

        $forksAlways = $this->listAt($source, ['forks', 'extensions', 'always_on']);
        $forksAlways = $form->forksAlwaysOn
            ? $this->ensureClassInList($forksAlways, $class)
            : $this->removeClassFromList($forksAlways, $class);

        $forksOptional = $this->listAt($source, ['forks', 'extensions', 'enabled']);
        $forksOptional = $form->forksEnabled
            ? $this->ensureClassInList($forksOptional, $class)
            : $this->removeClassFromList($forksOptional, $class);

        $agentsAlways = $this->listAt($source, ['agents', 'extensions', 'always_on']);
        $agentsAlways = $form->agentsAlwaysOn
            ? $this->ensureClassInList($agentsAlways, $class)
            : $this->removeClassFromList($agentsAlways, $class);

        // Disable keeps membership updates install-free. Valid guided edits still
        // persist while disabled; untouched invalid/missing guided values stay out
        // of the way for disable-only. Enablement still requires install + valid config.
        $guided = null;
        $settingsBag = \is_array($layerRaw['extensions']['settings'] ?? null)
            ? $layerRaw['extensions']['settings']
            : [];
        if ([] !== $settingsBag && array_is_list($settingsBag)) {
            throw ExtensionsSetupValidationException::forMessage('extensions.settings must be a mapping.');
        }
        $existingSection = \is_array($settingsBag[$extension->settingsKey] ?? null)
            ? $settingsBag[$extension->settingsKey]
            : [];
        if ([] !== $existingSection && array_is_list($existingSection)) {
            throw ExtensionsSetupValidationException::forMessage(\sprintf('extensions.settings.%s must be a mapping.', $extension->settingsKey));
        }

        if ($form->parentEnabled) {
            if (!$extension->builtin && !$this->isInstallAvailable($extension)) {
                throw ExtensionsSetupValidationException::forMessage(\sprintf('%s is not installed for this scope yet. Confirm Composer installation before enabling or saving configuration.', $extension->name));
            }
            $guided = $this->normalizeGuidedSettings($extension, $form->settings);
        } elseif ($this->guidedSettingsWereEdited($extension, $form->settings, $resolution)) {
            $guided = $this->normalizeGuidedSettings($extension, $form->settings);
        }

        $this->settingsWriter->set($this->layer, $this->cwd, 'extensions.enabled', $enabled);
        if (null !== $guided) {
            $merged = $this->configLoader->overlayConfig($existingSection, $guided);
            $this->settingsWriter->set($this->layer, $this->cwd, 'extensions.settings.'.$extension->settingsKey, $merged);
        }

        $this->settingsWriter->set($this->layer, $this->cwd, 'forks.extensions.always_on', $forksAlways);
        $this->settingsWriter->set($this->layer, $this->cwd, 'forks.extensions.enabled', $forksOptional);
        $this->settingsWriter->set($this->layer, $this->cwd, 'agents.extensions.always_on', $agentsAlways);

        $this->wroteSomething = true;
        $this->summary[] = \sprintf(
            'Saved %s to %s (%s). Restart Hatfield so registration picks up the change. Optional per-agent frontmatter extensions remain separate from agents.extensions.always_on.',
            $extension->name,
            $this->settingsPath(),
            $form->parentEnabled ? 'enabled' : 'disabled',
        );
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

    /**
     * @return array<string, mixed>
     */
    private function guidedSettingsPrefill(ProvidedExtensionDTO $extension, SettingsResolutionDTO $resolution): array
    {
        $source = $this->prefillSource($resolution);
        $section = $source['extensions']['settings'][$extension->settingsKey] ?? [];
        if (!\is_array($section) || ([] !== $section && array_is_list($section))) {
            $section = [];
        }

        $settings = [];
        foreach ($extension->guidedFields as $field) {
            $path = $this->propertyPath($field->path);
            $value = $this->propertyAccessor->isReadable($section, $path)
                ? $this->propertyAccessor->getValue($section, $path)
                : null;
            if (null === $value || '' === $value || ([] === $value && \in_array($field->type, ['csv_list', 'absolute_path_list'], true))) {
                $value = $field->default;
            }
            $this->propertyAccessor->setValue($settings, $path, $this->displayValue($field->type, $value));
        }

        return $settings;
    }

    /**
     * True when the form's guided values differ from the prefill that would be
     * shown for this extension (including catalog defaults for empty fields).
     *
     * @param array<string, mixed> $settings
     */
    private function guidedSettingsWereEdited(ProvidedExtensionDTO $extension, array $settings, SettingsResolutionDTO $resolution): bool
    {
        $baseline = $this->guidedSettingsPrefill($extension, $resolution);

        return $this->canonicalizeGuidedSettings($extension, $settings)
            !== $this->canonicalizeGuidedSettings($extension, $baseline);
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function canonicalizeGuidedSettings(ProvidedExtensionDTO $extension, array $settings): array
    {
        $canonical = [];
        foreach ($extension->guidedFields as $field) {
            $path = $this->propertyPath($field->path);
            $value = $this->propertyAccessor->isReadable($settings, $path)
                ? $this->propertyAccessor->getValue($settings, $path)
                : null;
            if (null === $value || '' === $value || ([] === $value && \in_array($field->type, ['csv_list', 'absolute_path_list'], true))) {
                $value = $field->default;
            }
            $this->propertyAccessor->setValue($canonical, $path, $this->displayValue($field->type, $value));
        }

        return $canonical;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function normalizeGuidedSettings(ProvidedExtensionDTO $extension, array $settings): array
    {
        $prepared = $this->prepareSettingsForDenormalization($extension, $settings);
        $dtoClass = match ($extension->id) {
            'safe-guard' => SafeGuardGuidedSettingsDTO::class,
            'task-workflow' => TaskWorkflowGuidedSettingsDTO::class,
            'file-rewind' => FileRewindGuidedSettingsDTO::class,
            'observational-memory' => ObservationalMemoryGuidedSettingsDTO::class,
            default => throw ExtensionsSetupValidationException::forMessage(\sprintf('No guided settings DTO for "%s".', $extension->id)),
        };

        try {
            $dto = $this->denormalizer->denormalize($prepared, $dtoClass);
        } catch (SerializerExceptionInterface $e) {
            throw ExtensionsSetupValidationException::forMessage(\sprintf('Invalid guided settings for %s: %s', $extension->name, $e->getMessage()), $e);
        }

        $violations = $this->validator->validate($dto);
        if (\count($violations) > 0) {
            throw ExtensionsSetupValidationException::fromViolations($violations);
        }

        try {
            /** @var array<string, mixed> $normalized */
            $normalized = $this->normalizer->normalize($dto);
        } catch (SerializerExceptionInterface $e) {
            throw new \RuntimeException(\sprintf('Failed to normalize guided settings for %s: %s', $extension->name, $e->getMessage()), 0, $e);
        }

        if (!\is_array($normalized)) {
            throw new \RuntimeException(\sprintf('Normalized guided settings for %s must be a mapping.', $extension->name));
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function prepareSettingsForDenormalization(ProvidedExtensionDTO $extension, array $settings): array
    {
        $prepared = $settings;
        foreach ($extension->guidedFields as $field) {
            $path = $this->propertyPath($field->path);
            if (!$this->propertyAccessor->isReadable($prepared, $path)) {
                continue;
            }
            $raw = $this->propertyAccessor->getValue($prepared, $path);
            $this->propertyAccessor->setValue($prepared, $path, $this->coerceIncoming($field->type, $raw, $field->path));
        }

        return $prepared;
    }

    private function coerceIncoming(string $type, mixed $raw, string $label): mixed
    {
        return match ($type) {
            'bool' => $this->strictBool($raw, $label),
            'csv_list', 'absolute_path_list' => $this->parseList($raw),
            'path_string', 'model_id' => \is_string($raw) ? trim($raw) : (null === $raw ? '' : (string) $raw),
            'positive_int' => $this->strictPositiveInt($raw, $label),
            'open_unit_ratio' => $this->strictFloat($raw, $label),
            default => $raw,
        };
    }

    private function displayValue(string $type, mixed $value): mixed
    {
        return match ($type) {
            'bool' => (bool) $value,
            'csv_list', 'absolute_path_list' => $this->parseList($value),
            'positive_int' => is_numeric($value) ? (int) $value : $value,
            'open_unit_ratio' => is_numeric($value) ? (float) $value : $value,
            'path_string', 'model_id' => \is_string($value) ? $value : (null === $value ? '' : (string) $value),
            default => $value,
        };
    }

    /**
     * @return list<string>
     */
    private function parseList(mixed $value): array
    {
        if (\is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                if (\is_string($item) && '' !== trim($item)) {
                    $out[] = trim($item);
                }
            }

            return $out;
        }

        $raw = trim((string) $value);
        if ('' === $raw) {
            return [];
        }

        // Split only on ", " so unedited comma-containing entries stay intact
        // when the settings list round-trips through implode(', ', ...).
        if (str_contains($raw, ', ')) {
            $parts = explode(', ', $raw);
        } else {
            $parts = [$raw];
        }

        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ('' !== $part) {
                $out[] = $part;
            }
        }

        return array_values(array_unique($out, \SORT_STRING));
    }

    private function strictBool(mixed $value, string $label): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_string($value)) {
            $normalized = strtolower(trim($value));
            if ('yes' === $normalized || 'true' === $normalized) {
                return true;
            }
            if ('no' === $normalized || 'false' === $normalized) {
                return false;
            }
        }

        throw ExtensionsSetupValidationException::forMessage(\sprintf('%s must be yes/no.', $label));
    }

    private function strictPositiveInt(mixed $value, string $label): int
    {
        if (\is_int($value)) {
            if ($value < 1) {
                throw ExtensionsSetupValidationException::forMessage(\sprintf('%s must be a positive integer.', $label));
            }

            return $value;
        }
        if (\is_string($value) && preg_match('/^\d+$/', trim($value))) {
            $int = (int) trim($value);
            if ($int < 1) {
                throw ExtensionsSetupValidationException::forMessage(\sprintf('%s must be a positive integer.', $label));
            }

            return $int;
        }

        throw ExtensionsSetupValidationException::forMessage(\sprintf('%s must be a positive integer.', $label));
    }

    private function strictFloat(mixed $value, string $label): float
    {
        if (\is_int($value) || \is_float($value)) {
            return (float) $value;
        }
        if (\is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        throw ExtensionsSetupValidationException::forMessage(\sprintf('%s must be numeric.', $label));
    }

    /**
     * @param array<string, mixed> $formState
     */
    private function denormalizeForm(array $formState): ExtensionsSetupFormDTO
    {
        $payload = [
            'id' => $formState['id'] ?? null,
            'parentEnabled' => $formState['parentEnabled'] ?? null,
            'forksAlwaysOn' => $formState['forksAlwaysOn'] ?? null,
            'forksEnabled' => $formState['forksEnabled'] ?? null,
            'agentsAlwaysOn' => $formState['agentsAlwaysOn'] ?? null,
            'settings' => \is_array($formState['settings'] ?? null) ? $formState['settings'] : [],
        ];

        try {
            $form = $this->denormalizer->denormalize($payload, ExtensionsSetupFormDTO::class);
        } catch (SerializerExceptionInterface $e) {
            throw ExtensionsSetupValidationException::forMessage('Invalid extensions:setup form payload: '.$e->getMessage(), $e);
        }

        $violations = $this->validator->validate($form);
        if (\count($violations) > 0) {
            throw ExtensionsSetupValidationException::fromViolations($violations);
        }

        return $form;
    }

    private function propertyPath(string $dotted): string
    {
        $segments = explode('.', $dotted);
        $parts = [];
        foreach ($segments as $segment) {
            $parts[] = '['.$segment.']';
        }

        return implode('', $parts);
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
}
