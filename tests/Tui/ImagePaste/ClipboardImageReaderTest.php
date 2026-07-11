<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\ImagePaste;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\ImagePaste\ClipboardImageReader;
use Ineersa\Tui\ImagePaste\ClipboardImageReadOutcomeEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClipboardImageReaderTest extends TestCase
{
    private string $tempDir;
    private string $fakeBinDir;
    private string $originalPath;

    protected function setUp(): void
    {
        $this->tempDir = TestDirectoryIsolation::createOsTempDir('clipboard-reader');
        $this->fakeBinDir = $this->tempDir.'/bin';
        mkdir($this->fakeBinDir, 0o755, true);
        $this->originalPath = (string) getenv('PATH');
        putenv('PATH='.$this->fakeBinDir.':'.$this->originalPath);
        $_ENV['PATH'] = $this->fakeBinDir.':'.$this->originalPath;
        $_SERVER['PATH'] = $_ENV['PATH'];
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
        $_ENV['PATH'] = $this->originalPath;
        $_SERVER['PATH'] = $this->originalPath;
        TestDirectoryIsolation::removeDirectory($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function streamsSmallImageWithinConfiguredLimit(): void
    {
        $png = file_get_contents(__DIR__.'/../E2E/fixtures/paste-test-1x1.png');
        $this->assertNotFalse($png);
        $fixturePath = $this->tempDir.'/paste-test-1x1.png';
        file_put_contents($fixturePath, $png);
        $this->installScript('wl-paste', '#!/bin/sh'."\n".'cat '.escapeshellarg($fixturePath));

        putenv('XDG_SESSION_TYPE=wayland');
        putenv('WAYLAND_DISPLAY=wayland-test');

        $reader = new ClipboardImageReader(new ImageToolConfig(maxBytes: 4096), new TestLogger());
        $result = $reader->readImageToTempFile();

        $this->assertSame(ClipboardImageReadOutcomeEnum::Image, $result->outcome);
        $this->assertNotNull($result->tempPath);
        $this->assertFileExists($result->tempPath);
        $this->assertSame($png, file_get_contents($result->tempPath));
    }

    #[Test]
    public function rejectsOversizedClipboardStreamWithoutBufferingEntirePayload(): void
    {
        $this->installScript('wl-paste', '#!/bin/sh'."\n".'dd if=/dev/zero bs=1024 count=20 status=none');

        putenv('XDG_SESSION_TYPE=wayland');
        putenv('WAYLAND_DISPLAY=wayland-test');

        $reader = new ClipboardImageReader(new ImageToolConfig(maxBytes: 1024), new TestLogger());
        $result = $reader->readImageToTempFile();

        $this->assertSame(ClipboardImageReadOutcomeEnum::Failed, $result->outcome);
        $this->assertStringContainsString('too large', strtolower($result->userMessage ?? ''));
    }

    #[Test]
    public function distinguishesNoImageExitFromBackendError(): void
    {
        $this->installScript('wl-paste', '#!/bin/sh'."\n".'echo "wl-paste: no data" 1>&2; exit 1');

        putenv('XDG_SESSION_TYPE=wayland');
        putenv('WAYLAND_DISPLAY=wayland-test');

        $reader = new ClipboardImageReader(new ImageToolConfig(), new TestLogger());
        $result = $reader->readImageToTempFile();

        $this->assertSame(ClipboardImageReadOutcomeEnum::NoImage, $result->outcome);
    }

    #[Test]
    public function reportsBackendErrorWhenStderrPresentOnFailure(): void
    {
        $this->installScript('wl-paste', '#!/bin/sh'."\n".'echo "permission denied" 1>&2; exit 2');

        putenv('XDG_SESSION_TYPE=wayland');
        putenv('WAYLAND_DISPLAY=wayland-test');

        $reader = new ClipboardImageReader(new ImageToolConfig(), new TestLogger());
        $result = $reader->readImageToTempFile();

        $this->assertSame(ClipboardImageReadOutcomeEnum::Failed, $result->outcome);
        $this->assertStringContainsString('permission denied', strtolower($result->diagnostic ?? ''));
    }

    private function installScript(string $name, string $contents): void
    {
        file_put_contents($this->fakeBinDir.'/'.$name, $contents."\n");
        chmod($this->fakeBinDir.'/'.$name, 0o755);
    }
}
