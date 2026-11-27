<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\ImagePaste;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\ImageToolConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\ImagePaste\ClipboardImageReader;
use Ineersa\Tui\ImagePaste\ClipboardImageReadOutcomeEnum;
use Ineersa\Tui\ImagePaste\ClipboardImageReadResultDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

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
    public function cancelStopsInFlightClipboardBackendWithoutWaitingForTimeout(): void
    {
        // Intentional coverage choice: the old elapsed hang→PROCESS_TIMEOUT(5.0)→
        // Failed("timed out") case was removed because it used wall-clock sleep as
        // synchronization and was slow/low-value coverage (not because a 5s case is
        // impossible under the ≤10s ceiling). Cancel/cleanup is the deterministic
        // proof here; production still sets ClipboardImageReader::PROCESS_TIMEOUT
        // via setTimeout and handles ProcessTimedOutException.
        $this->installScript('wl-paste', '#!/bin/sh'.'
while true; do sleep 1; done');

        putenv('XDG_SESSION_TYPE=wayland');
        putenv('WAYLAND_DISPLAY=wayland-test');

        $reader = new ClipboardImageReader(new ImageToolConfig(), new TestLogger());
        $this->assertTrue($reader->startRead()->started);
        $this->assertTrue($reader->isReading());

        $reader->cancel();
        $this->assertFalse($reader->isReading());
    }

    #[Test]
    public function drainsRemainingOutputWhenProcessExitsBetweenRunningChecks(): void
    {
        $path = $this->tempDir.'/final-output.png';
        $process = $this->createMock(Process::class);
        $runningChecks = 0;
        $process->method('isRunning')->willReturnCallback(static function () use (&$runningChecks): bool {
            return 1 === ++$runningChecks;
        });
        $process->expects($this->exactly(2))->method('getIncrementalOutput')->willReturnCallback(static function () use (&$runningChecks): string {
            return $runningChecks < 2 ? 'first' : 'tail';
        });
        $process->method('isSuccessful')->willReturn(true);
        $reader = new ClipboardImageReader(new ImageToolConfig(), new TestLogger());
        foreach (['process' => $process, 'backend' => ['name' => 'wl-paste', 'argv' => []], 'tempPath' => $path, 'fileHandle' => fopen($path, 'wb')] as $name => $value) {
            (new \ReflectionProperty($reader, $name))->setValue($reader, $value);
        }

        $result = $reader->poll();

        $this->assertSame(ClipboardImageReadOutcomeEnum::Image, $result->terminal?->outcome);
        $this->assertSame('firsttail', file_get_contents($path));
        $this->assertFalse($reader->isReading());
    }

    #[Test]
    #[DataProvider('noImageExits')]
    public function noImageExitClassification(string $backend, ?int $exit, string $stderr, int $written, bool $expected): void
    {
        $reader = new ClipboardImageReader(new ImageToolConfig(), new TestLogger());
        $this->assertSame($expected, (new \ReflectionMethod($reader, 'isNoImageExit'))->invoke($reader, $backend, $exit, $stderr, $written));
    }

    public static function noImageExits(): iterable
    {
        foreach (['wl-paste' => ['NO DATA'], 'xclip' => ['no image/png', 'no image types', 'no selection', 'not available'], 'pngpaste' => ['no image', "clipboard doesn't contain", 'clipboard does not contain']] as $backend => $markers) {
            yield $backend.' empty' => [$backend, 1, '', 0, true];
            foreach ($markers as $marker) {
                yield $backend.' '.$marker => [$backend, 1, $marker, 0, true];
            }
            yield $backend.' wrong exit' => [$backend, 2, $markers[0], 0, false];
            yield $backend.' output' => [$backend, 1, $markers[0], 1, false];
            yield $backend.' real error' => [$backend, 1, 'permission denied', 0, false];
        }
        yield 'missing exit' => ['xclip', null, '', 0, false];
    }

    private function pollUntilTerminal(ClipboardImageReader $reader, int $maxSteps = 500): ClipboardImageReadResultDTO
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
