<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

/**
 * Ordered fallback chain of executable locators.
 *
 * Tries each locator in registration order and returns the first
 * successful result. If a locator throws, it is caught and the next
 * locator in the chain is tried. If all locators fail, the last
 * exception is re-thrown with a summary of all failures.
 *
 * Typical registration order:
 *   1. ConfigExecutableLocator  (HATFIELD_BINARY_PATH env override)
 *   2. PharExecutableLocator    (current PHAR self-reference)
 *   3. SourceTreeExecutableLocator (source checkout fallback)
 */
final class ChainExecutableLocator implements AppExecutableLocator
{
    /** @var list<AppExecutableLocator> */
    private array $locators = [];

    /**
     * @param AppExecutableLocator ...$locators Ordered list of locators to try.
     *                                          The first successful one wins.
     */
    public function __construct(AppExecutableLocator ...$locators)
    {
        $this->locators = $locators;
    }

    public function command(): array
    {
        return $this->resolve(__FUNCTION__);
    }

    public function path(): string
    {
        return $this->resolve(__FUNCTION__);
    }

    /**
     * Try each locator in the chain for the given method.
     *
     * The $method parameter is always one of the interface methods:
     *   - 'command' → locator->command() → list<string> (PHP binary + args)
     *   - 'path'    → locator->path()    → string (absolute executable path)
     *
     * An explicit match branch dispatches the call instead of a dynamic
     * method call, which satisfies PHPStan's forbidDynamicMethodCall
     * and keeps type expectations clear in both branches.
     *
     * The return type is a union only because PHP doesn't support
     * conditional return types at the language level. In practice the
     * caller (command() or path()) always matches the method being
     * dispatched, so the runtime type is well-known.
     *
     * @return list<string>|string
     */
    private function resolve(string $method): array|string
    {
        $exceptions = [];

        foreach ($this->locators as $locator) {
            try {
                /** @var list<string>|string $result */
                $result = match ($method) {
                    'command' => $locator->command(),
                    'path' => $locator->path(),
                    default => throw new \InvalidArgumentException(\sprintf('Unknown method: %s', $method)),
                };

                return $result;
            } catch (\Throwable $e) {
                $exceptions[] = $e::class.': '.$e->getMessage();
            }
        }

        throw new \RuntimeException('All executable locators in the chain failed: '.\PHP_EOL.implode(\PHP_EOL, $exceptions));
    }
}
