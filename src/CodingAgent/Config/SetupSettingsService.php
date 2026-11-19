<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class SetupSettingsService
{
    public function __construct(
        private AppConfigLoader $loader,
        private AppResourceLocator $resources,
        private AppConfig $app,
        private SettingsOverrideWriter $writer,
        private DenormalizerInterface $denormalizer,
        private ValidatorInterface $validator,
        private SettingsDescriptions $descriptions,
        public SettingsValueResolver $resolver,
    ) {
    }

    public function load(): SettingsResolutionDTO
    {
        return $this->loader->load($this->resources->getDefaultsPath(), $this->app->cwd);
    }

    /** @return array<string, string> */
    public function descriptions(SettingsResolutionDTO $resolution): array
    {
        return $this->descriptions->extract($this->resources->getDefaultsPath(), $resolution->defaultsRaw);
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
