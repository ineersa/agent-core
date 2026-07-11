<?php

declare(strict_types=1);

namespace Ineersa\Tui\ImagePaste;

/**
 * Result of attempting to read image bytes from the OS clipboard.
 *
 * When outcome is Image, {@see $tempPath} points at a private file under the
 * system temp directory (restrictive permissions). The caller owns cleanup.
 */
final readonly class ClipboardImageReadResultDTO
{
    public function __construct(
        public ClipboardImageReadOutcomeEnum $outcome,
        public ?string $tempPath = null,
        public ?string $userMessage = null,
        public ?string $diagnostic = null,
    ) {
    }

    public static function image(string $tempPath): self
    {
        return new self(ClipboardImageReadOutcomeEnum::Image, tempPath: $tempPath);
    }

    public static function noImage(string $userMessage): self
    {
        return new self(ClipboardImageReadOutcomeEnum::NoImage, userMessage: $userMessage);
    }

    public static function unavailable(string $userMessage): self
    {
        return new self(ClipboardImageReadOutcomeEnum::Unavailable, userMessage: $userMessage);
    }

    public static function failed(string $userMessage, ?string $diagnostic = null): self
    {
        return new self(ClipboardImageReadOutcomeEnum::Failed, userMessage: $userMessage, diagnostic: $diagnostic);
    }
}
