<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\ImageProcessing;

use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Tool\ImageProcessing\DTO\ImageInspectionDTO;
use Ineersa\CodingAgent\Tool\ImageProcessing\DTO\ImageInspectionRefusalDTO;
use League\MimeTypeDetection\FinfoMimeTypeDetector;

/** Inspects mutable image files once; callers own their user-facing refusal messages. */
final readonly class ImageFileInspector
{
    private const array SUPPORTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(private ImageToolConfig $config)
    {
    }

    public function inspect(string $path): ImageInspectionDTO|ImageInspectionRefusalDTO
    {
        if (!is_file($path) || !is_readable($path)) {
            return new ImageInspectionRefusalDTO(ImageInspectionRefusalReasonEnum::NotReadable, $this->config);
        }

        $bytes = @filesize($path);
        if (false === $bytes) {
            return new ImageInspectionRefusalDTO(ImageInspectionRefusalReasonEnum::SizeUnavailable, $this->config);
        }
        if ($bytes > $this->config->maxBytes) {
            return new ImageInspectionRefusalDTO(ImageInspectionRefusalReasonEnum::TooLarge, $this->config, bytes: $bytes);
        }

        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return new ImageInspectionRefusalDTO(ImageInspectionRefusalReasonEnum::OpenFailed, $this->config);
        }
        $header = @fread($handle, 8192);
        @fclose($handle);
        if (false === $header || '' === $header) {
            return new ImageInspectionRefusalDTO(ImageInspectionRefusalReasonEnum::HeaderUnreadable, $this->config);
        }

        $mediaType = (new FinfoMimeTypeDetector())->detectMimeTypeFromBuffer($header);
        if (null === $mediaType || !\in_array($mediaType, self::SUPPORTED_MIME_TYPES, true)) {
            return new ImageInspectionRefusalDTO(ImageInspectionRefusalReasonEnum::UnsupportedType, $this->config, mediaType: $mediaType);
        }

        $dimensions = @getimagesize($path);
        if (false === $dimensions) {
            return new ImageInspectionRefusalDTO(ImageInspectionRefusalReasonEnum::DimensionsUnavailable, $this->config);
        }
        [$width, $height] = $dimensions;
        if ($width > $this->config->maxWidth || $height > $this->config->maxHeight) {
            return new ImageInspectionRefusalDTO(ImageInspectionRefusalReasonEnum::DimensionsExceeded, $this->config, width: $width, height: $height);
        }

        return new ImageInspectionDTO($mediaType, $bytes, $width, $height);
    }

    /** Header-only classification: supported but damaged/oversized images still need normal refusal messages. */
    public function isSupportedImageFile(string $path): bool
    {
        $header = @file_get_contents($path, false, null, 0, 8192);
        if (false === $header || '' === $header) {
            return false;
        }
        $mediaType = (new FinfoMimeTypeDetector())->detectMimeTypeFromBuffer($header);

        return null !== $mediaType && \in_array($mediaType, self::SUPPORTED_MIME_TYPES, true);
    }

    /** Unknown processor cache formats retain the existing binary extension fallback. */
    public static function extensionForMime(string $mediaType): string
    {
        if (!\in_array($mediaType, self::SUPPORTED_MIME_TYPES, true)) {
            return 'bin';
        }

        $extension = (new FinfoMimeTypeDetector())->lookupExtension($mediaType) ?? 'bin';

        // Keep attachment/cache names stable: supported library versions prefer different JPEG aliases.
        return 'jpeg' === $extension ? 'jpg' : $extension;
    }
}
