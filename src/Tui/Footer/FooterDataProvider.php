<?php

declare(strict_types=1);

namespace Ineersa\Tui\Footer;

/**
 * Aggregates footer segment providers and exposes footer data.
 *
 * The FooterBarWidget calls getSegments() at render time to collect
 * all registered segment data. Providers can be added by internal
 * listener code (auto-keyed) or by extensions via the TuiExtensionContext
 * setFooterProvider() API (explicitly keyed, nullable for removal).
 */
final class FooterDataProvider
{
    /** @var array<string, FooterSegmentProvider> */
    private array $providers = [];

    /**
     * Add a provider with an auto-generated key.
     *
     * Internal callers (listeners) should use this. The key allows
     * extension code to later remove the provider if needed, though
     * callers typically do not track or expose the returned key.
     */
    public function addProvider(FooterSegmentProvider $provider, ?string $key = null): string
    {
        $key ??= '='.bin2hex(random_bytes(8));
        $this->providers[$key] = $provider;

        return $key;
    }

    /**
     * Set or replace a keyed provider. Pass null to remove.
     */
    public function setProvider(string $key, ?FooterSegmentProvider $provider): void
    {
        if (null === $provider) {
            unset($this->providers[$key]);
        } else {
            $this->providers[$key] = $provider;
        }
    }

    
    /** @return list<FooterSegment> */
    public function getSegments(): array
    {
        $segments = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getSegments() as $segment) {
                $segments[] = $segment;
            }
        }

        // Sort by priority (lower first)
        usort($segments, static fn (FooterSegment $a, FooterSegment $b) => $a->priority <=> $b->priority);

        return $segments;
    }
}
