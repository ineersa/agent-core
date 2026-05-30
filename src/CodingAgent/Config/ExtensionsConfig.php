<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Extension settings resolved from Hatfield config.
 *
 * Immutable value object. Contains the list of enabled extension
 * fully-qualified class names and a generic settings array exposed
 * to extensions via the Extension API.
 */
final readonly class ExtensionsConfig
{
    /**
     * @param list<class-string> $enabled  Fully-qualified class names of
     *                                     enabled Hatfield extensions
     * @param array<string, mixed> $settings Generic key-value settings for
     *                                       extensions. Extensions read their
     *                                       section by key (e.g. 'safe_guard').
     */
    public function __construct(
        public array $enabled = [],
        public array $settings = [],
    ) {
    }
}
