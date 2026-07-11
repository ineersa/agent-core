<?php

declare(strict_types=1);

namespace Ineersa\Tui\ImagePaste;

final readonly class PastedImageValidatedDTO
{
    public function __construct(
        public string $mediaType,
        public string $extension,
        public int $bytes,
        public int $width,
        public int $height,
    ) {
    }
}
