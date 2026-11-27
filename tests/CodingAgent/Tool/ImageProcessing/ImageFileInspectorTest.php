<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\ImageProcessing;

use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\ImageProcessing\DTO\ImageInspectionDTO;
use Ineersa\CodingAgent\Tool\ImageProcessing\DTO\ImageInspectionRefusalDTO;
use Ineersa\CodingAgent\Tool\ImageProcessing\ImageFileInspector;
use Ineersa\CodingAgent\Tool\ImageProcessing\ImageInspectionRefusalReasonEnum;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageFileInspectorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = TestDirectoryIsolation::createOsTempDir('image-inspector');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->directory);
    }

    #[DataProvider('supportedImages')]
    public function testInspectsSupportedContentAtExactLimits(string $writer, string $mediaType, string $extension): void
    {
        $path = $this->directory.'/misnamed.txt';
        $writer(imagecreatetruecolor(3, 2), $path);
        $bytes = filesize($path);
        $inspector = new ImageFileInspector(new ImageToolConfig(maxBytes: $bytes, maxWidth: 3, maxHeight: 2));
        $result = $inspector->inspect($path);
        $this->assertInstanceOf(ImageInspectionDTO::class, $result);
        $this->assertSame($mediaType, $result->mediaType);
        $this->assertSame($bytes, $result->bytes);
        $this->assertSame(3, $result->width);
        $this->assertSame(2, $result->height);
        $this->assertSame($extension, ImageFileInspector::extensionForMime($result->mediaType));
        $this->assertTrue($inspector->isSupportedImageFile($path));
    }

    public static function supportedImages(): iterable
    {
        yield 'PNG' => ['imagepng', 'image/png', 'png'];
        yield 'JPEG' => ['imagejpeg', 'image/jpeg', 'jpg'];
        yield 'GIF' => ['imagegif', 'image/gif', 'gif'];
        yield 'WebP' => ['imagewebp', 'image/webp', 'webp'];
    }

    #[DataProvider('refusals')]
    public function testReturnsTypedRefusal(?string $content, ImageToolConfig $config, ImageInspectionRefusalReasonEnum $reason): void
    {
        $path = $this->directory.'/file';
        if (null !== $content) {
            file_put_contents($path, $content);
        }
        $inspector = new ImageFileInspector($config);
        $result = $inspector->inspect($path);
        $this->assertInstanceOf(ImageInspectionRefusalDTO::class, $result);
        $this->assertSame($reason, $result->reason);
        $this->assertSame($config, $result->limits);
        if (ImageInspectionRefusalReasonEnum::TooLarge === $reason) {
            $this->assertSame(5, $result->bytes);
        }
        if (ImageInspectionRefusalReasonEnum::UnsupportedType === $reason) {
            $this->assertSame('text/plain', $result->mediaType);
        }
    }

    public static function refusals(): iterable
    {
        yield 'missing' => [null, new ImageToolConfig(), ImageInspectionRefusalReasonEnum::NotReadable];
        yield 'empty' => ['', new ImageToolConfig(), ImageInspectionRefusalReasonEnum::HeaderUnreadable];
        yield 'size before MIME' => ['hello', new ImageToolConfig(maxBytes: 4), ImageInspectionRefusalReasonEnum::TooLarge];
        yield 'unsupported MIME' => ['This is plain text.', new ImageToolConfig(), ImageInspectionRefusalReasonEnum::UnsupportedType];
        yield 'damaged image' => ['GIF89a', new ImageToolConfig(), ImageInspectionRefusalReasonEnum::DimensionsUnavailable];
    }

    #[DataProvider('dimensionLimits')]
    public function testReturnsDimensionValuesAndKeepsHeaderClassification(int $maxWidth, int $maxHeight): void
    {
        $path = $this->directory.'/dimensions.png';
        imagepng(imagecreatetruecolor(3, 2), $path);
        $inspector = new ImageFileInspector(new ImageToolConfig(maxWidth: $maxWidth, maxHeight: $maxHeight));
        $result = $inspector->inspect($path);
        $this->assertInstanceOf(ImageInspectionRefusalDTO::class, $result);
        $this->assertSame(ImageInspectionRefusalReasonEnum::DimensionsExceeded, $result->reason);
        $this->assertSame(3, $result->width);
        $this->assertSame(2, $result->height);
        $this->assertTrue($inspector->isSupportedImageFile($path));
    }

    public static function dimensionLimits(): iterable
    {
        yield 'width' => [2, 2];
        yield 'height' => [3, 1];
    }

    public function testUnknownCacheFormatKeepsBinaryFallback(): void
    {
        $this->assertSame('bin', ImageFileInspector::extensionForMime('application/octet-stream'));
    }
}
