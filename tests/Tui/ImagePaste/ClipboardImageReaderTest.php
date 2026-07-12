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
    private ?string $originalXdgSessionType;
    private ?string $originalWaylandDisplay;
    /** @var list<string> */
    private array $createdTempPaths = [];

    protected function setUp(): void
    {
        $this->tempDir = TestDirectoryIsolation::createOsTempDir('clipboard-reader');
        $this->fakeBinDir = $this->tempDir.'/bin';
        mkdir($this->fakeBinDir, 0o755, true);
        $this->originalPath = (string) getenv('PATH');
        $this->originalXdgSessionType = false !== getenv('XDG_SESSION_TYPE') ? (string) getenv('XDG_SESSION_TYPE') : null;
        $this->originalWaylandDisplay = false !== getenv('WAYLAND_DISPLAY') ? (string) getenv('WAYLAND_DISPLAY') : null;
        putenv('PATH='.$this->fakeBinDir.':'.$this->originalPath);
        $_ENV['PATH'] = $this->fakeBinDir.':'.$this->originalPath;
        $_SERVER['PATH'] = $_ENV['PATH'];
    }

    protected function tearDown(): void
    {
        foreach ($this->createdTempPaths as $tempPath) {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
        $this->createdTempPaths = [];

        putenv('PATH='.$this->originalPath);
        $_ENV['PATH'] = $this->originalPath;
        $_SERVER['PATH'] = $this->originalPath;

        if (null === $this->originalXdgSessionType) {
            putenv('XDG_SESSION_TYPE');
            unset($_ENV['XDG_SESSION_TYPE'], $_SERVER['XDG_SESSION_TYPE']);
        } else {
            putenv('XDG_SESSION_TYPE='.$this->originalXdgSessionType);
            $_ENV['XDG_SESSION_TYPE'] = $this->originalXdgSessionType;
            $_SERVER['XDG_SESSION_TYPE'] = $this->originalXdgSessionType;
        }

        if (null === $this->originalWaylandDisplay) {
            putenv('WAYLAND_DISPLAY');
            unset($_ENV['WAYLAND_DISPLAY'], $_SERVER['WAYLAND_DISPLAY']);
        } else {
            putenv('WAYLAND_DISPLAY='.$this->originalWaylandDisplay);
            $_ENV['WAYLAND_DISPLAY'] = $this->originalWaylandDisplay;
            $_SERVER['WAYLAND_DISPLAY'] = $this->originalWaylandDisplay;
        }

        TestDirectoryIsolation::removeDirectory($this->tempDir);
        parent::tearDown();
    }

    #[Test]
    public function startPollCompletesSmallImageWithinConfiguredLimit(): void
    {
        $png = file_get_contents(__DIR__.'/../E2E/fixtures/paste-test-1x1.png');
        $this->assertNotFalse($png);
        $fixturePath = $this->tempDir.'/paste-test-1x1.png';
        file_put_contents($fixturePath, $png);
        $this->installScript('wl-paste', '#!/bin/sh'."\n".'cat '.escapeshellarg($fixturePath));

        putenv('XDG_SESSION_TYPE=wayland');
        putenv('WAYLAND_DISPLAY=wayland-test');

        $reader = new ClipboardImageReader(new ImageToolConfig(maxBytes: 4096), new TestLogger());
        $start = $reader->startRead();
        $this->assertTrue($start->started);
        $this->assertTrue($reader->isReading());

        $result = $this->pollUntilTerminal($reader);
        $this->assertFalse($reader->isReading());
        $this->assertSame(ClipboardImageReadOutcomeEnum::Image, $result->outcome);
        $this->assertNotNull($result->tempPath);
        $this->assertFileExists($result->tempPath);
        $this->assertSame($png, file_get_contents($result->tempPath));
        $this->createdTempPaths[] = $result->tempPath;
    }

    #[Test]
    public function rejectsOversizedClipboardStreamWithoutBufferingEntirePayload(): void
    {
        $this->installScript('wl-paste', '#!/bin/sh'."\n".'dd if=/dev/zero bs=1024 count=20 status=none');

        putenv('XDG_SESSION_TYPE=wayland');
        putenv('WAYLAND_DISPLAY=wayland-test');

        $reader = new ClipboardImageReader(new ImageToolConfig(maxBytes: 1024), new TestLogger());
        $this->assertTrue($reader->startRead()->started);
        $result = $this->pollUntilTerminal($reader);

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
        $this->assertTrue($reader->startRead()->started);
        $result = $this->pollUntilTerminal($reader);

        $this->assertSame(ClipboardImageReadOutcomeEnum::NoImage, $result->outcome);
    }

    #[Test]
    public function reportsBackendErrorWhenStderrPresentOnFailure(): void
    {
        $this->installScript('wl-paste', '#!/bin/sh'."\n".'echo "permission denied" 1>&2; exit 2');

        putenv('XDG_SESSION_TYPE=wayland');
        putenv('WAYLAND_DISPLAY=wayland-test');

        $reader = new ClipboardImageReader(new ImageToolConfig(), new TestLogger());
        $this->assertTrue($reader->startRead()->started);
        $result = $this->pollUntilTerminal($reader);

        $this->assertSame(ClipboardImageReadOutcomeEnum::Failed, $result->outcome);
        $this->assertStringContainsString('permission denied', strtolower($result->diagnostic ?? ''));
    }

    #[Test]
    public function xclipNoImageStderrYieldsNoImageOutcome(): void
    {
        $this->installScript('xclip', '#!/bin/sh'.'
echo "No image/png in clipboard" 1>&2; exit 1');

        putenv('XDG_SESSION_TYPE=');
        putenv('WAYLAND_DISPLAY=');

        $reader = new ClipboardImageReader(new ImageToolConfig(), new TestLogger());
        $this->assertTrue($reader->startRead()->started);
        $result = $this->pollUntilTerminal($reader);

        $this->assertSame(ClipboardImageReadOutcomeEnum::NoImage, $result->outcome);
    }

    #[Test]
    public function xclipRealErrorStderrYieldsFailedOutcome(): void
    {
        $this->installScript('xclip', '#!/bin/sh'.'
echo "xclip: Error: Can\'t open display" 1>&2; exit 1');

        putenv('XDG_SESSION_TYPE=');
        putenv('WAYLAND_DISPLAY=');

        $reader = new ClipboardImageReader(new ImageToolConfig(), new TestLogger());
        $this->assertTrue($reader->startRead()->started);
        $result = $this->pollUntilTerminal($reader);

        $this->assertSame(ClipboardImageReadOutcomeEnum::Failed, $result->outcome);
        $this->assertStringContainsString('display', strtolower($result->diagnostic ?? ''));
    }

    #[Test]
    public function hungClipboardBackendTimesOutAndCancelCleansUp(): void
    {
        $this->installScript('wl-paste', '#!/bin/sh'.'
sleep 30');

        putenv('XDG_SESSION_TYPE=wayland');
        putenv('WAYLAND_DISPLAY=wayland-test');

        $reader = new ClipboardImageReader(new ImageToolConfig(), new TestLogger());
        $this->assertTrue($reader->startRead()->started);

        $deadline = microtime(true) + 8.0;
        $result = null;
        while (microtime(true) < $deadline) {
            $poll = $reader->poll();
            if (!$poll->pending && null !== $poll->terminal) {
                $result = $poll->terminal;
                break;
            }
            usleep(20_000);
        }

        $this->assertNotNull($result);
        $this->assertSame(ClipboardImageReadOutcomeEnum::Failed, $result->outcome);
        $this->assertStringContainsString('timed out', strtolower($result->userMessage ?? ''));
        $this->assertNull($result->tempPath);
        $this->assertFalse($reader->isReading());

        $reader2 = new ClipboardImageReader(new ImageToolConfig(), new TestLogger());
        $this->assertTrue($reader2->startRead()->started);
        $reader2->cancel();
        $this->assertFalse($reader2->isReading());
    }

    private function pollUntilTerminal(ClipboardImageReader $reader, int $maxSteps = 500): \Ineersa\Tui\ImagePaste\ClipboardImageReadResultDTO
    {
        for ($i = 0; $i < $maxSteps; ++$i) {
            $poll = $reader->poll();
            if (!$poll->pending && null !== $poll->terminal) {
                return $poll->terminal;
            }
            usleep(5_000);
        }

        $this->fail('Clipboard read did not complete within poll budget');
    }

    private function installScript(string $name, string $contents): void
    {
        file_put_contents($this->fakeBinDir.'/'.$name, $contents."\n");
        chmod($this->fakeBinDir.'/'.$name, 0o755);
    }
}
