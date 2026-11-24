<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions;

use Composer\Autoload\ClassLoader;
use Ineersa\CodingAgent\CLI\Extensions\Exception\ExtensionSetupRefusalException;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Extension\Exception\ExtensionPackageUninstallException;
use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\CodingAgent\Extension\ExtensionPackageInstaller;
use Ineersa\Hatfield\ExtensionApi\ConfigurableExtensionInterface;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Definition\PrototypedArrayNode;
use Symfony\Component\Config\Definition\StringNode;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Yaml\Yaml;

final class ExtensionsSetupFlow
{
    // These roots are reserved for the named official packages. Installed metadata
    // and provider schemas may corroborate ownership, but cannot assign it.
    private const array PACKAGE_IDENTITIES = [
        'ineersa/hatfield-ext-task-workflow' => ['class' => 'Ineersa\\HatfieldExt\\TaskWorkflow\\TaskWorkflowExtension', 'root' => 'task_workflow'],
        'ineersa/hatfield-ext-observational-memory' => ['class' => 'Ineersa\\HatfieldExt\\ObservationalMemory\\ObservationalMemoryExtension', 'root' => 'observational_memory'],
    ];

    public SettingsLayerEnum $layer = SettingsLayerEnum::Project;
    /** @var array<string, NodeInterface> */
    public array $nodes = [];
    /** @var array<string, mixed> */
    public array $settings = [];
    /** @var array<string, string> */
    public array $members = [];
    private ConfigurationInterface $configuration;
    private string $class;
    private string $key;

    /** @param array<string, array{label: string, class: class-string<ConfigurableExtensionInterface>}> $builtIns */
    public function __construct(
        private readonly AppConfig $app,
        private readonly AppConfigLoader $loader,
        private readonly AppResourceLocator $resources,
        private readonly SettingsOverrideWriter $writer,
        private readonly ExtensionPackageInstaller $installer,
        private readonly ExtensionManager $manager,
        private readonly array $builtIns,
    ) {
    }

    /** @return array<string, array{label: string, installed: bool, class: ?string}> */
    public function packages(): array
    {
        $manifest = json_decode(file_get_contents($this->resources->getAppRoot().'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        $installed = $this->installer->packages($this->layer, $this->app->cwd);
        $rows = array_map(static fn (array $entry): array => ['label' => $entry['label'], 'installed' => true, 'class' => $entry['class']], $this->builtIns);
        foreach ($manifest['suggest'] ?? [] as $name => $description) {
            if (!str_starts_with($name, 'ineersa/hatfield-ext-')) {
                continue;
            }
            $package = $installed[$name] ?? [];
            $rows[$name] = ['label' => $package['description'] ?? $description, 'installed' => isset($installed[$name]), 'class' => $package['extra']['hatfield']['extension'] ?? null];
        }

        return $rows;
    }

    public function install(string $package): void
    {
        $this->installer->install($this->layer, $this->app->cwd, $package);
    }

    public function isBuiltIn(string $package): bool
    {
        return isset($this->builtIns[$package]);
    }

    public function uninstall(string $package): void
    {
        if ($this->isBuiltIn($package)) {
            throw new ExtensionSetupRefusalException('Built-in extensions cannot be uninstalled. Disable them in Configure.');
        }
        $identity = self::PACKAGE_IDENTITIES[$package] ?? null;
        if (null === $identity) {
            throw new ExtensionSetupRefusalException('No trusted uninstall identity exists for this package. Nothing was removed.');
        }
        $class = $identity['class'];
        $path = $this->writer->layerPath($this->layer, $this->app->cwd);
        $other = $this->writer->layerPath(SettingsLayerEnum::User === $this->layer ? SettingsLayerEnum::Project : SettingsLayerEnum::User, $this->app->cwd);
        clearstatcache(true);
        $directory = \dirname($path);
        $scopeRoot = realpath(\dirname($directory));
        if (!is_readable($directory) || !is_executable($directory)) {
            throw new ExtensionSetupRefusalException('Cannot inspect settings.yaml in the selected scope. Nothing was removed.');
        }
        if (false === $scopeRoot || realpath($directory) !== $scopeRoot.'/.hatfield'
            || is_link($path) || realpath($directory) === realpath(\dirname($other))) {
            throw new ExtensionSetupRefusalException('The settings path is not exclusive to this scope. Nothing was removed.');
        }
        try {
            // A failed file stat alone cannot distinguish absence from inaccessibility.
            $entries = scandir($directory);
            if (false === $entries) {
                throw new ExtensionSetupRefusalException('Cannot inspect settings.yaml in the selected scope. Nothing was removed.');
            }
            $exists = \in_array(basename($path), $entries, true);
            if ($exists && (!is_file($path) || !is_readable($path))) {
                throw new ExtensionSetupRefusalException('Existing settings.yaml must be a readable regular file. Nothing was removed.');
            }
            $local = $exists ? $this->writer->readRequired($this->layer, $this->app->cwd) : [];
            $installed = $this->installer->packages($this->layer, $this->app->cwd);
            if (!isset($installed[$package])) {
                throw new ExtensionSetupRefusalException('The package is not installed in this scope. Nothing was removed.');
            }
            foreach ($installed as $name => $metadata) {
                $declared = $metadata['extra']['hatfield']['extension'] ?? null;
                if (($name === $package && null !== $declared && $class !== $declared)
                    || ($name !== $package && $class === $declared)) {
                    throw new ExtensionSetupRefusalException('Installed package metadata conflicts with the trusted extension identity. Nothing was removed.');
                }
            }
            $changes = [];
            foreach (['extensions.enabled', 'forks.extensions.enabled', 'forks.extensions.always_on', 'agents.extensions.always_on'] as $listPath) {
                $list = $this->value($local, $listPath);
                if (null === $list) {
                    continue;
                }
                if (!\is_array($list) || !array_is_list($list) || $list !== array_filter($list, is_string(...))) {
                    throw new ExtensionSetupRefusalException('An enablement list in settings.yaml is invalid. Nothing was removed.');
                }
                if (\in_array($class, $list, true)) {
                    $changes[$listPath] = array_values(array_diff($list, [$class]));
                }
            }
            $remove = ['extensions.settings.'.$identity['root']];
            $candidate = $this->writer->withChanges($local, $changes, $remove, $path);
            if ($candidate !== $local && (!is_writable(\dirname($path)) || !is_writable($path))) {
                throw new ExtensionSetupRefusalException('Settings are not writable. Nothing was removed.');
            }
        } catch (ExtensionSetupRefusalException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new ExtensionSetupRefusalException('Cannot read or prepare settings cleanup. Nothing was removed.', previous: $error);
        }
        try {
            $this->installer->uninstall($this->layer, $this->app->cwd, $package);
        } catch (ExtensionPackageUninstallException $error) {
            throw new ExtensionSetupRefusalException($error->getMessage().' Settings were not changed.', previous: $error);
        } catch (\Throwable $error) {
            throw new ExtensionSetupRefusalException('Composer did not confirm removal. Check the Composer installation before retrying. Settings were not changed.', previous: $error);
        }
        if ($local !== $candidate) {
            try {
                $this->writer->setMany($this->layer, $this->app->cwd, $changes, $remove);
            } catch (\Throwable $error) {
                throw new ExtensionSetupRefusalException('Composer removed the package, but settings cleanup failed. Remove its enablement entries and settings block manually before restarting Hatfield.', previous: $error);
            }
        }
    }

    public function select(string $package): void
    {
        [$class, $this->configuration, $root] = $this->configurationFor($package);
        $this->class = $class;
        $this->key = $root->getName();
        try {
            $effective = $this->snapshot();
        } catch (\Throwable $error) {
            throw new ExtensionSetupRefusalException('Cannot read settings for this scope. Check its settings.yaml and any inherited settings.', previous: $error);
        }
        $settings = $effective['extensions']['settings'][$this->key] ?? [];
        if (!\is_array($settings) || ([] !== $settings && array_is_list($settings))) {
            throw new ExtensionSetupRefusalException('The extension settings block is not a mapping. Check settings.yaml before configuring it.');
        }
        $this->settings = $settings;
        $this->nodes = [];
        $this->collectNodes($root);
        $in = fn (string $path): bool => \in_array($class, $this->value($effective, $path) ?? [], true);
        $this->members = [
            'parent' => $in('extensions.enabled') ? 'true' : 'false',
            'forks' => $in('forks.extensions.always_on') ? 'always_on' : ($in('forks.extensions.enabled') ? 'enabled' : 'off'),
            'agents' => $in('agents.extensions.always_on') ? 'true' : 'false',
        ];
    }

    /** @return list<string> */
    public function models(): array
    {
        $ai = AiConfig::optionalFromArray($this->snapshot());

        return null === $ai ? [] : array_map(static fn ($ref): string => $ref->toString(), (new HatfieldModelCatalog($ai))->allModels());
    }

    /** @param array<string, mixed> $values */
    public function value(array $values, string $path): mixed
    {
        return PropertyAccess::createPropertyAccessor()->getValue($values, '['.str_replace('.', '?][', $path).'?]');
    }

    public function clearMapping(string $path): bool
    {
        $layers = $this->loader->load($this->resources->getDefaultsPath(), $this->app->cwd);
        $local = SettingsLayerEnum::User === $this->layer ? $layers->userRaw : $layers->projectRaw;
        $fullPath = 'extensions.settings.'.$this->key.'.'.$path;
        $candidate = $this->writer->withoutOverride($local, $fullPath);
        if ($candidate === $local) {
            return false;
        }

        $settings = $this->snapshot($candidate)['extensions']['settings'][$this->key] ?? [];
        $processor = new Processor();
        $processor->processConfiguration($this->configuration, [$settings]);
        if (SettingsLayerEnum::User === $this->layer && [] !== $layers->projectRaw) {
            $effective = $this->loader->overlayConfig($this->snapshot($candidate), $layers->projectRaw);
            $processor->processConfiguration($this->configuration, [$effective['extensions']['settings'][$this->key] ?? []]);
        }
        $this->writer->remove($this->layer, $this->app->cwd, $fullPath);
        $this->settings = $settings;

        return true;
    }

    /** @param array<string, string> $edits Only keyboard-edited fields, never materialized defaults. */
    public function save(array $edits): bool
    {
        $layers = $this->loader->load($this->resources->getDefaultsPath(), $this->app->cwd);
        $local = SettingsLayerEnum::User === $this->layer ? $layers->userRaw : $layers->projectRaw;
        $effective = $this->snapshot();
        $changes = [];
        $remove = [];
        $membership = [
            'extensions.enabled' => 'true' === $this->members['parent'],
            'forks.extensions.always_on' => 'always_on' === $this->members['forks'],
            'forks.extensions.enabled' => 'enabled' === $this->members['forks'],
            'agents.extensions.always_on' => 'true' === $this->members['agents'],
        ];
        if (!$membership['extensions.enabled'] && ($membership['forks.extensions.always_on'] || $membership['forks.extensions.enabled'] || $membership['agents.extensions.always_on'])) {
            throw new \RuntimeException('Enable the parent extension before selecting it for forks or agents.');
        }
        foreach ($membership as $path => $enabled) {
            $list = $this->value($effective, $path) ?? [];
            if (!\is_array($list) || !array_is_list($list)) {
                throw new \RuntimeException($path.' must be a list.');
            }
            if (\in_array($this->class, $list, true) !== $enabled) {
                $changes[$path] = $enabled ? [...$list, $this->class] : array_values(array_diff($list, [$this->class]));
            }
        }
        $accessor = PropertyAccess::createPropertyAccessor();
        foreach ($edits as $path => $text) {
            $node = $this->nodes[$path];
            $value = $node instanceof StringNode ? $text : Yaml::parse($text);
            $fullPath = 'extensions.settings.'.$this->key.'.'.$path;
            if (null === $value && !$node instanceof ArrayNode && !$node->isRequired()) {
                $local = $this->writer->withoutOverride($local, $fullPath);
                continue;
            }
            $parts = explode('.', $fullPath);
            foreach (\array_slice($parts, 0, -1) as $index => $_) {
                $parent = '['.implode('][', \array_slice($parts, 0, $index + 1)).']';
                if (null === $accessor->getValue($local, $parent)) {
                    $accessor->setValue($local, $parent, []);
                }
            }
            $accessor->setValue($local, '['.implode('][', $parts).']', $value);
        }
        $candidate = $this->snapshot($local)['extensions']['settings'][$this->key] ?? [];
        if ([] !== $edits || $membership['extensions.enabled']) {
            (new Processor())->processConfiguration($this->configuration, [$candidate]);
        }
        if ([] !== $edits) {
            // Replace the local section, not the effective tree: inherited and internal keys stay untouched.
            if (isset($local['extensions'])) {
                $changes['extensions'] = $local['extensions'];
            } else {
                // An empty array would replace inherited mappings as a list; remove the override instead.
                $remove[] = 'extensions';
            }
            if (isset($changes['extensions'], $changes['extensions.enabled'])) {
                $changes['extensions']['enabled'] = $changes['extensions.enabled'];
                unset($changes['extensions.enabled']);
            }
        }
        if ([] !== $changes || [] !== $remove) {
            $this->writer->setMany($this->layer, $this->app->cwd, $changes, $remove);

            return true;
        }

        return false;
    }

    /** @return array{class-string<ConfigurableExtensionInterface>, ConfigurationInterface, ArrayNode} */
    private function configurationFor(string $package): array
    {
        $row = $this->packages()[$package] ?? null;
        if (null === $row || !$row['installed']) {
            throw new ExtensionSetupRefusalException('The package is not installed in this scope.');
        }
        $class = $row['class'];
        if (!\is_string($class) || '' === $class) {
            throw new ExtensionSetupRefusalException('Package metadata does not declare an extension class. Install a release with setup configuration support.');
        }
        $identity = self::PACKAGE_IDENTITIES[$package] ?? null;
        if (!$this->isBuiltIn($package)) {
            if (null !== $identity && $identity['class'] !== $class) {
                throw new ExtensionSetupRefusalException('The package declares a different extension class than the host catalog.');
            }
            try {
                $this->manager->loadAutoloaders();
                $root = $this->installer->rootFor($this->layer, $this->app->cwd);
                $autoload = realpath($root.'/vendor/autoload.php');
                $loader = false === $autoload ? null : (ClassLoader::getRegisteredLoaders()[\dirname($autoload)] ?? null);
                $entry = $loader?->findFile($class);
                $metadata = $this->installer->packages($this->layer, $this->app->cwd)[$package] ?? [];
                $relative = $metadata['install-path'] ?? null;
                $install = \is_string($relative) && !Path::isAbsolute($relative) ? realpath($root.'/vendor/composer/'.$relative) : false;
                $expected = \is_string($entry) ? realpath($entry) : false;
                if (false === $install || false === $expected || !Path::isBasePath($install, $expected)) {
                    throw new ExtensionSetupRefusalException('Cannot resolve the extension class from this scope’s installed package. Check its Composer autoloader and package metadata.');
                }
                if (!class_exists($class, false)) {
                    $loader?->loadClass($class);
                }
                if (!class_exists($class, false)) {
                    throw new ExtensionSetupRefusalException('The installed file did not declare the extension class. Check the package metadata.');
                }
                $declared = (new \ReflectionClass($class))->getFileName();
                if (false === $declared || $expected !== realpath($declared)) {
                    throw new ExtensionSetupRefusalException('Another copy of this extension class is already loaded. Restart setup and configure this scope first.');
                }
            } catch (ExtensionSetupRefusalException $error) {
                throw $error;
            } catch (\Throwable $error) {
                throw new ExtensionSetupRefusalException('The installed extension class could not be loaded. Check the package’s Composer autoloader and compatibility with this Hatfield version.', previous: $error);
            }
        }
        if (!is_a($class, ConfigurableExtensionInterface::class, true)) {
            throw new ExtensionSetupRefusalException('The extension does not provide a configuration schema. Install a release with setup configuration support.');
        }
        try {
            $configuration = (new $class())->getConfiguration();
            $root = $configuration->getConfigTreeBuilder()->buildTree();
        } catch (\Throwable $error) {
            throw new ExtensionSetupRefusalException('The extension could not build its configuration schema. Check package compatibility with this Hatfield version.', previous: $error);
        }
        if (!$root instanceof ArrayNode) {
            throw new ExtensionSetupRefusalException('The extension configuration schema must have a mapping root.');
        }

        if (null !== $identity && $root->getName() !== $identity['root']) {
            throw new ExtensionSetupRefusalException('The extension configuration schema does not use its reserved settings root. No settings were changed.');
        }

        if (null === $identity && !$this->isBuiltIn($package)
            && ($this->isBuiltIn($root->getName()) || \in_array($root->getName(), array_column(self::PACKAGE_IDENTITIES, 'root'), true))) {
            throw new ExtensionSetupRefusalException('The extension configuration schema claims a settings root reserved for another extension. No settings were changed.');
        }

        return [$class, $configuration, $root];
    }

    /**
     * @param array<string, mixed>|null $local
     *
     * @return array<string, mixed>
     */
    private function snapshot(?array $local = null): array
    {
        $layers = $this->loader->load($this->resources->getDefaultsPath(), $this->app->cwd);
        $user = SettingsLayerEnum::User === $this->layer && null !== $local ? $local : $layers->userRaw;
        $effective = $this->loader->overlayConfig($layers->defaultsRaw, $user);

        return SettingsLayerEnum::User === $this->layer ? $effective : $this->loader->overlayConfig($effective, $local ?? $layers->projectRaw);
    }

    private function collectNodes(ArrayNode $parent, string $prefix = ''): void
    {
        foreach ($parent->getChildren() as $name => $node) {
            $path = $prefix.$name;
            if ($node instanceof ArrayNode && !$node instanceof PrototypedArrayNode) {
                // Optional mappings can be explicitly cleared (null masks inheritance).
                if (!$node->isRequired() && !$node->hasDefaultValue()) {
                    $this->nodes[$path] = $node;
                }
                $this->collectNodes($node, $path.'.');
            } else {
                $this->nodes[$path] = $node;
            }
        }
    }
}
