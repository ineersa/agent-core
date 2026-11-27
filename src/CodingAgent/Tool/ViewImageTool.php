<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\ToolResultType;
use Ineersa\CodingAgent\Tool\Arguments\ViewImageArgumentsDTO;
use Ineersa\CodingAgent\Tool\ImageProcessing\DTO\ImageInspectionRefusalDTO;
use Ineersa\CodingAgent\Tool\ImageProcessing\ImageAttachmentProcessor;
use Ineersa\CodingAgent\Tool\ImageProcessing\ImageFileInspector;
use Ineersa\CodingAgent\Tool\ImageProcessing\ImageInspectionRefusalReasonEnum;
use Ineersa\CodingAgent\Tool\ImageProcessing\RunVisionCheckService;
use Ineersa\Hatfield\ExtensionApi\Path\PathResolver;
use Symfony\AI\Agent\Toolbox\Attribute\MapToolArguments;

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
 * Path shape is DTO-validated. Mutable-resource policy (vision capability,
 * existence/readability, max bytes, magic-byte MIME, dimension limits) and
 * metadata production share one filesystem inspection in this handler.
 */
final class ViewImageTool implements HatfieldToolProviderInterface
{
    public const string NAME = 'view_image';

    public const string DESCRIPTION = 'View an image file by attaching it to the next provider request and return compact metadata (media type, dimensions, file size). Supports JPEG, PNG, GIF, and WebP.';

    public function __construct(
        private readonly ToolRuntime $toolRuntime,
        private readonly ImageFileInspector $inspector,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ?RunVisionCheckService $visionCheck = null,
        private readonly ?ImageAttachmentProcessor $processor = null,
    ) {
    }

    /**
     * Execute the view_image tool.
     *
     * @return array<string, mixed> Compact image metadata result.
     *                              NEVER contains base64, data_url, or full image bytes.
     *
     * @throws ToolCallException on policy or operational filesystem failures
     * @throws \RuntimeException on cancellation or timeout (runtime concerns)
     */
    public function __invoke(
        #[MapToolArguments]
        ViewImageArgumentsDTO $arguments,
    ): array {
        return $this->toolRuntime->run(function () use ($arguments): array {
            $path = $arguments->path;
            $resolvedPath = PathResolver::resolve($path);

            $context = $this->contextAccessor->current();
            if (null !== $context && null !== $this->visionCheck) {
                if (!$this->visionCheck->isModelVisionCapable($context->runId())) {
                    throw new ToolCallException('The active model does not support image input. Switch to a vision-capable model to use view_image.', retryable: false);
                }
            }

            $inspection = $this->inspector->inspect($resolvedPath);
            if ($inspection instanceof ImageInspectionRefusalDTO) {
                throw $this->inspectionFailure($resolvedPath, $inspection);
            }
            $mediaType = $inspection->mediaType;
            $fileSize = $inspection->bytes;
            $width = $inspection->width;
            $height = $inspection->height;

            $effectivePath = $resolvedPath;
            $effectiveMediaType = $mediaType;
            $effectiveBytes = $fileSize;
            $effectiveWidth = $width;
            $effectiveHeight = $height;

            $processed = null;
            if (null !== $this->processor) {
                $processed = $this->processor->process($resolvedPath, $mediaType, $width, $height);
                $effectivePath = $processed['path'];
                $effectiveMediaType = $processed['media_type'];
                $effectiveBytes = $processed['bytes'];
                $effectiveWidth = $processed['width'];
                $effectiveHeight = $processed['height'];
            }

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

            if (null !== $processed && $fileSize !== $effectiveBytes) {
                $result['processed_bytes'] = $effectiveBytes;
            }

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

    private function inspectionFailure(string $path, ImageInspectionRefusalDTO $refusal): ToolCallException
    {
        return match ($refusal->reason) {
            ImageInspectionRefusalReasonEnum::NotReadable => new ToolCallException(\sprintf('File "%s" does not exist or is not readable.', $path), retryable: false, hint: 'Check the file path. Use absolute paths or paths relative to the working directory.'),
            ImageInspectionRefusalReasonEnum::SizeUnavailable => new ToolCallException(\sprintf('Failed to determine file size for "%s".', $path), retryable: true, hint: 'The file may be damaged or unreadable.'),
            ImageInspectionRefusalReasonEnum::TooLarge => new ToolCallException(\sprintf('Image file "%s" exceeds maximum allowed size of %d bytes (actual: %d bytes).', $path, $refusal->limits->maxBytes, $refusal->bytes), retryable: false, hint: 'Resize the image or increase the max_bytes setting.'),
            ImageInspectionRefusalReasonEnum::OpenFailed => new ToolCallException(\sprintf('Failed to open file "%s" for reading.', $path), retryable: true, hint: 'Check file permissions and that the file is not locked by another process.'),
            ImageInspectionRefusalReasonEnum::HeaderUnreadable => new ToolCallException(\sprintf('Failed to read header bytes from "%s".', $path), retryable: true, hint: 'The file appears empty or unreadable; try downloading it again.'),
            ImageInspectionRefusalReasonEnum::UnsupportedType => new ToolCallException(\sprintf('Unsupported image type "%s" for file "%s".', $refusal->mediaType ?? 'unknown', $path), retryable: false, hint: 'Use JPEG, PNG, GIF, or WebP format.'),
            ImageInspectionRefusalReasonEnum::DimensionsUnavailable => new ToolCallException(\sprintf('Failed to determine dimensions for image "%s".', $path), retryable: true, hint: 'The file may be corrupted or not a valid image.'),
            ImageInspectionRefusalReasonEnum::DimensionsExceeded => new ToolCallException(\sprintf('Image "%s" dimensions (%dx%d) exceed maximum allowed (%dx%d).', $path, $refusal->width, $refusal->height, $refusal->limits->maxWidth, $refusal->limits->maxHeight), retryable: false, hint: 'Resize the image to fit within the maximum allowed dimensions or increase max_width/max_height settings.'),
        };
    }
}
