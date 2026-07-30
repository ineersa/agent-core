<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

/**
 * Process-local owner bracket for extension registration.
 *
 * ExtensionManager sets the current owner class while calling register() so
 * ExtensionToolRegistryBridge / ExtensionHookRegistry can tag every
 * registration without changing the public ExtensionApi surface.
 *
 * @internal
 */
final class ExtensionRegistrationContext
{
    private static ?string $currentOwnerClass = null;

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public static function withOwner(string $ownerClass, callable $callback): mixed
    {
        $previous = self::$currentOwnerClass;
        self::$currentOwnerClass = $ownerClass;

        try {
            return $callback();
        } finally {
            self::$currentOwnerClass = $previous;
        }
    }

    public static function currentOwnerClass(): ?string
    {
        return self::$currentOwnerClass;
    }
}
