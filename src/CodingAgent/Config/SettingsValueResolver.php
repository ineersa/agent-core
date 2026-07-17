<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\PropertyAccess\PropertyPathBuilder;

/**
 * Resolves a dotted settings path against a fresh {@see SettingsResolutionDTO}.
 *
 * Dotted paths (e.g. "tui.theme") use Symfony PropertyAccess bracket notation.
 * Literal dots inside a YAML key name cannot be addressed; current Hatfield keys
 * do not contain dots.
 *
 * Uses one injected PropertyAccessor instance — never constructs one per query.
 */
final class SettingsValueResolver
{
    public function __construct(
        private readonly PropertyAccessorInterface $propertyAccessor,
    ) {
    }

    public function resolve(SettingsResolutionDTO $settings, string $dottedPath): SettingsValueDTO
    {
        $propertyPath = self::dottedPathToPropertyPath($dottedPath);
        if (null === $propertyPath) {
            return new SettingsValueDTO(exists: false);
        }

        $accessor = $this->propertyAccessor;

        if (!$accessor->isReadable($settings->effective, $propertyPath)) {
            return new SettingsValueDTO(exists: false);
        }

        $value = $accessor->getValue($settings->effective, $propertyPath);

        // Empty PHP [] is terminal, not composite: YAML empty map/list both become [].
        if (\is_array($value) && [] !== $value && !array_is_list($value)) {
            return new SettingsValueDTO(
                exists: true,
                value: $value,
                layer: null,
                composite: true,
            );
        }

        if ($accessor->isReadable($settings->projectRaw, $propertyPath)) {
            return new SettingsValueDTO(exists: true, value: $value, layer: SettingsLayerEnum::Project);
        }

        if ($accessor->isReadable($settings->userRaw, $propertyPath)) {
            return new SettingsValueDTO(exists: true, value: $value, layer: SettingsLayerEnum::User);
        }

        if ($accessor->isReadable($settings->defaultsRaw, $propertyPath)) {
            return new SettingsValueDTO(exists: true, value: $value, layer: SettingsLayerEnum::Defaults);
        }

        return new SettingsValueDTO(exists: true, value: $value, layer: null);
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
