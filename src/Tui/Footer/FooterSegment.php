<?php

declare(strict_types=1);

namespace Ineersa\Tui\Footer;

/**
 * A single segment of data rendered in the footer bar.
 *
 * Segments are contributed by FooterSegmentProvider implementations
 * and rendered left-to-right by FooterBarWidget.
 */
final readonly class FooterSegment
{
    /**
     * @param string $text     The rendered text for this segment
     * @param int    $priority Lower = rendered first (leftmost)
     */
    public function __construct(
        public string $text = '',
        public int $priority = 0,
    ) {
    }
}
