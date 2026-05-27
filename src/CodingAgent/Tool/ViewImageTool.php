<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Path\PathResolver;
use League\MimeTypeDetection\FinfoMimeTypeDetector;

/**
 * View an image file and return its content as base64-encoded data.
 *
 * Implements both HatfieldToolProviderInterface for automatic registration
 * as a permanent tool and ToolHandlerInterface for execution.
 *
 * Detection and validation:
 * - MIME type is determined from magic bytes via finfo (not file extension).
 * - Only image/jpeg, image/png, image/gif, and image/webp are accepted.
 * - File size and image dimensions are checked against configurable limits.
 * - Cancellation is checked before reading the file.
 */
final class ViewImageTool implements HatfieldToolProviderInterface, ToolHandlerInterface
{
    /** @var list<string> */
    private const array SUPPORTED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly ToolRuntime $toolRuntime,
        private readonly ImageToolConfig $imageConfig,
        private readonly OutputCap $outputCap,
    ) {
    }

    /**
     * Execute the view_image tool.
     *
     * @param array<string, mixed> $arguments Must contain 'path' (non-empty string)
     *
     * @return array<string, mixed> Structured result with image metadata and content
     *
     * @throws \RuntimeException on validation failures, filesystem errors, or cancellation
     */
    public function __invoke(array $arguments): array
    {
        return $this->toolRuntime->run(function () use ($arguments): array {
            // Validate required argument
            $path = $arguments['path'] ?? null;
            if (!\is_string($path) || '' === $path) {
                throw new \InvalidArgumentException('The "path" argument is required and must be a non-empty string.');
            }

            // Resolve to absolute normalized path
            $resolvedPath = PathResolver::resolve($path);

            // Check file existence and readability
            if (!is_file($resolvedPath) || !is_readable($resolvedPath)) {
                throw new \RuntimeException(\sprintf('File "%s" does not exist or is not readable.', $resolvedPath));
            }

            // Check file size against maximum before reading content
            $fileSize = @filesize($resolvedPath);
            if (false === $fileSize) {
                throw new \RuntimeException(\sprintf('Failed to determine file size for "%s".', $resolvedPath));
            }

            if ($fileSize > $this->imageConfig->maxBytes) {
                throw new \RuntimeException(\sprintf('Image file "%s" exceeds maximum allowed size of %d bytes (actual: %d bytes).', $resolvedPath, $this->imageConfig->maxBytes, $fileSize));
            }

            // Read the first 8KB for magic-byte MIME detection
            $fh = @fopen($resolvedPath, 'r');
            if (false === $fh) {
                throw new \RuntimeException(\sprintf('Failed to open file "%s" for reading.', $resolvedPath));
            }

            $headerBytes = @fread($fh, 8192);
            @fclose($fh);

            if (false === $headerBytes || '' === $headerBytes) {
                throw new \RuntimeException(\sprintf('Failed to read header bytes from "%s".', $resolvedPath));
            }

            // Detect MIME type from magic bytes
            $detector = new FinfoMimeTypeDetector();
            $mediaType = $detector->detectMimeTypeFromBuffer($headerBytes);

            if (null === $mediaType || !\in_array($mediaType, self::SUPPORTED_TYPES, true)) {
                $displayType = null !== $mediaType ? $mediaType : 'unknown';
                throw new \RuntimeException(\sprintf('Unsupported image type "%s" for file "%s". Supported types: JPEG, PNG, GIF, WebP.', $displayType, $resolvedPath));
            }

            // Read full file content
            $binaryContent = @file_get_contents($resolvedPath);
            if (false === $binaryContent) {
                throw new \RuntimeException(\sprintf('Failed to read file "%s".', $resolvedPath));
            }

            // Check image dimensions
            $imageInfo = @getimagesize($resolvedPath);
            if (false === $imageInfo) {
                throw new \RuntimeException(\sprintf('Failed to determine dimensions for image "%s".', $resolvedPath));
            }

            $width = $imageInfo[0];
            $height = $imageInfo[1];

            if ($width > $this->imageConfig->maxWidth || $height > $this->imageConfig->maxHeight) {
                throw new \RuntimeException(\sprintf('Image "%s" dimensions (%dx%d) exceed maximum allowed (%dx%d).', $resolvedPath, $width, $height, $this->imageConfig->maxWidth, $this->imageConfig->maxHeight));
            }

            $base64 = base64_encode($binaryContent);
            $dataUrl = \sprintf('data:%s;base64,%s', $mediaType, $base64);

            $result = [
                'type' => 'view_image',
                'path' => $resolvedPath,
                'media_type' => $mediaType,
                'base64' => $base64,
                'data_url' => $dataUrl,
                'bytes' => $fileSize,
                'width' => $width,
                'height' => $height,
            ];

            // Cap oversized results via OutputCap to prevent runtime/LLM blowup.
            // When the serialized result exceeds the default output cap limit,
            // persist the full data to disk and return a compact result with
            // a reference path. The LLM can retrieve the full data via shell
            // tools if needed.
            $resultJson = json_encode($result);
            if (\is_string($resultJson) && mb_strlen($resultJson) > $this->outputCap->config()->defaultCap) {
                $cappedPath = $this->outputCap->persist($resultJson);

                return [
                    'type' => 'view_image',
                    'path' => $resolvedPath,
                    'media_type' => $mediaType,
                    'bytes' => $fileSize,
                    'width' => $width,
                    'height' => $height,
                    'output_cap_path' => $cappedPath,
                    'note' => \sprintf(
                        'Image data capped. Full %d×%d (%s, %d bytes) saved to output cap. Use read tool or shell commands on output_cap_path to retrieve full data if needed.',
                        $width,
                        $height,
                        $mediaType,
                        $fileSize,
                    ),
                ];
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
            description: 'View an image file and return its content as base64-encoded data with metadata (media type, dimensions, file size). Only JPEG, PNG, GIF, and WebP formats are supported.',
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
            promptLine: 'view_image path — view an image file and return its base64-encoded content with metadata; supports JPEG, PNG, GIF, WebP',
            promptGuidelines: [
                'Only JPEG, PNG, GIF, and WebP formats are supported — other file types are rejected.',
                'Image type is determined from file content (magic bytes), not file extension.',
                'Returns base64-encoded image data, MIME type, data URL, dimensions, and file size.',
                'Large images may be rejected if they exceed configured size or dimension limits.',
                'Very large images have their base64/data_url persisted to an output cap file and replaced with a compact metadata result containing an output_cap_path reference.',
                'Use when you need to inspect image content, dimensions, or encode an image for downstream use.',
            ],
        );
    }
}
