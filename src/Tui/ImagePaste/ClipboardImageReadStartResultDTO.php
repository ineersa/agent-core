<?php

declare(strict_types=1);

namespace Ineersa\Tui\ImagePaste;

/**
 * Outcome of a non-blocking clipboard read start attempt.
 *
 * When {@see $started} is true, the reader owns an in-flight capture until
 * poll() returns a terminal result or cancel() runs.
 */
final readonly class ClipboardImageReadStartResultDTO
{
    public function __construct(
        public bool $started,
        public ?ClipboardImageReadResultDTO $immediate = null,
    ) {
    }

    public static function started(): self
    {
        return new self(started: true);
    }

    public static function immediate(ClipboardImageReadResultDTO $result): self
    {
        return new self(started: false, immediate: $result);
    }
}
