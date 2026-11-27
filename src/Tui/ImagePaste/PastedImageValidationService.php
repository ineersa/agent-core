<?php

declare(strict_types=1);

namespace Ineersa\Tui\ImagePaste;

use Ineersa\CodingAgent\Tool\ImageProcessing\DTO\ImageInspectionRefusalDTO;
use Ineersa\CodingAgent\Tool\ImageProcessing\ImageFileInspector;
use Ineersa\CodingAgent\Tool\ImageProcessing\ImageInspectionRefusalReasonEnum;
use Psr\Log\LoggerInterface;

/**
 * Validates pasted image bytes using the same MIME/dimension limits as view_image.
 *
 * Validation runs at paste (Ctrl+V) and again at submission so tampering or truncation
 * of staged files under /tmp is detected before promotion into session attachments.
 */
final class PastedImageValidationService
{
    public function __construct(
        private readonly ImageFileInspector $inspector,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \RuntimeException when validation fails
     */
    public function validateFile(string $path): PastedImageValidatedDTO
    {
        $inspection = $this->inspector->inspect($path);
        if ($inspection instanceof ImageInspectionRefusalDTO) {
            throw new \RuntimeException(match ($inspection->reason) {
                ImageInspectionRefusalReasonEnum::NotReadable => 'Pasted image file is not readable.', ImageInspectionRefusalReasonEnum::SizeUnavailable => 'Failed to determine pasted image size.', ImageInspectionRefusalReasonEnum::TooLarge => \sprintf('Pasted image exceeds maximum size of %d bytes (actual: %d).', $inspection->limits->maxBytes, $inspection->bytes), ImageInspectionRefusalReasonEnum::OpenFailed => 'Failed to open pasted image for validation.', ImageInspectionRefusalReasonEnum::HeaderUnreadable => 'Pasted image appears empty.', ImageInspectionRefusalReasonEnum::UnsupportedType => \sprintf('Unsupported pasted image type "%s".', $inspection->mediaType ?? 'unknown'), ImageInspectionRefusalReasonEnum::DimensionsUnavailable => 'Failed to read pasted image dimensions.', ImageInspectionRefusalReasonEnum::DimensionsExceeded => \sprintf('Pasted image dimensions (%dx%d) exceed maximum allowed (%dx%d).', $inspection->width, $inspection->height, $inspection->limits->maxWidth, $inspection->limits->maxHeight),
            });
        }

        return new PastedImageValidatedDTO(ImageFileInspector::extensionForMime($inspection->mediaType));
    }

    public function isSupportedImageFile(string $path): bool
    {
        return $this->inspector->isSupportedImageFile($path);
    }

    /**
     * Centralizes structured, privacy-safe validation diagnostics for paste-time and submit-time checks.
     *
     * Callers surface user-visible errors locally; this method records the exception for observability
     * without logging raw image bytes, paths beyond the event type, or clipboard content.
     */
    public function logValidationFailure(string $eventType, \Throwable $e): void
    {
        $this->logger->info('Pasted image validation failed', [
            'component' => 'PastedImageValidationService',
            'event_type' => $eventType,
            'exception' => $e,
        ]);
    }
}
