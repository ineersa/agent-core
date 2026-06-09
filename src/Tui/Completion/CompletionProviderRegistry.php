<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * Composite completion provider that delegates to a prioritised
 * collection of registered providers.
 *
 * Providers are invoked in priority order (lowest first); the first
 * non-empty suggestion list wins.  This lets slash commands take
 * precedence over file mentions while keeping each provider
 * independent and testable.
 *
 * Registered as the default {@see CompletionProvider} service so
 * {@see \Ineersa\Tui\Listener\CompletionListener} can depend on a
 * single provider without knowing about individual implementations.
 */
final readonly class CompletionProviderRegistry implements CompletionProvider
{
    /**
     * @param iterable<CompletionProvider> $providers
     */
    public function __construct(
        private iterable $providers,
    ) {
    }

    public function getSuggestions(CompletionContext $context): array
    {
        foreach ($this->providers as $provider) {
            $suggestions = $provider->getSuggestions($context);

            if ([] !== $suggestions) {
                return $suggestions;
            }
        }

        return [];
    }
}
