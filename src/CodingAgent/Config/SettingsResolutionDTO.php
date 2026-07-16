<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\PropertyAccess\PropertyPathBuilder;

/**
 * Fresh settings resolution from disk: raw layers and merged effective config.
 *
 * Dotted paths (e.g. "tui.theme") use Symfony PropertyAccess bracket notation.
 * Literal dots inside a YAML key name cannot be addressed; current Hatfield keys
 * do not contain dots.
 */
final readonly class SettingsResolutionDTO
{
    /**
     * @param array<string, mixed> $defaultsRaw
     * @param array<string, mixed> $userRaw
     * @param array<string, mixed> $projectRaw
     * @param array<string, mixed> $effective
     */
    public function __construct(
        public array $defaultsRaw,
        public array $userRaw,
        public array $projectRaw,
        public array $effective,
    ) {
    }

    public function getValue(string $dottedPath): SettingsValueDTO
    {
        $propertyPath = self::dottedPathToPropertyPath($dottedPath);
        if (null === $propertyPath) {
            return new SettingsValueDTO(exists: false);
        }

        $accessor = self::createPropertyAccessor();

        if (!$accessor->isReadable($this->effective, $propertyPath)) {
            return new SettingsValueDTO(exists: false);
        }

        $value = $accessor->getValue($this->effective, $propertyPath);

        // Empty PHP [] is terminal, not composite: YAML empty map/list both become [].
        if (\is_array($value) && [] !== $value && !array_is_list($value)) {
            return new SettingsValueDTO(
                exists: true,
                value: $value,
                layer: null,
                composite: true,
            );
        }

        if ($accessor->isReadable($this->projectRaw, $propertyPath)) {
            return new SettingsValueDTO(exists: true, value: $value, layer: SettingsLayerEnum::Project);
        }

        if ($accessor->isReadable($this->userRaw, $propertyPath)) {
            return new SettingsValueDTO(exists: true, value: $value, layer: SettingsLayerEnum::User);
        }

        if ($accessor->isReadable($this->defaultsRaw, $propertyPath)) {
            return new SettingsValueDTO(exists: true, value: $value, layer: SettingsLayerEnum::Defaults);
        }

        return new SettingsValueDTO(exists: true, value: $value, layer: null);
    }

    private static function createPropertyAccessor(): PropertyAccessorInterface
    {
        return PropertyAccess::createPropertyAccessorBuilder()
            ->enableExceptionOnInvalidIndex()
            ->getPropertyAccessor();
    }

    /**
     * Converts "tui.theme" to "[tui][theme]" via PropertyPathBuilder.
     */
    private static function dottedPathToPropertyPath(string $dottedPath): ?string
    {
        $dottedPath = trim($dottedPath);
        if ('' === $dottedPath) {
            return null;
        }

        $builder = new PropertyPathBuilder();
        foreach (explode('.', $dottedPath) as $segment) {
            if ('' === $segment) {
                continue;
            }
            // Reject control chars and PropertyPath-significant [, ], \, ? so PropertyPathBuilder
            // cannot produce malformed paths or throw from strict PropertyAccessor.
            if (preg_match('/[\x00-\x1F\x7F\[\]\\\\?]/', $segment)) {
                return null;
            }
            $builder->appendIndex($segment);
        }

        if (0 === $builder->getLength()) {
            return null;
        }

        return (string) $builder;
    }
}
