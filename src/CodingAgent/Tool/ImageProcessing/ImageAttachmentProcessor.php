<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\ImageProcessing;

use Ineersa\CodingAgent\Config\ImageToolConfig;

/**
 * Process images for provider-safe delivery: resize oversized images,
 * re-encode with quality reduction to stay within payload limits, and
 * write the artifact to a deterministic cache directory.
 *
 * Uses Imagick when available (best quality, animation support, EXIF),
 * with GD as fallback.
 *
 * Animated GIF/WebP is passed through unchanged when already within
 * dimension and encoded-size limits to avoid silently losing frames.
 * Animation that exceeds limits is returned as-is (a caveat emitted
 * by the tool, not silently corrupted).
 */
final class ImageAttachmentProcessor
{
    /** @var string Subdirectory under sys_get_temp_dir() for cached processed images */
    private const string CACHE_DIR = 'hatfield/view_image';

    public function __construct(
        private readonly ImageToolConfig $config,
    ) {
    }

    /**
     * Process an image file to produce a provider-safe artifact.
     *
     * @param string $filePath  Absolute path to the source image
     * @param string $mediaType Detected MIME type (image/jpeg, image/png, etc.)
     * @param int    $width     Image width in pixels
     * @param int    $height    Image height in pixels
     *
     * @return array{path: string, media_type: string, width: int, height: int, bytes: int, processed: bool, exceeds_encoded_limit?: bool, warning?: string}
     *                                                                                                                                                       Processed image metadata. 'path' points to either the original
     *                                                                                                                                                       file (no processing needed) or a cached processed artifact.
     *                                                                                                                                                       'processed' is true when processing changed the file.
     *                                                                                                                                                       'exceeds_encoded_limit' is true when the image is too large for
     *                                                                                                                                                       provider-safe delivery and could not be resized to fit.
     *                                                                                                                                                       'warning' carries a human-readable note when applicable.
     */
    public function process(string $filePath, string $mediaType, int $width, int $height): array
    {
        $fileSize = @filesize($filePath);
        if (false === $fileSize) {
            return self::original($filePath, $mediaType, $width, $height, 0);
        }

        // Quick exit for images that are within both dimension and estimated
        // base64 size limits. Base64 overhead is ~37 %, so binary * 1.37 is
        // a conservative upper bound.
        $needsResize = $width > $this->config->maxDimension || $height > $this->config->maxDimension;
        $estimatedB64 = (int) ceil($fileSize * 1.37);

        if (!$needsResize && $estimatedB64 <= $this->config->encodedMaxBytes) {
            return self::original($filePath, $mediaType, $width, $height, $fileSize);
        }

        // Try processing with available library
        if (\extension_loaded('imagick')) {
            return $this->processWithImagick($filePath, $mediaType, $width, $height, $fileSize);
        }

        if (\extension_loaded('gd')) {
            return $this->processWithGd($filePath, $mediaType, $width, $height, $fileSize);
        }

        return self::original($filePath, $mediaType, $width, $height, $fileSize);
    }

    /**
     * Remove expired cached processed image files.
     *
     * @param int|null $olderThanSeconds Delete files older than this many seconds.
     *                                   Defaults to 86400 (24 hours). Null means all.
     *
     * @return int Number of deleted cache files
     */
    public function cleanCache(?int $olderThanSeconds = 86400): int
    {
        $cacheDir = $this->tempDir().'/'.self::CACHE_DIR;

        if (!is_dir($cacheDir)) {
            return 0;
        }

        $deleted = 0;
        $cutoff = null !== $olderThanSeconds ? time() - $olderThanSeconds : null;

        $files = @glob($cacheDir.'/*');
        if (false === $files) {
            return 0;
        }

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            if (null !== $cutoff) {
                $mtime = @filemtime($file);
                if (false === $mtime || $mtime >= $cutoff) {
                    continue;
                }
            }

            if (@unlink($file)) {
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * @return array{path: string, media_type: string, width: int, height: int, bytes: int, processed: bool, exceeds_encoded_limit?: bool, warning?: string}
     */
    private function processWithImagick(string $filePath, string $mediaType, int $width, int $height, int $fileSize): array
    {
        try {
            $img = new \Imagick($filePath);

            // Apply automatic EXIF orientation
            $img->autoOrient();

            // Animated images — pass through when already within limits
            if ($this->isAnimated($img)) {
                if ($width <= $this->config->maxDimension && $height <= $this->config->maxDimension) {
                    $blob = $img->getImageBlob();
                    $b64Len = \strlen(base64_encode($blob));
                    $img->destroy();

                    if ($b64Len <= $this->config->encodedMaxBytes) {
                        return self::original($filePath, $mediaType, $width, $height, $fileSize);
                    }
                }
                // Animated but too large — cannot safely resize without frame loss.
                // Return original with a warning so the tool/model can decide how
                // to handle it rather than silently corrupting the animation.
                $encodedBytes = \strlen(base64_encode($img->getImageBlob()));
                $img->destroy();

                return self::original(
                    $filePath,
                    $mediaType,
                    $width,
                    $height,
                    $fileSize,
                    exceedsEncodedLimit: true,
                    warning: \sprintf(
                        'Animated image may exceed provider size limits (%d bytes encoded, limit %d).',
                        $encodedBytes,
                        $this->config->encodedMaxBytes,
                    ),
                );
            }

            // Non-animated: resize and encode
            [$newW, $newH] = $this->scaleToFit($width, $height);
            if ($newW !== $width || $newH !== $height) {
                $img->resizeImage($newW, $newH, \Imagick::FILTER_LANCZOS, 1);
            }

            $result = $this->tryImagickEncoding($img, $mediaType, $newW, $newH);
            $img->destroy();

            if (null !== $result) {
                return $result;
            }

            // All encoding attempts failed — write resized original as fallback.
            // The result may still exceed provider limits, so flag it.
            $fallbackImg = new \Imagick($filePath);
            if ($newW !== $width || $newH !== $height) {
                $fallbackImg->resizeImage($newW, $newH, \Imagick::FILTER_LANCZOS, 1);
            }
            $blob = $fallbackImg->getImageBlob();
            $fallbackImg->destroy();

            $b64Len = \strlen(base64_encode($blob));
            $exceedsLimit = $b64Len > $this->config->encodedMaxBytes;
            $outPath = $this->writeCache($blob, $this->formatExtension($mediaType));

            // Cache write failed — fall back to the original file
            if (null === $outPath) {
                return self::original(
                    $filePath,
                    $mediaType,
                    $width,
                    $height,
                    $fileSize,
                    exceedsEncodedLimit: $exceedsLimit,
                    warning: 'Could not write processed image to temp cache.',
                );
            }

            $result = [
                'path' => $outPath,
                'media_type' => $mediaType,
                'width' => $newW,
                'height' => $newH,
                'bytes' => \strlen($blob),
                'processed' => true,
            ];

            if ($exceedsLimit) {
                $result['exceeds_encoded_limit'] = true;
                $result['warning'] = \sprintf(
                    'Image may exceed provider size limits (%d bytes encoded, limit %d).',
                    $b64Len,
                    $this->config->encodedMaxBytes,
                );
            }

            return $result;
        } catch (\Throwable) {
            return self::original($filePath, $mediaType, $width, $height, $fileSize);
        }
    }

    /**
     * Try encoding candidates with Imagick, reducing dimensions progressively.
     *
     * @return array{path: string, media_type: string, width: int, height: int, bytes: int, processed: bool, exceeds_encoded_limit?: bool, warning?: string}|null
     */
    private function tryImagickEncoding(\Imagick $img, string $mediaType, int $origW, int $origH): ?array
    {
        $candidates = $this->encodingCandidates($mediaType);

        $currentW = $origW;
        $currentH = $origH;

        while ($currentW >= 100 && $currentH >= 100) {
            foreach ($candidates as $spec) {
                $format = $spec['format'];
                $quality = $spec['quality'] ?? null;

                $clone = clone $img;
                if ($currentW !== $origW || $currentH !== $origH) {
                    $clone->resizeImage($currentW, $currentH, \Imagick::FILTER_LANCZOS, 1);
                }
                $clone->setImageFormat($format);
                if (null !== $quality) {
                    $clone->setImageCompressionQuality($quality);
                }

                $blob = $clone->getImageBlob();
                $clone->destroy();

                $b64Len = \strlen(base64_encode($blob));
                if ($b64Len <= $this->config->encodedMaxBytes) {
                    $outPath = $this->writeCache($blob, $format);
                    if (null === $outPath) {
                        continue;
                    }

                    return [
                        'path' => $outPath,
                        'media_type' => 'image/'.strtolower($format),
                        'width' => $currentW,
                        'height' => $currentH,
                        'bytes' => \strlen($blob),
                        'processed' => true,
                    ];
                }
            }

            // Reduce dimensions by 0.75x and retry
            $currentW = max(100, (int) ceil($currentW * 0.75));
            $currentH = max(100, (int) ceil($currentH * 0.75));
        }

        return null;
    }

    /**
     * @return array{path: string, media_type: string, width: int, height: int, bytes: int, processed: bool, exceeds_encoded_limit?: bool, warning?: string}
     */
    private function processWithGd(string $filePath, string $mediaType, int $width, int $height, int $fileSize): array
    {
        $gd = $this->loadGdImage($filePath, $mediaType);
        if (null === $gd) {
            return self::original($filePath, $mediaType, $width, $height, $fileSize);
        }

        // Apply EXIF orientation for JPEG
        if ('image/jpeg' === $mediaType && \function_exists('exif_read_data')) {
            $gd = $this->applyExifOrientationWithGd($gd, $filePath);
            if (null === $gd) {
                return self::original($filePath, $mediaType, $width, $height, $fileSize);
            }
        }

        $newW = $width;
        $newH = $height;

        if ($width > $this->config->maxDimension || $height > $this->config->maxDimension) {
            [$newW, $newH] = $this->scaleToFit($width, $height);
            $resized = imagescale($gd, $newW, $newH, \IMG_BICUBIC_FIXED);
            if (false !== $resized) {
                imagedestroy($gd);
                $gd = $resized;
            } else {
                $newW = $width;
                $newH = $height;
            }
        }

        $result = $this->tryGdEncoding($gd, $mediaType, $newW, $newH);
        imagedestroy($gd);

        return $result ?? self::original($filePath, $mediaType, $width, $height, $fileSize);
    }

    /**
     * Try encoding with GD, falling back through format/quality candidates.
     *
     * @return array{path: string, media_type: string, width: int, height: int, bytes: int, processed: bool, exceeds_encoded_limit?: bool, warning?: string}|null
     */
    private function tryGdEncoding(\GdImage $gd, string $mediaType, int $width, int $height): ?array
    {
        $candidates = $this->encodingCandidates($mediaType);

        $currentW = $width;
        $currentH = $height;

        while ($currentW >= 100 && $currentH >= 100) {
            foreach ($candidates as $spec) {
                $format = $spec['format'];
                $quality = $spec['quality'] ?? null;

                if ($currentW !== $width || $currentH !== $height) {
                    $resized = imagescale($gd, $currentW, $currentH, \IMG_BICUBIC_FIXED);
                    if (false === $resized) {
                        continue;
                    }
                    $encodeGd = $resized;
                } else {
                    $encodeGd = $gd;
                }

                $blob = $this->gdEncode($encodeGd, $format, $quality);

                if ($encodeGd !== $gd) {
                    imagedestroy($encodeGd);
                }

                if (null === $blob) {
                    continue;
                }

                $b64Len = \strlen(base64_encode($blob));
                if ($b64Len <= $this->config->encodedMaxBytes) {
                    $outPath = $this->writeCache($blob, $format);
                    if (null === $outPath) {
                        continue;
                    }

                    return [
                        'path' => $outPath,
                        'media_type' => 'image/'.strtolower($format),
                        'width' => $currentW,
                        'height' => $currentH,
                        'bytes' => \strlen($blob),
                        'processed' => true,
                    ];
                }
            }

            $currentW = max(100, (int) ceil($currentW * 0.75));
            $currentH = max(100, (int) ceil($currentH * 0.75));
        }

        return null;
    }

    /**
     * Encode a GD image to a binary string.
     *
     * @return non-empty-string|null Binary image data, or null on failure
     */
    private function gdEncode(\GdImage $gd, string $format, ?int $quality = null): ?string
    {
        return match ($format) {
            'jpeg' => $this->gdEncodeJpeg($gd, $quality),
            'png' => $this->gdEncodePng($gd),
            'gif' => $this->gdEncodeGif($gd),
            'webp' => $this->gdEncodeWebp($gd, $quality),
            default => null,
        };
    }

    private function gdEncodeJpeg(\GdImage $gd, ?int $quality): ?string
    {
        ob_start();
        $ok = imagejpeg($gd, null, $quality ?? $this->config->jpegQuality);
        $data = ob_get_clean();

        return false !== $ok && \is_string($data) && '' !== $data ? $data : null;
    }

    private function gdEncodePng(\GdImage $gd): ?string
    {
        ob_start();
        $ok = imagepng($gd, null, 9); // Max compression, no quality tradeoff
        $data = ob_get_clean();

        return false !== $ok && \is_string($data) && '' !== $data ? $data : null;
    }

    private function gdEncodeGif(\GdImage $gd): ?string
    {
        ob_start();
        $ok = imagegif($gd);
        $data = ob_get_clean();

        return false !== $ok && \is_string($data) && '' !== $data ? $data : null;
    }

    private function gdEncodeWebp(\GdImage $gd, ?int $quality): ?string
    {
        if (!\function_exists('imagewebp')) {
            return null;
        }

        ob_start();
        $ok = imagewebp($gd, null, $quality ?? $this->config->jpegQuality);
        $data = ob_get_clean();

        return false !== $ok && \is_string($data) && '' !== $data ? $data : null;
    }

    /**
     * Load a GD image from file.
     */
    private function loadGdImage(string $filePath, string $mediaType): ?\GdImage
    {
        return match ($mediaType) {
            'image/jpeg' => ($img = @imagecreatefromjpeg($filePath)) instanceof \GdImage ? $img : null,
            'image/png' => ($img = @imagecreatefrompng($filePath)) instanceof \GdImage ? $img : null,
            'image/gif' => ($img = @imagecreatefromgif($filePath)) instanceof \GdImage ? $img : null,
            'image/webp' => \function_exists('imagecreatefromwebp')
                ? (($img = @imagecreatefromwebp($filePath)) instanceof \GdImage ? $img : null)
                : null,
            default => null,
        };
    }

    /**
     * Apply EXIF orientation from a JPEG file to a GD image.
     *
     * Returns a new GD image with orientation applied, or null on failure.
     */
    private function applyExifOrientationWithGd(\GdImage $gd, string $filePath): ?\GdImage
    {
        try {
            $exif = @exif_read_data($filePath);
            $orientation = isset($exif['Orientation']) && \is_int($exif['Orientation']) ? $exif['Orientation'] : 1;

            return match ($orientation) {
                2 => $this->gdFlipHorizontally($gd),
                3 => $this->gdRotate($gd, 180),
                4 => $this->gdFlipVertically($gd),
                5 => (($rotated = $this->gdRotate($gd, 90)) instanceof \GdImage) ? $this->gdFlipHorizontally($rotated) : null,
                6 => $this->gdRotate($gd, 270),
                7 => (($rotated = $this->gdRotate($gd, 90)) instanceof \GdImage) ? $this->gdFlipVertically($rotated) : null,
                8 => $this->gdRotate($gd, 90),
                default => $gd,
            };
        } catch (\Throwable) {
            return $gd;
        }
    }

    private function gdRotate(\GdImage $gd, int $angle): ?\GdImage
    {
        $rotated = imagerotate($gd, $angle, 0);

        return $rotated instanceof \GdImage ? $rotated : null;
    }

    private function gdFlipHorizontally(\GdImage $gd): ?\GdImage
    {
        $w = imagesx($gd);
        $h = imagesy($gd);
        $flipped = imagecreatetruecolor($w, $h);
        if (false === $flipped) {
            return null;
        }
        imagecopyresampled($flipped, $gd, 0, 0, $w - 1, 0, $w, $h, -$w, $h);

        return $flipped;
    }

    private function gdFlipVertically(\GdImage $gd): ?\GdImage
    {
        $w = imagesx($gd);
        $h = imagesy($gd);
        $flipped = imagecreatetruecolor($w, $h);
        if (false === $flipped) {
            return null;
        }
        imagecopyresampled($flipped, $gd, 0, 0, 0, $h - 1, $w, $h, $w, -$h);

        return $flipped;
    }

    // ─── Encoding candidates (dynamically generated from config) ───

    /**
     * Generate encoding candidates for a given input media type, using
     * the configured jpegQuality as starting quality and stepping down
     * to jpegMinQuality.
     *
     * First candidate for the native format is tried at full quality,
     * then progressively lower qualities, then alternative formats.
     *
     * @return list<array{format: string, quality?: int}>
     */
    private function encodingCandidates(string $mediaType): array
    {
        $startQuality = $this->config->jpegQuality;
        $minQuality = $this->config->jpegMinQuality;

        // Build quality steps: start, start-10, start-25, start-40
        $qualitySteps = [];
        foreach ([0, -10, -25, -40] as $step) {
            $q = $startQuality + $step;
            if ($q >= $minQuality) {
                $qualitySteps[] = $q;
            }
        }

        // Ensure minQuality is always included
        if (!\in_array($minQuality, $qualitySteps, true)) {
            $qualitySteps[] = $minQuality;
        }

        // Deduplicate and sort descending
        $qualitySteps = array_unique($qualitySteps);
        rsort($qualitySteps);

        // Build JPEG candidates from quality steps
        $jpegCandidates = [];
        foreach ($qualitySteps as $q) {
            $jpegCandidates[] = ['format' => 'jpeg', 'quality' => $q];
        }

        // Build WebP candidates from quality steps
        $webpCandidates = [];
        foreach ($qualitySteps as $q) {
            $webpCandidates[] = ['format' => 'webp', 'quality' => $q];
        }

        return match ($mediaType) {
            'image/jpeg' => $jpegCandidates,
            'image/png' => array_merge(
                [['format' => 'png']],
                $jpegCandidates,
            ),
            'image/gif' => array_merge(
                [['format' => 'gif']],
                [['format' => 'png']],
                $jpegCandidates,
            ),
            'image/webp' => array_merge(
                $webpCandidates,
                $jpegCandidates,
            ),
            default => $jpegCandidates,
        };
    }

    // ─── Helpers ───

    /**
     * Check if an Imagick image is animated (has > 1 frame).
     */
    private function isAnimated(\Imagick $imagick): bool
    {
        return $imagick->getNumberImages() > 1;
    }

    /**
     * Scale dimensions to fit within the configured maxDimension bounding box,
     * preserving aspect ratio.
     *
     * @return array{int, int} [newWidth, newHeight]
     */
    private function scaleToFit(int $width, int $height): array
    {
        if ($width <= $this->config->maxDimension && $height <= $this->config->maxDimension) {
            return [$width, $height];
        }

        $ratio = min(
            $this->config->maxDimension / $width,
            $this->config->maxDimension / $height,
        );

        return [
            (int) round($width * $ratio),
            (int) round($height * $ratio),
        ];
    }

    /**
     * Write processed image blob to a deterministic cache file.
     *
     * @param non-empty-string $blob Binary image data
     * @param string           $ext  File extension (no dot, e.g. "jpeg")
     *
     * @return non-empty-string|null Absolute path to the cache file, or null
     *                               when mkdir or file_put_contents fails
     */
    private function writeCache(string $blob, string $ext): ?string
    {
        $cacheDir = $this->tempDir().'/'.self::CACHE_DIR;

        if (!@mkdir($cacheDir, 0750, recursive: true) && !is_dir($cacheDir)) {
            return null;
        }

        $hash = md5($blob);
        $outPath = $cacheDir.'/'.$hash.'.'.$ext;

        if (!is_file($outPath)) {
            if (false === @file_put_contents($outPath, $blob) || !is_file($outPath)) {
                return null;
            }
            @chmod($outPath, 0640);
        }

        return $outPath;
    }

    /**
     * Map MIME type to a file extension for cache storage.
     */
    private function formatExtension(string $mediaType): string
    {
        return match ($mediaType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }

    private function tempDir(): string
    {
        return sys_get_temp_dir();
    }

    /**
     * Build an "original" metadata response (no processing applied).
     *
     * @return array{path: string, media_type: string, width: int, height: int, bytes: int, processed: bool, exceeds_encoded_limit?: bool, warning?: string}
     */
    private static function original(string $path, string $mediaType, int $width, int $height, int $bytes, bool $exceedsEncodedLimit = false, ?string $warning = null): array
    {
        $result = [
            'path' => $path,
            'media_type' => $mediaType,
            'width' => $width,
            'height' => $height,
            'bytes' => $bytes,
            'processed' => false,
        ];

        if ($exceedsEncodedLimit) {
            $result['exceeds_encoded_limit'] = true;
        }

        if (null !== $warning) {
            $result['warning'] = $warning;
        }

        return $result;
    }
}
