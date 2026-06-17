<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\ToolResultType;
use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Path\PathResolver;
use Ineersa\CodingAgent\Tool\ImageProcessing\ImageAttachmentProcessor;
use Ineersa\CodingAgent\Tool\ImageProcessing\RunVisionCheckService;
use League\MimeTypeDetection\FinfoMimeTypeDetector;

/**
 * View an image file and return compact metadata (no base64/data_url).
 *
 * Implements both HatfieldToolProviderInterface for automatic registration
 * as a permanent tool and ToolHandlerInterface for execution.
 *
 * The tool returns only image metadata (path, media_type, bytes, width, height)
 * as a JSON text result. The actual image data is NOT included in the tool
 * result. Instead, AgentMessageConverter detects the image_ref metadata in
 * the content parts and attaches a real Symfony AI Image content object
 * as a synthetic follow-up user message for the next provider request.
 *
 * Detection and validation:
 * - MIME type is determined from magic bytes via finfo (not file extension).
 * - Only image/jpeg, image/png, image/gif, and image/webp are accepted.
 * - File size and image dimensions are checked against configurable limits.
 * - Cancellation is checked via ToolRuntime::run() before reading the file.
 */
final class ViewImageTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    /** @var list<string> */
    private const array SUPPORTED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly ToolRuntime $toolRuntime,
        private readonly ImageToolConfig $imageConfig,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ?RunVisionCheckService $visionCheck = null,
        private readonly ?ImageAttachmentProcessor $processor = null,
    ) {
    }

    /**
     * Execute the view_image tool.
     *
     * @param array<string, mixed> $arguments Must contain 'path' (non-empty string)
     *
     * @return array<string, mixed> Compact image metadata result.
     *                              NEVER contains base64, data_url, or full image bytes.
     *
     * @throws \RuntimeException on validation failures, filesystem errors, or cancellation
     */
    public function __invoke(array $arguments): array
    {
        return $this->toolRuntime->run(function () use ($arguments): array {
            // Check if the active model supports image viewing.
            // When the model lacks vision capabilities, fail fast with a clear
            // error that the user can see in the TUI tool result.
            $context = $this->contextAccessor->current();
            if (null !== $context && null !== $this->visionCheck) {
                if (!$this->visionCheck->isModelVisionCapable($context->runId())) {
                    throw new ToolCallException('The active model does not support image input.', retryable: false, hint: 'Switch to a vision-capable model to use view_image.');
                }
            }

            // Validate required argument
            $path = $arguments['path'] ?? null;
            if (!\is_string($path) || '' === $path) {
                throw new ToolCallException('The "path" argument is required and must be a non-empty string.', retryable: false, hint: 'Provide a valid file path.');
            }

            // Resolve to absolute normalized path
            $resolvedPath = PathResolver::resolve($path);

            // Check file existence and readability
            if (!is_file($resolvedPath) || !is_readable($resolvedPath)) {
                throw new ToolCallException(\sprintf('File "%s" does not exist or is not readable.', $resolvedPath), retryable: false, hint: 'Check the file path. Use absolute paths or paths relative to the working directory.');
            }

            // Check file size against maximum before reading content
            $fileSize = @filesize($resolvedPath);
            if (false === $fileSize) {
                throw new ToolCallException(\sprintf('Failed to determine file size for "%s".', $resolvedPath), retryable: true, hint: 'The file may be damaged or unreadable.');
            }

            if ($fileSize > $this->imageConfig->maxBytes) {
                throw new ToolCallException(\sprintf('Image file "%s" exceeds maximum allowed size of %d bytes (actual: %d bytes).', $resolvedPath, $this->imageConfig->maxBytes, $fileSize), retryable: false, hint: 'Resize the image or increase the max_bytes setting.');
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

            if (null === $mediaType || !\in_array($mediaType, self::SUPPORTED_TYPES, true)) {
                $displayType = null !== $mediaType ? $mediaType : 'unknown';
                throw new ToolCallException(\sprintf('Unsupported image type "%s" for file "%s".', $displayType, $resolvedPath), retryable: false, hint: 'Use JPEG, PNG, GIF, or WebP format.');
            }

            // Check image dimensions
            $imageInfo = @getimagesize($resolvedPath);
            if (false === $imageInfo) {
                throw new ToolCallException(\sprintf('Failed to determine dimensions for image "%s".', $resolvedPath), retryable: true, hint: 'The file may be corrupted or not a valid image.');
            }

            $width = $imageInfo[0];
            $height = $imageInfo[1];

            if ($width > $this->imageConfig->maxWidth || $height > $this->imageConfig->maxHeight) {
                throw new ToolCallException(\sprintf('Image "%s" dimensions (%dx%d) exceed maximum allowed (%dx%d).', $resolvedPath, $width, $height, $this->imageConfig->maxWidth, $this->imageConfig->maxHeight), retryable: false, hint: 'Resize the image to fit within the maximum allowed dimensions or increase max_width/max_height settings.');
            }

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
            name: 'view_image',
            description: 'View an image file and return its metadata (media type, dimensions, file size). Only JPEG, PNG, GIF, and WebP formats are supported.',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'Path to the image file (absolute, or relative to the working directory)',
                    ],
                ],
                'required' => ['path'],
                'additionalProperties' => false,
            ],
            handler: $this,
            promptLine: 'view_image path — view an image file and return its metadata (media type, dimensions, file size); supports JPEG, PNG, GIF, WebP',
            promptGuidelines: [
                'Only JPEG, PNG, GIF, and WebP formats are supported — other file types are rejected.',
                'Image type is determined from file content (magic bytes), not file extension.',
                'Returns image metadata: path, media type, file size, width, and height.',
                'Large images may be rejected if they exceed configured size or dimension limits.',
                'Images are automatically resized and optimized for safe provider delivery before attachment.',
                'The actual image data is attached to the next provider request as a real image attachment; the tool result contains only compact metadata.',
                'Use when you need to inspect image dimensions, verify file type, or load an image for the model to see.',
            ],
        );
    }
}
