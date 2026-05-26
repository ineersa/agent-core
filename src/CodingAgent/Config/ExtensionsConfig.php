<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Extension settings resolved from Hatfield config.
 *
 * Immutable value object. Contains the list of enabled extension
 * fully-qualified class names.
 */
final readonly class ExtensionsConfig
{
    /**
     * @param list<class-string> $enabled Fully-qualified class names of
     *                                    enabled Hatfield extensions
     */
    public function __construct(
        public array $enabled = [],
    ) {
    }
}
