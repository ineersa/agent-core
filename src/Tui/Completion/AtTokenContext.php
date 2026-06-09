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
     * @param string $query             Raw query text after @ and optional opening quote
     * @param int    $replacementStart  Byte offset of @ in the editor text
     * @param int    $replacementLength Number of bytes from @ to end of text
     * @param bool   $isQuoted          Whether the token is quoted (@"...")
     * @param string $rawText           Full editor text at time of extraction
     */
    public function __construct(
        public string $query,
        public int $replacementStart,
        public int $replacementLength,
        public bool $isQuoted,
        public string $rawText,
    ) {
    }
}
