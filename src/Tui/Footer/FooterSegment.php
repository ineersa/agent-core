<?php

declare(strict_types=1);

namespace Ineersa\Tui\Footer;

use Ineersa\Tui\Theme\ThemeColorEnum;

/**
 * A single segment of data rendered in the footer bar.
 *
 * Segments are contributed by FooterSegmentProvider implementations,
 * then sorted by priority and rendered left-to-right by FooterBarWidget.
 *
 * When color is provided, {@see FooterBarWidget} wraps the segment text
 * in the corresponding theme color. Segments with the same or adjacent
 * priorities are rendered without section separators for visual grouping.
 */
final readonly class FooterSegment
{
    /**
     * @param string      $text     The rendered text for this segment
     * @param int         $priority Lower = rendered first (leftmost).
     *                              Gaps >= 5 between consecutive segments
     *                              produce a "  |  " separator.
     * @param ?ThemeColorEnum $color    Optional semantic color token
     */
    public function __construct(
        public string          $text = '',
        public int             $priority = 0,
        public ?ThemeColorEnum $color = null,
    ) {
    }
}
