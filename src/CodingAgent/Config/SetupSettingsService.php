<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Yaml\Yaml;

final readonly class SetupSettingsService
{
    public function __construct(
        private AppConfigLoader $loader,
        private AppResourceLocator $resources,
        private AppConfig $app,
        private SettingsOverrideWriter $writer,
        private DenormalizerInterface $denormalizer,
        private ValidatorInterface $validator,
        public SettingsValueResolver $resolver,
        private SettingsDescriptions $descriptions,
    ) {
    }

    public function load(): SettingsResolutionDTO
    {
        return $this->loader->load($this->resources->getDefaultsPath(), $this->app->cwd);
    }

    public function loadConfig(): AppConfig
    {
        return AppConfig::fromSettingsData($this->load()->effective, $this->denormalizer, $this->app->cwd, $this->validator);
    }

    /** @return array<string, array<string, array{sample: mixed, help: string}>> */
    public function inventory(): array
    {
        $path = $this->resources->getDefaultsPath();
        /** @var array<string, mixed> $defaults */
        $defaults = Yaml::parseFile($path);
        $help = $this->descriptions->extract($path, $defaults);
        unset($defaults['ai']['providers'], $defaults['extensions']);
        $groups = [];
        $walk = static function (array $data, string $prefix) use (&$walk, &$groups, $help): void {
            foreach ($data as $key => $value) {
                $path = $prefix.$key;
                if (\is_array($value) && !array_is_list($value)) {
                    $walk($value, $path.'.');

                    continue;
                }
                $group = explode('.', $path)[0];
                $groups[$group][$path] = ['sample' => $value, 'help' => $help[$path] ?? ''];
            }
        };
        $walk($defaults, '');

        return $groups;
    }

    public function editValue(SettingsResolutionDTO $snapshot, SettingsLayerEnum $layer, string $key): SettingsValueDTO
    {
        $raw = $this->loader->overlayConfig($snapshot->defaultsRaw, $snapshot->userRaw);
        if (SettingsLayerEnum::Project === $layer) {
            $raw = $this->loader->overlayConfig($raw, $snapshot->projectRaw);
        }

        return $this->resolver->resolve(new SettingsResolutionDTO([], [], [], $raw), $key);
    }

    public function path(SettingsLayerEnum $layer): string
    {
        return $this->writer->layerPath($layer, $this->app->cwd);
    }

    /** @param array<string, mixed> $values
     * @param list<string> $remove
     */
    public function save(SettingsLayerEnum $layer, array $values, array $remove): bool
    {
        $before = $this->load();
        $local = SettingsLayerEnum::User === $layer ? $before->userRaw : $before->projectRaw;
        // Hidden map editors accept patches, never a replacement assembled from
        // redacted values. Reset is the explicit way to remove a scope's map.
        foreach (['compaction.provider_overrides', 'compaction.model_overrides'] as $key) {
            if (!isset($values[$key]) || !\is_array($values[$key])) {
                continue;
            }
            $current = $this->resolver->resolve(new SettingsResolutionDTO([], [], [], $local), $key)->value;
            $values[$key] = $this->loader->overlayConfig(\is_array($current) ? $current : [], $values[$key], explode('.', $key));
            if ([] === $values[$key]) {
                unset($values[$key]);
            }
        }
        $candidate = $this->writer->withChanges($local, $values, $remove, $this->path($layer));
        if ($candidate === $local) {
            return false;
        }
        $resolved = $this->resolve($before, $layer, $candidate);
        AppConfig::fromSettingsData($resolved->effective, $this->denormalizer, $this->app->cwd, $this->validator);
        // Validate user edits even if a project masks them.
        if (SettingsLayerEnum::User === $layer && [] !== $before->projectRaw) {
            $user = $this->loader->resolveLayers($this->app->cwd, $before->defaultsRaw, $candidate, []);
            AppConfig::fromSettingsData($user->effective, $this->denormalizer, $this->app->cwd, $this->validator);
        }
        $this->writer->setMany($layer, $this->app->cwd, $values, $remove);

        return true;
    }

    public function withoutOverride(SettingsResolutionDTO $before, SettingsLayerEnum $layer, string $key): SettingsResolutionDTO
    {
        return $this->resolve($before, $layer, $this->writer->withoutOverride(
            SettingsLayerEnum::User === $layer ? $before->userRaw : $before->projectRaw,
            $key,
        ));
    }

    /** @param array<string, mixed> $candidate */
    private function resolve(SettingsResolutionDTO $before, SettingsLayerEnum $layer, array $candidate): SettingsResolutionDTO
    {
        return $this->loader->resolveLayers(
            $this->app->cwd,
            $before->defaultsRaw,
            SettingsLayerEnum::User === $layer ? $candidate : $before->userRaw,
            SettingsLayerEnum::Project === $layer ? $candidate : $before->projectRaw,
        );
    }
}
