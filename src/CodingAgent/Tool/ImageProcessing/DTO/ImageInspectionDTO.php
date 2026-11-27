<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\ImageProcessing\DTO;

final readonly class ImageInspectionDTO
{
    public function __construct(
        public string $mediaType,
        public int $bytes,
        public int $width,
        public int $height,
    ) {
    }
}
