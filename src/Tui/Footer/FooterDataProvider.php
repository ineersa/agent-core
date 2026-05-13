<?php

declare(strict_types=1);

namespace Ineersa\Tui\Footer;

/**
 * Aggregates footer segment providers and exposes footer data.
 *
 * The FooterBarWidget calls getSegments() at render time to collect
 * all registered segment data. Extensions register their providers
 * via addProvider().
 *
 * A ReadonlyFooterDataProvider projection can be exposed to extensions
 * that should not mutate the provider set.
 */
final class FooterDataProvider
{
    /** @var list<FooterSegmentProvider> */
    private array $providers = [];

    /**
     * @var array<string, string> Extension-set status key-value pairs
     */
    private array $statusEntries = [];

    public function addProvider(FooterSegmentProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    /**
     * @return list<FooterSegment>
     */
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

    public function setStatus(string $key, ?string $text): void
    {
        if (null === $text) {
            unset($this->statusEntries[$key]);
        } else {
            $this->statusEntries[$key] = $text;
        }
    }

    /**
     * @return array<string, string>
     */
    public function getStatusEntries(): array
    {
        return $this->statusEntries;
    }

    /**
     * Create a read-only projection for use by extensions.
     */
    public function readonly(): ReadonlyFooterDataProvider
    {
        return new ReadonlyFooterDataProvider($this);
    }
}
