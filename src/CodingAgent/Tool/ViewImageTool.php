<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\ToolResultType;
use Ineersa\CodingAgent\Path\PathResolver;
use Ineersa\CodingAgent\Tool\Arguments\ViewImageArgumentsDTO;
use Ineersa\CodingAgent\Tool\ImageProcessing\ImageAttachmentProcessor;
use League\MimeTypeDetection\FinfoMimeTypeDetector;

/**
 * View an image file and return compact metadata (no base64/data_url).
 *
 * Implements HatfieldToolProviderInterface for automatic registration
 * as a permanent tool and the Symfony AI native tool contract (typed DTO arguments).
 *
 * The tool returns only image metadata (path, media_type, bytes, width, height)
 * as a JSON text result. The actual image data is NOT included in the tool
 * result. Instead, AgentMessageConverter detects the image_ref metadata in
 * the content parts and attaches a real Symfony AI Image content object
 * as a synthetic follow-up user message for the next provider request.
 *
 * Policy validation (vision capability, existence/readability, max bytes,
 * supported MIME, dimension limits) is enforced before execution by the
 * {@see ViewImageTarget} class-level DTO constraint via
 * ValidateToolCallArgumentsListener. This handler only reads stat/header/
 * dimensions to produce its metadata output; failures here are operational
 * (races, I/O), not policy rejections.
 */
final class ViewImageTool implements HatfieldToolProviderInterface
{
    public const string NAME = 'view_image';

    public const string DESCRIPTION = 'View an image file by attaching it to the next provider request and return compact metadata (media type, dimensions, file size). Supports JPEG, PNG, GIF, and WebP.';

    /** @var list<string> Magic-byte MIME types accepted by view_image. Shared with ViewImageTargetValidator so validation and execution cannot drift. */
    public const array SUPPORTED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly ToolRuntime $toolRuntime,
        private readonly ?ImageAttachmentProcessor $processor = null,
    ) {
    }

    /**
     * Execute the view_image tool.
     *
     * Policy validation (vision capability, target existence/readability,
     * max bytes, supported MIME, dimension limits) is enforced by the
     * ViewImageTarget DTO constraint before this handler runs. The reads
     * below produce execution metadata; false results are operational
     * failures (file changed or disappeared since validation).
     *
     * @return array<string, mixed> Compact image metadata result.
     *                              NEVER contains base64, data_url, or full image bytes.
     *
     * @throws ToolCallException on operational filesystem failures
     * @throws \RuntimeException on cancellation or timeout (runtime concerns)
     */
    public function __invoke(ViewImageArgumentsDTO $arguments): array
    {
        return $this->toolRuntime->run(function () use ($arguments): array {
            $path = $arguments->path;

            // Resolve to absolute normalized path
            $resolvedPath = PathResolver::resolve($path);

            // Execution metadata reads. Validation already rejected missing
            // targets, oversized files, unsupported types, and out-of-range
            // dimensions; reaching a failure here means the file changed or
            // an I/O error occurred between validation and execution.
            $fileSize = @filesize($resolvedPath);
            if (false === $fileSize) {
                throw new ToolCallException(\sprintf('Failed to determine file size for "%s".', $resolvedPath), retryable: true, hint: 'The file may be damaged or unreadable.');
            }

            // Read the first 8KB for magic-byte MIME detection
            $fh = @fopen($resolvedPath, 'rb');
            if (false === $fh) {
                throw new ToolCallException(\sprintf('Failed to open file "%s" for reading.', $resolvedPath), retryable: true, hint: 'Check file permissions and that the file is not locked by another process.');
            }

            $headerBytes = @fread($fh, 8192);
            @fclose($fh);

            if (false === $headerBytes || '' === $headerBytes) {
                throw new ToolCallException(\sprintf('Failed to read header bytes from "%s".', $resolvedPath), retryable: true, hint: 'The file appears empty or unreadable; try downloading it again.');
            }

            // Detect MIME type from magic bytes
            $detector = new FinfoMimeTypeDetector();
            $mediaType = $detector->detectMimeTypeFromBuffer($headerBytes);

            // Race guard, not policy: ViewImageTarget already rejected
            // unsupported types at validation time; detecting one here means
            // the file changed between validation and execution.
            if (null === $mediaType || !\in_array($mediaType, self::SUPPORTED_TYPES, true)) {
                $displayType = null !== $mediaType ? $mediaType : 'unknown';
                throw new ToolCallException(\sprintf('Unsupported image type "%s" for file "%s".', $displayType, $resolvedPath), retryable: true, hint: 'Use JPEG, PNG, GIF, or WebP format. The file may have changed since validation.');
            }

            // Check image dimensions (execution metadata; validation already
            // enforced the configured width/height limits)
            $imageInfo = @getimagesize($resolvedPath);
            if (false === $imageInfo) {
                throw new ToolCallException(\sprintf('Failed to determine dimensions for image "%s".', $resolvedPath), retryable: true, hint: 'The file may be corrupted or not a valid image.');
            }

            $width = $imageInfo[0];
            $height = $imageInfo[1];

            // Process image for provider-safe delivery (resize, quality reduction).
            // The processor writes a cached artifact when processing is needed;
            // otherwise returns the original file unchanged.
            $effectivePath = $resolvedPath;
            $effectiveMediaType = $mediaType;
            $effectiveBytes = $fileSize;
            $effectiveWidth = $width;
            $effectiveHeight = $height;

            // If no processor configured, return original metadata as-is.
            $processed = null;
            if (null !== $this->processor) {
                $processed = $this->processor->process($resolvedPath, $mediaType, $width, $height);
                $effectivePath = $processed['path'];
                $effectiveMediaType = $processed['media_type'];
                $effectiveBytes = $processed['bytes'];
                $effectiveWidth = $processed['width'];
                $effectiveHeight = $processed['height'];
            }

            // Build compact metadata result — no base64, no data_url, no full image bytes.
            // AgentMessageConverter will use image_ref content parts to attach a real
            // Symfony AI Image in a synthetic UserMessage for the provider request.
            //
            // The attachment_refs array declares content-part attachments so the
            // AgentMessageNormalizer can copy them without sniffing the tool type.
            $result = [
                'type' => 'view_image',
                'path' => $effectivePath,
                'media_type' => $effectiveMediaType,
                'bytes' => $effectiveBytes,
                'width' => $effectiveWidth,
                'height' => $effectiveHeight,
                'processed_dimensions' => $effectiveWidth !== $width || $effectiveHeight !== $height,
                'attachment_refs' => [
                    [
                        'type' => ToolResultType::IMAGE_REF,
                        'path' => $effectivePath,
                        'media_type' => $effectiveMediaType,
                        'bytes' => $effectiveBytes,
                        'width' => $effectiveWidth,
                        'height' => $effectiveHeight,
                    ],
                ],
            ];

            // Report processing details to the model so it can reason about size changes
            if (null !== $processed && $fileSize !== $effectiveBytes) {
                $result['processed_bytes'] = $effectiveBytes;
            }

            // Forward processor warnings (e.g. animated image exceeds provider limits)
            if (null !== $processed && isset($processed['exceeds_encoded_limit']) && $processed['exceeds_encoded_limit']) {
                $result['exceeds_encoded_limit'] = true;
                if (isset($processed['warning']) && \is_string($processed['warning'])) {
                    $result['warning'] = $processed['warning'];
                }
            }

            return $result;
        });
    }

    /**
     * Return the tool definition for automatic provider registration.
     */
    public function definition(): ToolDefinitionDTO
    {
        return new ToolDefinitionDTO(
            name: self::NAME,
            description: self::DESCRIPTION,
            handler: $this,
            promptLine: 'view_image path — view an image file and return its metadata (media type, dimensions, file size); supports JPEG, PNG, GIF, WebP',
            promptGuidelines: [
                'Only JPEG, PNG, GIF, and WebP formats are supported — other file types are rejected.',
                'Image type is determined from file content (magic bytes), not file extension.',
                'Large images may be rejected if they exceed configured size or dimension limits.',
                'Images are automatically resized and optimized for safe provider delivery before attachment.',
                'The actual image data is attached to the next provider request as a real image attachment; the tool result contains only compact metadata.',
            ],
        );
    }
}
