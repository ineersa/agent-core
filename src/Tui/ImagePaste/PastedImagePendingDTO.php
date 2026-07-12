<?php

declare(strict_types=1);

namespace Ineersa\Tui\ImagePaste;

/**
 * Editor-session pending paste before submission promotes into session attachments.
 */
final readonly class PastedImagePendingDTO
{
    public function __construct(
        public int $index,
        public string $placeholder,
        public string $stagedPath,
    ) {
    }
}
