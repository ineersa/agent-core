<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Compaction;

/**
 * Reason why SessionCompactor::prepare() skipped compaction.
 *
 * Its string value is returned to the compaction pipeline as the failure reason.
 */
enum CompactionSkipReasonEnum: string
{
    /**
     * Fewer than two messages — nothing meaningful to compact.
     */
    case TooFewMessages = 'too_few_messages';

    /**
     * The entire session fits within keep_recent_tokens — no need
     * to compact yet.
     */
    case BelowKeepRecentTokens = 'below_keep_recent_tokens';

    /**
     * No boundary could be found (token accumulation never reached
     * keep_recent_tokens).
     */
    case NoBoundary = 'no_boundary';

    /**
     * A boundary exists but no safe cut point could be found —
     * every candidate boundary violates the tool-call sequence
     * invariant.
     */
    case NoSafeBoundary = 'no_safe_boundary';
}
