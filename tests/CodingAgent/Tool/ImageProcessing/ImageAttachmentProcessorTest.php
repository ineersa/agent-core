<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\ImageProcessing;

use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Tool\ImageProcessing\ImageAttachmentProcessor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Tool\ImageProcessing\ImageAttachmentProcessor
 */
final class ImageAttachmentProcessorTest extends TestCase
{
    private ImageToolConfig $config;
    private ImageAttachmentProcessor $processor;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->config = new ImageToolConfig(
            maxBytes: 10_485_760,
            maxWidth: 4096,
            maxHeight: 2000,
            maxDimension: 2000,
            encodedMaxBytes: 4_718_592,
            jpegQuality: 80,
            jpegMinQuality: 40,
        );

        $this->processor = new ImageAttachmentProcessor($this->config);

        $this->tmpDir = \sys_get_temp_dir().'/hatfield_view_image_proc_test_'.\bin2hex(\random_bytes(8));
        \mkdir($this->tmpDir, 0750, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->tmpDir);
    }

    private function createPng(int $width, int $height, string $path): void
    {
        $img = \imagecreatetruecolor($width, $height);
        \imagepng($img, $path);
        \imagedestroy($img);
    }

    private function createJpeg(int $width, int $height, string $path): void
    {
        $img = \imagecreatetruecolor($width, $height);
        \imagejpeg($img, $path, 90);
        \imagedestroy($img);
    }

    /* ── Small images pass through unchanged ── */

    public function testSmallPngPassesThroughUnchanged(): void
    {
        $path = $this->tmpDir.'/small.png';
        $this->createPng(100, 100, $path);

        $result = $this->processor->process($path, 'image/png', 100, 100);

        self::assertFalse($result['processed'], 'Small image should not be processed');
        self::assertSame($path, $result['path']);
        self::assertSame(100, $result['width']);
        self::assertSame(100, $result['height']);
        self::assertSame('image/png', $result['media_type']);
    }

    public function testSmallJpegPassesThroughUnchanged(): void
    {
        $path = $this->tmpDir.'/small.jpg';
        $this->createJpeg(100, 100, $path);

        $result = $this->processor->process($path, 'image/jpeg', 100, 100);

        self::assertFalse($result['processed'], 'Small JPEG should not be processed');
        self::assertSame($path, $result['path']);
    }

    /* ── Large images get resized ── */

    public function testLargePngIsResizedToMaxDimension(): void
    {
        if (!\extension_loaded('imagick') && !\extension_loaded('gd')) {
            $this->markTestSkipped('No image processing library available');
        }

        $path = $this->tmpDir.'/large.png';
        $this->createPng(3000, 2000, $path);

        $result = $this->processor->process($path, 'image/png', 3000, 2000);

        self::assertTrue($result['processed'], 'Large image should be processed');
        self::assertNotSame($path, $result['path'], 'Processed image should be a temp file, not the original');
        self::assertLessThanOrEqual(2000, $result['width'], 'Width must be <= maxDimension');
        self::assertLessThanOrEqual(2000, $result['height'], 'Height must be <= maxDimension');
        self::assertFileExists($result['path']);
    }

    public function testLargeJpegIsResizedToMaxDimension(): void
    {
        if (!\extension_loaded('imagick') && !\extension_loaded('gd')) {
            $this->markTestSkipped('No image processing library available');
        }

        $path = $this->tmpDir.'/large.jpg';
        $this->createJpeg(3000, 1500, $path);

        $result = $this->processor->process($path, 'image/jpeg', 3000, 1500);

        self::assertTrue($result['processed'], 'Large JPEG should be processed');
        self::assertLessThanOrEqual(2000, $result['width']);
        self::assertLessThanOrEqual(2000, $result['height']);
        self::assertFileExists($result['path']);
    }

    /* ── Aspect ratio preservation ── */

    public function testAspectRatioIsPreservedAfterResize(): void
    {
        if (!\extension_loaded('imagick') && !\extension_loaded('gd')) {
            $this->markTestSkipped('No image processing library available');
        }

        $path = $this->tmpDir.'/aspect.png';
        $this->createPng(4000, 1000, $path);

        $result = $this->processor->process($path, 'image/png', 4000, 1000);

        self::assertTrue($result['processed']);
        // Original aspect: 4:1. After resize to fit max 2000:
        // width = 2000 (clamped), height = 1000 * (2000/4000) = 500
        self::assertSame(2000, $result['width']);
        self::assertSame(500, $result['height']);
        self::assertFileExists($result['path']);
    }

    /* ── Processor handles GD-only fallback ── */

    public function testProcessorWorksWithGdOnly(): void
    {
        if (!\extension_loaded('gd')) {
            $this->markTestSkipped('GD not available');
        }

        $path = $this->tmpDir.'/gd.png';
        $this->createPng(100, 100, $path);

        $result = $this->processor->process($path, 'image/png', 100, 100);

        self::assertFalse($result['processed']);
        self::assertSame($path, $result['path']);
    }

    /* ── Config-driven quality candidates ── */

    public function testEncodingCandidatesUseConfiguredQuality(): void
    {
        if (!\extension_loaded('imagick') && !\extension_loaded('gd')) {
            $this->markTestSkipped('No image processing library available');
        }

        // Use a moderate size image that needs resize but doesn't need massive
        // encoding loop iterations. 2500 wide → resize to 2000 → PNG candidate
        // should fit under default 4.7 MB limit in one pass.
        $config = new ImageToolConfig(
            maxBytes: 10_485_760,
            maxWidth: 4096,
            maxHeight: 2000,
            maxDimension: 2000,
            encodedMaxBytes: 4_718_592,
            jpegQuality: 70,
            jpegMinQuality: 30,
        );

        $processor = new ImageAttachmentProcessor($config);

        $path = $this->tmpDir.'/quality.png';
        $this->createPng(2500, 2000, $path);

        $result = $processor->process($path, 'image/png', 2500, 2000);

        self::assertTrue($result['processed'], 'Image should be processed with custom quality config');
        self::assertNotSame($path, $result['path']);
        self::assertLessThanOrEqual(2000, $result['width']);
        self::assertLessThanOrEqual(2000, $result['height']);
    }

    public function testExceedsEncodedLimitWarningPresentWhenLimitTiny(): void
    {
        if (!\extension_loaded('imagick') && !\extension_loaded('gd')) {
            $this->markTestSkipped('No image processing library available');
        }

        // Use a tiny encodedMaxBytes (1 byte) so the image will always exceed.
        // With a tiny image (20x20) the dimension loop exits immediately
        // (20 < 100), so this completes in one fallback pass — very fast.
        $config = new ImageToolConfig(
            maxBytes: 10_485_760,
            maxWidth: 4096,
            maxHeight: 2000,
            maxDimension: 2000,
            encodedMaxBytes: 1, // Any real image exceeds this
            jpegQuality: 80,
            jpegMinQuality: 40,
        );

        $processor = new ImageAttachmentProcessor($config);

        $path = $this->tmpDir.'/oversize_limit.png';
        $this->createPng(20, 20, $path);

        $result = $processor->process($path, 'image/png', 20, 20);

        self::assertTrue($result['processed']);
        self::assertArrayHasKey('exceeds_encoded_limit', $result);
        self::assertTrue($result['exceeds_encoded_limit']);
        self::assertArrayHasKey('warning', $result);
        self::assertStringContainsString('may exceed provider size limits', $result['warning']);
    }

    /* ── Cache cleanup ── */

    public function testCleanCacheRemovesExpiredFiles(): void
    {
        if (!\extension_loaded('imagick') && !\extension_loaded('gd')) {
            $this->markTestSkipped('No image processing library available');
        }

        // Use a moderate size image to trigger cache write
        $path = $this->tmpDir.'/cache_clean.png';
        $this->createPng(2500, 2000, $path);

        $result = $this->processor->process($path, 'image/png', 2500, 2000);

        self::assertTrue($result['processed']);
        self::assertFileExists($result['path']);

        // Clean with null (delete all) — should remove the cached file
        $deleted = $this->processor->cleanCache(null);

        self::assertGreaterThanOrEqual(1, $deleted, 'Should delete at least one cached file');
        self::assertFileDoesNotExist($result['path']);
    }

    // ─── helpers ───

    private function rmDir(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir()
                ? \rmdir((string) $item)
                : \unlink((string) $item);
        }

        @\rmdir($path);
    }
}
