<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\ImageProcessing\DTO;

use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Tool\ImageProcessing\ImageInspectionRefusalReasonEnum;

final readonly class ImageInspectionRefusalDTO
{
    public function __construct(
        public ImageInspectionRefusalReasonEnum $reason,
        public ImageToolConfig $limits,
        public ?int $bytes = null,
        public ?string $mediaType = null,
        public ?int $width = null,
        public ?int $height = null,
    ) {
    }
}
