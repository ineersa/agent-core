<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\ImagePaste;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\ImageProcessing\ImageFileInspector;
use Ineersa\Tui\ImagePaste\PastedImageValidationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PastedImageValidationServiceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = TestDirectoryIsolation::createOsTempDir('image-validation');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->directory);
    }

    #[DataProvider('refusals')]
    public function testPreservesRefusalMessages(?string $content, ImageToolConfig $config, string $message): void
    {
        $path = $this->directory.'/image';
        if (null !== $content) {
            file_put_contents($path, $content);
        }
        $service = new PastedImageValidationService(new ImageFileInspector($config), new TestLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($message);
        try {
            $service->validateFile($path);
        } catch (\RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
            throw $e;
        }
    }

    public static function refusals(): iterable
    {
        yield 'missing' => [null, new ImageToolConfig(), 'Pasted image file is not readable.'];
        yield 'empty' => ['', new ImageToolConfig(), 'Pasted image appears empty.'];
        yield 'size before MIME' => ['hello', new ImageToolConfig(maxBytes: 4), 'Pasted image exceeds maximum size of 4 bytes (actual: 5).'];
        yield 'unsupported MIME' => ['This is plain text.', new ImageToolConfig(), 'Unsupported pasted image type "text/plain".'];
        yield 'damaged image' => ['GIF89a', new ImageToolConfig(), 'Failed to read pasted image dimensions.'];
    }

    public function testPreservesDimensionRefusalAndHeaderOnlyClassification(): void
    {
        $path = $this->directory.'/large.png';
        imagepng(imagecreatetruecolor(3, 2), $path);
        $service = new PastedImageValidationService(new ImageFileInspector(new ImageToolConfig(maxWidth: 2, maxHeight: 1)), new TestLogger());
        $this->assertTrue($service->isSupportedImageFile($path));
        $this->expectExceptionMessage('Pasted image dimensions (3x2) exceed maximum allowed (2x1).');
        $service->validateFile($path);
    }

    public function testClassifiesNonImagesAsTextAndValidatesByContent(): void
    {
        $service = new PastedImageValidationService(new ImageFileInspector(new ImageToolConfig()), new TestLogger());
        $path = $this->directory.'/misnamed.txt';
        $this->assertFalse($service->isSupportedImageFile($path));
        file_put_contents($path, '');
        $this->assertFalse($service->isSupportedImageFile($path));
        file_put_contents($path, 'plain text');
        $this->assertFalse($service->isSupportedImageFile($path));
        imagepng(imagecreatetruecolor(1, 1), $path);
        $this->assertTrue($service->isSupportedImageFile($path));
        $this->assertSame('png', $service->validateFile($path)->extension);
    }
}
