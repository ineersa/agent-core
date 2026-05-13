<?php

declare(strict_types=1);

namespace Ineersa\Tui\Footer;

/**
 * Provides one or more FooterSegment instances for the footer bar.
 *
 * Extensions implement this interface to contribute live data to the
 * footer (e.g. model name, token usage, elapsed time, git branch).
 *
 * Providers are registered on the FooterDataProvider and invoked
 * each time the footer renders.
 */
interface FooterSegmentProvider
{
    /**
     * @return list<FooterSegment> Segments contributed by this provider
     */
    public function getSegments(): array;
}
