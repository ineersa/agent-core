<?php

declare(strict_types=1);

namespace Ineersa\Tui\ImagePaste;

use Ineersa\CodingAgent\Config\ImageToolConfig;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Psr\Log\LoggerInterface;

/**
 * Validates pasted image bytes using the same MIME/dimension limits as view_image.
 *
 * Validation runs at paste (Ctrl+V) and again at submission so tampering or truncation
 * of staged files under /tmp is detected before promotion into session attachments.
 */
final class PastedImageValidationService
{
    /** @var list<string> */
    private const array SUPPORTED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly ImageToolConfig $imageConfig,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @throws \RuntimeException when validation fails
     */
    public function validateFile(string $path): PastedImageValidatedDTO
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('Pasted image file is not readable.');
        }

        $fileSize = @filesize($path);
        if (false === $fileSize) {
            throw new \RuntimeException('Failed to determine pasted image size.');
        }

        if ($fileSize > $this->imageConfig->maxBytes) {
            throw new \RuntimeException(\sprintf('Pasted image exceeds maximum size of %d bytes (actual: %d).', $this->imageConfig->maxBytes, $fileSize));
        }

        $fh = @fopen($path, 'rb');
        if (false === $fh) {
            throw new \RuntimeException('Failed to open pasted image for validation.');
        }

        $headerBytes = @fread($fh, 8192);
        @fclose($fh);

        if (false === $headerBytes || '' === $headerBytes) {
            throw new \RuntimeException('Pasted image appears empty.');
        }

        $detector = new FinfoMimeTypeDetector();
        $mediaType = $detector->detectMimeTypeFromBuffer($headerBytes);

        if (null === $mediaType || !\in_array($mediaType, self::SUPPORTED_TYPES, true)) {
            $display = null !== $mediaType ? $mediaType : 'unknown';
            throw new \RuntimeException(\sprintf('Unsupported pasted image type "%s".', $display));
        }

        $imageInfo = @getimagesize($path);
        if (false === $imageInfo) {
            throw new \RuntimeException('Failed to read pasted image dimensions.');
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];

        if ($width > $this->imageConfig->maxWidth || $height > $this->imageConfig->maxHeight) {
            throw new \RuntimeException(\sprintf('Pasted image dimensions (%dx%d) exceed maximum allowed (%dx%d).', $width, $height, $this->imageConfig->maxWidth, $this->imageConfig->maxHeight));
        }

        $extension = self::extensionForMime($mediaType);

        return new PastedImageValidatedDTO($mediaType, $extension, $fileSize, $width, $height);
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

    private static function extensionForMime(string $mediaType): string
    {
        return match ($mediaType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => throw new \LogicException(\sprintf('Unsupported MIME type "%s" after validation.', $mediaType)),
        };
    }
}
