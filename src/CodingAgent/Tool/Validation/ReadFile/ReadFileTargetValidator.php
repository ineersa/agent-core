<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Validation\ReadFile;

use Ineersa\CodingAgent\Path\PathResolver;
use Ineersa\CodingAgent\Tool\Arguments\ReadFileArgumentsDTO;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates {@see ReadFileTarget} on ReadFileArgumentsDTO.
 *
 * Mirrors the pre-execution checks the read tool used to perform inline:
 * safety blocks, existence/regular/readability, sampled content inspection
 * (MIME, binary, UTF-8, extension), and offset-past-EOF. Checks run in the
 * same order and fail on the first violation, so the deterministic messages
 * match the previous ToolCallException output (remediation hint folded in).
 *
 * I/O performed here (sample read, line count for the offset bound) is
 * duplicated in the handler by design: validation and execution must stay
 * separable, and the handler's own read failures remain operational errors.
 */
final class ReadFileTargetValidator extends ConstraintValidator
{
    private const int INSPECTION_SAMPLE_BYTES = 8192;

    /** Maximum additional bytes needed after INSPECTION_SAMPLE_BYTES to complete a UTF-8 multi-byte prefix. */
    private const int LOOKAHEAD_BYTES = 3;

    /** @var list<string> Path prefixes that are obviously non-file resources. */
    private const array BLOCKED_PATH_PREFIXES = [
        '/dev/',
    ];

    /** @var list<string> Patterns for path segments that should be rejected. */
    private const array BLOCKED_PATH_PATTERNS = [
        '#^/proc/\d+/fd/#',
    ];

    /** @var list<string> Extensions that are likely image files. */
    private const array IMAGE_EXTENSIONS = [
        '.jpg', '.jpeg', '.png', '.gif', '.webp', '.bmp',
        '.svg', '.ico', '.tiff', '.tif', '.avif', '.heic', '.heif',
    ];

    /** @var list<string> Extensions that are non-text documents. */
    private const array BINARY_DOC_EXTENSIONS = [
        '.pdf', '.ipynb', '.xcf', '.psd', '.ai', '.eps',
        '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx',
        '.odt', '.ods', '.odp', '.zip', '.tar', '.gz',
    ];

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ReadFileTarget) {
            throw new UnexpectedTypeException($constraint, ReadFileTarget::class);
        }

        if (!$value instanceof ReadFileArgumentsDTO) {
            throw new UnexpectedTypeException($value, ReadFileArgumentsDTO::class);
        }

        // Blank paths are rejected by the property-level NotBlank constraint;
        // skip filesystem checks so the model sees one clear violation.
        if ('' === trim($value->path)) {
            return;
        }

        $resolvedPath = PathResolver::resolve($value->path);

        if ($this->rejectsBlockedPath($resolvedPath)) {
            return;
        }

        if (!$this->rejectsMissingOrUnreadableTarget($resolvedPath)) {
            $this->inspectContent($resolvedPath);
        }

        $this->rejectsOffsetPastEof($resolvedPath, $value->offset);
    }

    /**
     * @return bool true when a violation was added and later checks must be skipped
     */
    private function rejectsBlockedPath(string $resolvedPath): bool
    {
        foreach (self::BLOCKED_PATH_PREFIXES as $prefix) {
            if (str_starts_with($resolvedPath, $prefix)) {
                $this->violation(\sprintf('Cannot read "%s": device paths are rejected for safety. Specify a regular file path.', $resolvedPath));

                return true;
            }
        }

        foreach (self::BLOCKED_PATH_PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $resolvedPath)) {
                $this->violation(\sprintf('Cannot read "%s": this special path is rejected for safety. Specify a regular file path.', $resolvedPath));

                return true;
            }
        }

        return false;
    }

    /**
     * @return bool true when a violation was added and content checks must be skipped
     */
    private function rejectsMissingOrUnreadableTarget(string $resolvedPath): bool
    {
        if (!file_exists($resolvedPath)) {
            $this->violation(\sprintf('File "%s" does not exist. Check the file path and try again.', $resolvedPath));

            return true;
        }

        if (!is_file($resolvedPath)) {
            $this->violation(\sprintf('"%s" is not a regular file. Use the read tool only for regular files.', $resolvedPath));

            return true;
        }

        if (!is_readable($resolvedPath)) {
            $this->violation(\sprintf('File "%s" is not readable. Check file permissions and try again.', $resolvedPath));

            return true;
        }

        return false;
    }

    private function inspectContent(string $resolvedPath): void
    {
        $fileSize = @filesize($resolvedPath);
        if (false !== $fileSize && 0 === $fileSize) {
            return; // Empty file is valid text content
        }

        // Sample read: up to INSPECTION_SAMPLE_BYTES + LOOKAHEAD_BYTES in
        // binary mode so a multi-byte UTF-8 character split at the inspection
        // boundary can still be validated.
        $sample = $this->readSample($resolvedPath);
        if (null === $sample) {
            return; // sample read failure — violation already added
        }
        $sampleBase = substr($sample, 0, self::INSPECTION_SAMPLE_BYTES);

        // Reject images and other non-text MIME types FIRST so images get a
        // helpful "use view_image" hint instead of a generic binary error.
        if ($this->rejectsNonTextMime($sampleBase, $resolvedPath)) {
            return;
        }

        // Reject binary files (containing null bytes)
        if (str_contains($sampleBase, "\0")) {
            $this->violation(\sprintf('Cannot read "%s": file appears to be binary (contains null bytes). Use the view_image tool for image files. Binary code files (.so, .dll, etc.) are not supported by the read tool.', $resolvedPath));

            return;
        }

        // Reject non-UTF-8 content
        if (!$this->isSampleValidUtf8($sample)) {
            $this->violation(\sprintf('Cannot read "%s": file contains non-UTF-8 encoded content. Convert the file to UTF-8 encoding first, or use a binary-safe tool.', $resolvedPath));

            return;
        }

        // Secondary extension check for files finfo might not catch
        $this->rejectsByExtension($resolvedPath);
    }

    /**
     * @return bool true when a violation was added and later checks must be skipped
     */
    private function rejectsNonTextMime(string $sample, string $resolvedPath): bool
    {
        $detector = new FinfoMimeTypeDetector();
        $mimeType = $detector->detectMimeTypeFromBuffer($sample);

        if (null === $mimeType || '' === $mimeType) {
            return false; // Cannot determine MIME type, proceed
        }

        if (str_starts_with($mimeType, 'image/')) {
            $this->violation(\sprintf('Cannot read "%s": file type "%s" is an image. Use the view_image tool instead. Use the view_image tool to view image files.', $resolvedPath, $mimeType));

            return true;
        }

        $nonTextPrefixes = [
            'video/',
            'audio/',
            'application/zip',
            'application/gzip',
            'application/x-rar',
            'application/x-7z',
            'application/x-bzip',
            'application/pdf',
            'application/vnd.',
            'application/msword',
            'application/x-ms',
            'application/x-dosexec',
            'application/x-sharedlib',
            'application/x-executable',
            'application/x-object',
            'application/x-archive',
            'application/x-tar',
            'application/x-compress',
            'application/octet-stream',
        ];

        foreach ($nonTextPrefixes as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                $this->violation(\sprintf('Cannot read "%s": file type "%s" is not a readable text format. This file type is not supported by the read tool. Use the view_image tool for images.', $resolvedPath, $mimeType));

                return true;
            }
        }

        return false;
    }

    private function rejectsByExtension(string $resolvedPath): void
    {
        $lowerPath = strtolower($resolvedPath);

        foreach (self::IMAGE_EXTENSIONS as $ext) {
            if (str_ends_with($lowerPath, $ext)) {
                $this->violation(\sprintf('Cannot read "%s": this looks like an image file. Use the view_image tool instead. Use the view_image tool to view image files.', $resolvedPath));

                return;
            }
        }

        foreach (self::BINARY_DOC_EXTENSIONS as $ext) {
            if (str_ends_with($lowerPath, $ext)) {
                $this->violation(\sprintf('Cannot read "%s": this looks like a binary document format. Use the view_image tool for images, or check if the file can be converted to text. Binary document formats (.pdf, .docx, .ipynb, etc.) are not supported by the read tool.', $resolvedPath));

                return;
            }
        }
    }

    private function rejectsOffsetPastEof(string $resolvedPath, ?int $offset): void
    {
        if (null === $offset) {
            return;
        }

        // Stream the file line by line instead of loading it whole (file()):
        // counting stops as soon as the offset is proven to be in range, so
        // memory stays bounded even for very large files. The handle is
        // always closed, and read failures (as opposed to EOF) defer to the
        // handler exactly like the previous file() false result did.
        $fh = @fopen($resolvedPath, 'rb');
        if (false === $fh) {
            return; // Race/operational read failure — the handler reports it
        }

        $totalLines = 0;
        try {
            while (false !== ($line = fgets($fh))) {
                ++$totalLines;
                if ($totalLines >= $offset) {
                    return; // offset is within the file; no violation
                }
            }

            if (!feof($fh)) {
                return; // Read error before EOF — the handler reports it
            }
        } finally {
            @fclose($fh);
        }

        $this->violation(\sprintf(
            'Cannot read "%s": offset %d exceeds file length (%d lines). The file has %d lines. Use an offset between 1 and %d, or omit offset to read from the beginning.',
            $resolvedPath,
            $offset,
            $totalLines,
            $totalLines,
            $totalLines,
        ));
    }

    private function violation(string $message): void
    {
        $this->context->buildViolation($message)->addViolation();
    }

    /**
     * Read an inspection sample from the file for content analysis.
     *
     * @return string|null Up to INSPECTION_SAMPLE_BYTES + LOOKAHEAD_BYTES
     *                     bytes from the start of the file, or null on failure
     *                     (a violation is added)
     */
    private function readSample(string $resolvedPath): ?string
    {
        $fh = @fopen($resolvedPath, 'rb');
        if (false === $fh) {
            $lastError = error_get_last();
            $this->violation(\sprintf('Unable to inspect file "%s": %s. Check file permissions and disk health.', $resolvedPath, $lastError['message'] ?? 'Failed to open file for inspection'));

            return null;
        }

        $sample = @fread($fh, self::INSPECTION_SAMPLE_BYTES);
        if (false === $sample) {
            $lastError = error_get_last();
            @fclose($fh);
            $this->violation(\sprintf('Unable to inspect file "%s": %s. Check disk health and file integrity.', $resolvedPath, $lastError['message'] ?? 'Failed to read sample from file'));

            return null;
        }

        $lookahead = @fread($fh, self::LOOKAHEAD_BYTES);
        if (false !== $lookahead && '' !== $lookahead) {
            $sample .= $lookahead;
        }

        @fclose($fh);

        return $sample;
    }

    /**
     * Check whether the sample buffer contains valid UTF-8 content,
     * using lookahead bytes to handle multi-byte characters that may
     * be split at the INSPECTION_SAMPLE_BYTES read boundary.
     *
     * Strategy:
     * 1. Empty buffer → valid (trivial edge case).
     * 2. Fast path: the full probe (base + lookahead) is valid UTF-8.
     * 3. If the probe contains lookahead bytes (> INSPECTION_SAMPLE_BYTES):
     *    try prefix lengths from INSPECTION_SAMPLE_BYTES up to the full probe
     *    length. This allows completing a split multi-byte character with
     *    lookahead bytes, while still accepting the base sample if it alone
     *    is valid (lookahead may start a new multi-byte sequence that is
     *    itself incomplete in the 3-byte lookahead window).
     * 4. If the probe is ≤ INSPECTION_SAMPLE_BYTES (no lookahead): return the
     *    full-sample check as-is; do NOT trim any trailing bytes at EOF.
     */
    private function isSampleValidUtf8(string $sample): bool
    {
        if ('' === $sample) {
            return true;
        }

        // Fast path: the entire probe (base + lookahead) is already valid.
        if (mb_check_encoding($sample, 'UTF-8')) {
            return true;
        }

        $totalLen = \strlen($sample);

        if ($totalLen > self::INSPECTION_SAMPLE_BYTES) {
            // Probe includes lookahead bytes. Try the base sample first,
            // then progressively longer prefixes including lookahead.
            for ($len = self::INSPECTION_SAMPLE_BYTES; $len <= $totalLen; ++$len) {
                if (mb_check_encoding(substr($sample, 0, $len), 'UTF-8')) {
                    return true;
                }
            }

            return false;
        }

        // No lookahead bytes: validate the sample as-is. Never trim at EOF —
        // a file that is ≤ INSPECTION_SAMPLE_BYTES ending with stray
        // continuation bytes is genuinely invalid and must be rejected.
        return mb_check_encoding($sample, 'UTF-8');
    }
}
