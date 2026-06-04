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
     *                                           The first successful one wins.
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
     * @return list<string>
     */
    private function resolve(string $method): array|string
    {
        $exceptions = [];

        foreach ($this->locators as $locator) {
            try {
                /** @var list<string>|string $result */
                $result = $locator->{$method}();

                return $result;
            } catch (\Throwable $e) {
                $exceptions[] = $e::class.': '.$e->getMessage();
            }
        }

        throw new \RuntimeException(
            'All executable locators in the chain failed: '.\PHP_EOL
            .implode(\PHP_EOL, $exceptions)
        );
    }
}
