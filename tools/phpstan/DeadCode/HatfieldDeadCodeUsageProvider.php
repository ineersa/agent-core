<?php

declare(strict_types=1);

namespace Ineersa\Tools\PHPStan\DeadCode;

use ReflectionEnumUnitCase;
use ReflectionMethod;
use ReflectionProperty;
use ShipMonk\PHPStan\DeadCode\Provider\ReflectionBasedMemberUsageProvider;
use ShipMonk\PHPStan\DeadCode\Provider\VirtualUsageData;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Marks Hatfield dynamic entry points that ShipMonk cannot infer from PHP alone.
 *
 * - EventSubscriberInterface::getSubscribedEvents is wired by the Symfony DIC tag.
 * - Ineersa\Hatfield\ExtensionApi is the published extension contract.
 * - ThemeColorEnum cases are theme YAML tokens resolved by string value.
 */
final class HatfieldDeadCodeUsageProvider extends ReflectionBasedMemberUsageProvider
{
    protected function shouldMarkMethodAsUsed(ReflectionMethod $method): ?VirtualUsageData
    {
        if ('getSubscribedEvents' === $method->getName()
            && $method->getDeclaringClass()->implementsInterface(EventSubscriberInterface::class)) {
            return VirtualUsageData::withNote('Symfony EventSubscriberInterface DIC entrypoint');
        }

        if (str_starts_with($method->getDeclaringClass()->getName(), 'Ineersa\\Hatfield\\ExtensionApi\\')) {
            return VirtualUsageData::withNote('Published Hatfield ExtensionApi contract');
        }

        return null;
    }

    protected function shouldMarkPropertyAsRead(ReflectionProperty $property): ?VirtualUsageData
    {
        if (str_starts_with($property->getDeclaringClass()->getName(), 'Ineersa\\Hatfield\\ExtensionApi\\')) {
            return VirtualUsageData::withNote('Published Hatfield ExtensionApi contract');
        }

        return null;
    }

    protected function shouldMarkEnumCaseAsUsed(ReflectionEnumUnitCase $enumCase): ?VirtualUsageData
    {
        if ('Ineersa\\Tui\\Theme\\ThemeColorEnum' === $enumCase->getDeclaringClass()->getName()) {
            return VirtualUsageData::withNote('Theme YAML palette token resolved by enum string value');
        }

        return null;
    }
}
