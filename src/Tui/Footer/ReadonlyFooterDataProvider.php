<?php

declare(strict_types=1);

namespace Ineersa\Tui\Footer;

/**
 * Read-only projection of FooterDataProvider for extension use.
 *
 * Extensions receive this interface so they can read footer data
 * (segments, status entries) without mutating the provider set.
 */
final readonly class ReadonlyFooterDataProvider
{
    public function __construct(
        private FooterDataProvider $provider,
    ) {
    }

    /**
     * @return list<FooterSegment>
     */
    public function getSegments(): array
    {
        return $this->provider->getSegments();
    }

    /**
     * @return array<string, string>
     */
    public function getStatusEntries(): array
    {
        return $this->provider->getStatusEntries();
    }
}
