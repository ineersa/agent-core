<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * Internal value object for the currently active @ file mention token.
 *
 * @internal used only by {@see FileMentionCompletionProvider};
 *           external consumers should not depend on this type
 */
final readonly class AtTokenContext
{
    /**
     * @param string $query             Raw query text after @ (and after the opening
     *                                  quote when quoted, e.g. @"query → query)
     * @param int    $replacementStart  Byte offset of @ in the editor text
     * @param int    $replacementLength Number of bytes from @ to end of text
     */
    public function __construct(
        public string $query,
        public int $replacementStart,
        public int $replacementLength,
    ) {
    }
}
