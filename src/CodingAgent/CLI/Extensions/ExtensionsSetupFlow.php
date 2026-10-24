<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension;
use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\CodingAgent\Extension\ExtensionPackageInstaller;
use Ineersa\Hatfield\ExtensionApi\ConfigurableExtensionInterface;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Definition\PrototypedArrayNode;
use Symfony\Component\Config\Definition\StringNode;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Yaml\Yaml;

final class ExtensionsSetupFlow
{
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

    public function __construct(
        private readonly AppConfig $app,
        private readonly AppConfigLoader $loader,
        private readonly AppResourceLocator $resources,
        private readonly SettingsOverrideWriter $writer,
        private readonly ExtensionPackageInstaller $installer,
        private readonly ExtensionManager $manager,
    ) {
    }

    /** @return array<string, array{label: string, installed: bool, class: ?string}> */
    public function packages(): array
    {
        $manifest = json_decode(file_get_contents($this->resources->getAppRoot().'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        $installed = $this->installer->packages($this->layer, $this->app->cwd);
        $rows = ['safe_guard' => ['label' => 'SafeGuard (built in)', 'installed' => true, 'class' => SafeGuardExtension::class]];
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

    public function select(string $package): void
    {
        $row = $this->packages()[$package];
        if ('safe_guard' !== $package) {
            // Regenerate existing loaders before dual-root loading; never update installed packages.
            $this->installer->install($this->layer, $this->app->cwd, $package);
            $this->manager->loadAutoloaders();
        }
        $class = $row['class'];
        if (null === $class || !is_a($class, ConfigurableExtensionInterface::class, true)) {
            throw new \RuntimeException('This package does not provide configuration. Install a provider-capable release; settings were not changed.');
        }
        $this->class = $class;
        $this->configuration = (new $class())->getConfiguration();
        $root = $this->configuration->getConfigTreeBuilder()->buildTree();
        if (!$root instanceof ArrayNode) {
            throw new \RuntimeException('Extension configuration must have a native array root.');
        }
        $this->key = $root->getName();
        $effective = $this->snapshot();
        $this->settings = $effective['extensions']['settings'][$this->key] ?? [];
        if (!\is_array($this->settings)) {
            throw new \RuntimeException('Extension settings must be a mapping.');
        }
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
        return array_map(static fn ($ref): string => $ref->toString(), $this->app->catalog?->allModels() ?? []);
    }

    /** @param array<string, mixed> $values */
    public function value(array $values, string $path): mixed
    {
        return PropertyAccess::createPropertyAccessor()->getValue($values, '['.str_replace('.', '][', $path).']');
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
