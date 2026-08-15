<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Constraints;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Path\PathResolver;
use Ineersa\CodingAgent\Tool\Arguments\ViewImageArgumentsDTO;
use Ineersa\CodingAgent\Tool\ImageProcessing\RunVisionCheckService;
use Ineersa\CodingAgent\Tool\ViewImageTool;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates {@see ViewImageTarget} on ViewImageArgumentsDTO.
 *
 * Moves the view_image policy checks out of the tool body: active-model
 * vision capability, target existence/regularity/readability, configured
 * max bytes, magic-byte MIME support, and configured dimension limits.
 * The supported-type list is shared with the tool
 * ({@see ViewImageTool::SUPPORTED_TYPES}) so policy cannot drift.
 *
 * Checks run in the tool's previous order and stop at the first violation,
 * keeping the deterministic messages (remediation hint folded in).
 * The handler keeps only execution metadata reads and defensive throws on
 * race/operational failures.
 */
final class ViewImageTargetValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ImageToolConfig $config,
        private readonly StackToolExecutionContextAccessor $contextAccessor,
        private readonly ?RunVisionCheckService $visionCheck = null,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ViewImageTarget) {
            throw new UnexpectedTypeException($constraint, ViewImageTarget::class);
        }

        if (!$value instanceof ViewImageArgumentsDTO) {
            throw new UnexpectedTypeException($value, ViewImageArgumentsDTO::class);
        }

        // Blank paths are rejected by the property-level NotBlank constraint;
        // skip policy checks so the model sees one clear violation.
        if ('' === trim($value->path)) {
            return;
        }

        // Active-model vision capability. Skipped when no context is active
        // or no vision check service is configured (unit-level execution).
        $context = $this->contextAccessor->current();
        if (null !== $context && null !== $this->visionCheck) {
            if (!$this->visionCheck->isModelVisionCapable($context->runId())) {
                $this->violation('The active model does not support image input. Switch to a vision-capable model to use view_image.');

                return;
            }
        }

        $resolvedPath = PathResolver::resolve($value->path);

        if (!is_file($resolvedPath) || !is_readable($resolvedPath)) {
            $this->violation(\sprintf('File "%s" does not exist or is not readable. Check the file path. Use absolute paths or paths relative to the working directory.', $resolvedPath));

            return;
        }

        $fileSize = @filesize($resolvedPath);
        if (false === $fileSize) {
            $this->violation(\sprintf('Failed to determine file size for "%s". The file may be damaged or unreadable.', $resolvedPath));

            return;
        }

        if ($fileSize > $this->config->maxBytes) {
            $this->violation(\sprintf('Image file "%s" exceeds maximum allowed size of %d bytes (actual: %d bytes). Resize the image or increase the max_bytes setting.', $resolvedPath, $this->config->maxBytes, $fileSize));

            return;
        }

        // Header read for magic-byte MIME detection
        $fh = @fopen($resolvedPath, 'rb');
        if (false === $fh) {
            $this->violation(\sprintf('Failed to open file "%s" for reading. Check file permissions and that the file is not locked by another process.', $resolvedPath));

            return;
        }

        $headerBytes = @fread($fh, 8192);
        @fclose($fh);

        if (false === $headerBytes || '' === $headerBytes) {
            $this->violation(\sprintf('Failed to read header bytes from "%s". The file appears empty or unreadable; try downloading it again.', $resolvedPath));

            return;
        }

        $detector = new FinfoMimeTypeDetector();
        $mediaType = $detector->detectMimeTypeFromBuffer($headerBytes);

        if (null === $mediaType || !\in_array($mediaType, ViewImageTool::SUPPORTED_TYPES, true)) {
            $displayType = null !== $mediaType ? $mediaType : 'unknown';
            $this->violation(\sprintf('Unsupported image type "%s" for file "%s". Use JPEG, PNG, GIF, or WebP format.', $displayType, $resolvedPath));

            return;
        }

        $imageInfo = @getimagesize($resolvedPath);
        if (false === $imageInfo) {
            $this->violation(\sprintf('Failed to determine dimensions for image "%s". The file may be corrupted or not a valid image.', $resolvedPath));

            return;
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];

        if ($width > $this->config->maxWidth || $height > $this->config->maxHeight) {
            $this->violation(\sprintf('Image "%s" dimensions (%dx%d) exceed maximum allowed (%dx%d). Resize the image to fit within the maximum allowed dimensions or increase max_width/max_height settings.', $resolvedPath, $width, $height, $this->config->maxWidth, $this->config->maxHeight));
        }
    }

    private function violation(string $message): void
    {
        $this->context->buildViolation($message)->addViolation();
    }
}
