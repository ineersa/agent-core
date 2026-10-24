<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\DTO\ProvidedExtensionDTO;
use Ineersa\CodingAgent\Config\DTO\ProvidedExtensionsCatalogDTO;
use Ineersa\CodingAgent\Config\Exception\ProvidedExtensionsCatalogException;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads the curated Hatfield-provided extensions catalog from YAML.
 *
 * Catalog entries drive extensions:setup package selection. Built-in SafeGuard
 * has no Composer package; installable entries pin exact versions.
 */
final class ProvidedExtensionsCatalog
{
    /** @var list<ProvidedExtensionDTO>|null */
    private ?array $packages = null;

    public function __construct(
        private readonly string $catalogPath,
        private readonly DenormalizerInterface $denormalizer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * @return list<ProvidedExtensionDTO>
     */
    public function all(): array
    {
        return $this->packages ??= $this->load();
    }

    /**
     * @return list<ProvidedExtensionDTO>
     */
    public function installable(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ProvidedExtensionDTO $package): bool => !$package->builtin,
        ));
    }

    public function get(string $id): ?ProvidedExtensionDTO
    {
        foreach ($this->all() as $package) {
            if ($package->id === $id) {
                return $package;
            }
        }

        return null;
    }

    /**
     * @return list<ProvidedExtensionDTO>
     */
    private function load(): array
    {
        if (!is_readable($this->catalogPath)) {
            throw new ProvidedExtensionsCatalogException(\sprintf('Provided extensions catalog is missing or unreadable: %s', $this->catalogPath));
        }

        $content = file_get_contents($this->catalogPath);
        if (false === $content || '' === trim($content)) {
            throw new ProvidedExtensionsCatalogException(\sprintf('Provided extensions catalog is empty: %s', $this->catalogPath));
        }

        try {
            $parsed = Yaml::parse($content);
        } catch (ParseException $e) {
            throw new ProvidedExtensionsCatalogException(\sprintf('Provided extensions catalog is invalid YAML (%s): %s', $this->catalogPath, $e->getMessage()), 0, $e);
        }

        if (!\is_array($parsed)) {
            throw new ProvidedExtensionsCatalogException(\sprintf('Provided extensions catalog must be a mapping: %s', $this->catalogPath));
        }

        try {
            $catalog = $this->denormalizer->denormalize($parsed, ProvidedExtensionsCatalogDTO::class);
        } catch (SerializerExceptionInterface $e) {
            throw new ProvidedExtensionsCatalogException(\sprintf('Provided extensions catalog could not be denormalized (%s): %s', $this->catalogPath, $e->getMessage()), 0, $e);
        }

        $violations = $this->validator->validate($catalog);
        if (\count($violations) > 0) {
            throw new ProvidedExtensionsCatalogException(\sprintf('Provided extensions catalog failed validation (%s).', $this->catalogPath), 0, new ValidationFailedException($catalog, $violations));
        }

        $seenIds = [];
        foreach ($catalog->packages as $package) {
            if (isset($seenIds[$package->id])) {
                throw new ProvidedExtensionsCatalogException(\sprintf('Duplicate provided extension id "%s" in %s.', $package->id, $this->catalogPath));
            }
            $seenIds[$package->id] = true;
        }

        return $catalog->packages;
    }
}
