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

    /**
     * Encoding candidates per input format.
     * Format => list of {format: string, quality?: int}.
     * Quality descending; first match wins.
     *
     * @var array<string, list<array{format: string, quality?: int}>>
     */
    private const array ENCODING_CANDIDATES = [
        'image/jpeg' => [
            ['format' => 'jpeg', 'quality' => 80],
            ['format' => 'jpeg', 'quality' => 70],
            ['format' => 'jpeg', 'quality' => 55],
            ['format' => 'jpeg', 'quality' => 40],
            ['format' => 'png'],
            ['format' => 'jpeg', 'quality' => 40],
        ],
        'image/png' => [
            ['format' => 'png'],
            ['format' => 'jpeg', 'quality' => 80],
            ['format' => 'jpeg', 'quality' => 70],
            ['format' => 'jpeg', 'quality' => 55],
            ['format' => 'jpeg', 'quality' => 40],
        ],
        'image/gif' => [
            ['format' => 'gif'],
            ['format' => 'png'],
            ['format' => 'jpeg', 'quality' => 80],
            ['format' => 'jpeg', 'quality' => 70],
            ['format' => 'jpeg', 'quality' => 55],
            ['format' => 'jpeg', 'quality' => 40],
        ],
        'image/webp' => [
            ['format' => 'webp', 'quality' => 80],
            ['format' => 'webp', 'quality' => 70],
            ['format' => 'webp', 'quality' => 55],
            ['format' => 'webp', 'quality' => 40],
            ['format' => 'jpeg', 'quality' => 80],
            ['format' => 'jpeg', 'quality' => 70],
            ['format' => 'jpeg', 'quality' => 55],
            ['format' => 'jpeg', 'quality' => 40],
        ],
    ];

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
     * @return array{path: string, media_type: string, width: int, height: int, bytes: int, processed: bool}
     *                                                                                                       Processed image metadata. 'path' points to either the original
     *                                                                                                       file (no processing needed) or a cached processed artifact.
     *                                                                                                       'processed' is true when processing changed the file.
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
     * @return array{path: string, media_type: string, width: int, height: int, bytes: int, processed: bool}
     */
    private function processWithImagick(string $filePath, string $mediaType, int $width, int $height, int $fileSize): array
    {
        try {
            $img = new \Imagick($filePath);
            $img->autoOrientate(); // @phpstan-ignore method.notFound

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
                // Return original with a dimension note (the provider will receive
                // the large image or reject it; no silent corruption).
                $img->destroy();

                return self::original($filePath, $mediaType, $width, $height, $fileSize);
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

            // All encoding attempts failed — write resized original as fallback
            $fallbackImg = new \Imagick($filePath);
            if ($newW !== $width || $newH !== $height) {
                $fallbackImg->resizeImage($newW, $newH, \Imagick::FILTER_LANCZOS, 1);
            }
            $blob = $fallbackImg->getImageBlob();
            $fallbackImg->destroy();

            $outPath = $this->writeCache($blob, $this->formatExtension($mediaType));

            return [
                'path' => $outPath,
                'media_type' => $mediaType,
                'width' => $newW,
                'height' => $newH,
                'bytes' => \strlen($blob),
                'processed' => true,
            ];
        } catch (\Throwable) {
            return self::original($filePath, $mediaType, $width, $height, $fileSize);
        }
    }

    /**
     * Try encoding candidates with Imagick, reducing dimensions progressively.
     *
     * @return array{path: string, media_type: string, width: int, height: int, bytes: int, processed: bool}|null
     */
    private function tryImagickEncoding(\Imagick $img, string $mediaType, int $origW, int $origH): ?array
    {
        $candidates = self::ENCODING_CANDIDATES[$mediaType] ?? self::ENCODING_CANDIDATES['image/jpeg'];

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
     * @return array{path: string, media_type: string, width: int, height: int, bytes: int, processed: bool}
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
     * @return array{path: string, media_type: string, width: int, height: int, bytes: int, processed: bool}|null
     */
    private function tryGdEncoding(\GdImage $gd, string $mediaType, int $width, int $height): ?array
    {
        $candidates = self::ENCODING_CANDIDATES[$mediaType] ?? self::ENCODING_CANDIDATES['image/jpeg'];

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
     * @return non-empty-string Absolute path to the cache file
     */
    private function writeCache(string $blob, string $ext): string
    {
        $cacheDir = $this->tempDir().'/'.self::CACHE_DIR;
        @mkdir($cacheDir, 0750, recursive: true);

        $hash = md5($blob);
        $outPath = $cacheDir.'/'.$hash.'.'.$ext;

        if (!is_file($outPath)) {
            @file_put_contents($outPath, $blob);
        }

        return $outPath;
    }

    /**
     * Map MIME type to a file extension for cache storage.
     */
    private function formatExtension(string $mediaType): string
    {
        return match ($mediaType) {
            'image/jpeg' => 'jpeg',
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
     * @return array{path: string, media_type: string, width: int, height: int, bytes: int, processed: bool}
     */
    private static function original(string $path, string $mediaType, int $width, int $height, int $bytes): array
    {
        return [
            'path' => $path,
            'media_type' => $mediaType,
            'width' => $width,
            'height' => $height,
            'bytes' => $bytes,
            'processed' => false,
        ];
    }
}
