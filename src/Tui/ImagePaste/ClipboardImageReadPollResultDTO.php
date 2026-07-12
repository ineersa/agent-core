<?php

declare(strict_types=1);

namespace Ineersa\Tui\ImagePaste;

/**
 * One non-blocking poll step for an in-flight clipboard image capture.
 */
final readonly class ClipboardImageReadPollResultDTO
{
    public function __construct(
        public bool $pending,
        public ?ClipboardImageReadResultDTO $terminal = null,
    ) {
    }

    public static function pending(): self
    {
        return new self(pending: true);
    }

    public static function terminal(ClipboardImageReadResultDTO $result): self
    {
        return new self(pending: false, terminal: $result);
    }
}
