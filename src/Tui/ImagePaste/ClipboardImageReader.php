<?php

declare(strict_types=1);

namespace Ineersa\Tui\ImagePaste;

use Ineersa\CodingAgent\Config\ImageToolConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

use function Symfony\Component\String\u;

/**
 * Reads image/png (or convertible) clipboard data via wl-paste, xclip, or pngpaste.
 *
 * Capture is non-blocking: start() launches the helper; poll() drains output once
 * per TUI tick. Order: Wayland wl-paste, macOS pngpaste, then X11 xclip.
 */
final class ClipboardImageReader implements ClipboardImageReaderInterface
{
    private const float PROCESS_TIMEOUT = 5.0;

    private const array NO_IMAGE_STDERR_MARKERS = [
        'wl-paste' => ['no data'],
        'xclip' => ['no image/png', 'no image types', 'no selection', 'not available'],
        'pngpaste' => ['no image', "clipboard doesn't contain", 'clipboard does not contain'],
    ];

    private readonly ExecutableFinder $executableFinder;

    private ?Process $process = null;

    private ?string $tempPath = null;

    /** @var resource|null */
    private $fileHandle;

    private int $bytesWritten = 0;

    private bool $tooLarge = false;

    private bool $timedOut = false;

    private bool $writeFailed = false;

    /** @var array{name: string, argv: list<string>}|null */
    private ?array $backend = null;

    public function __construct(
        private readonly ImageToolConfig $imageConfig,
        private readonly LoggerInterface $logger,
    ) {
        $this->executableFinder = new ExecutableFinder();
    }

    public function __destruct()
    {
        $this->cancel();
    }

    public function isReading(): bool
    {
        return null !== $this->process;
    }

    public function startRead(): ClipboardImageReadStartResultDTO
    {
        if ($this->isReading()) {
            return ClipboardImageReadStartResultDTO::immediate(
                ClipboardImageReadResultDTO::failed('A clipboard image read is already in progress.'),
            );
        }

        $backend = $this->resolveBackend();
        if (null === $backend) {
            return ClipboardImageReadStartResultDTO::immediate(
                ClipboardImageReadResultDTO::unavailable(
                    'Image clipboard is unavailable (install wl-paste, xclip, or pngpaste).',
                ),
            );
        }

        $tempPath = $this->createEmptyTempFile();
        if (null === $tempPath) {
            return ClipboardImageReadStartResultDTO::immediate(
                ClipboardImageReadResultDTO::failed('Failed to stage clipboard image.'),
            );
        }

        $fileHandle = @fopen($tempPath, 'wb');
        if (false === $fileHandle) {
            @unlink($tempPath);

            return ClipboardImageReadStartResultDTO::immediate(
                ClipboardImageReadResultDTO::failed('Failed to stage clipboard image.'),
            );
        }

        try {
            $process = new Process($backend['argv']);
            $process->setTimeout(self::PROCESS_TIMEOUT);
            $process->start();

            $this->backend = $backend;
            $this->process = $process;
            $this->tempPath = $tempPath;
            $this->fileHandle = $fileHandle;
            $this->bytesWritten = 0;
            $this->tooLarge = false;
            $this->timedOut = false;
            $this->writeFailed = false;

            return ClipboardImageReadStartResultDTO::started();
        } catch (\Throwable $e) {
            if (\is_resource($fileHandle)) {
                @fclose($fileHandle);
            }
            @unlink($tempPath);
            $this->logger->info('Clipboard image read failed', [
                'component' => 'ClipboardImageReader',
                'event_type' => 'clipboard_read_failed',
                'backend' => $backend['name'],
                'exception' => $e,
            ]);

            return ClipboardImageReadStartResultDTO::immediate(
                ClipboardImageReadResultDTO::failed(
                    'Failed to read image from clipboard.',
                    $e->getMessage(),
                ),
            );
        }
    }

    public function poll(): ClipboardImageReadPollResultDTO
    {
        if (!$this->isReading()) {
            return ClipboardImageReadPollResultDTO::pending();
        }

        $process = $this->process;
        $backend = $this->backend;
        if (null === $process || null === $backend) {
            $this->resetOperation();

            return ClipboardImageReadPollResultDTO::terminal(
                ClipboardImageReadResultDTO::failed('Failed to read image from clipboard.'),
            );
        }

        $this->checkCaptureTimeout($process);
        if (!$this->captureStopped() && $process->isRunning()) {
            $this->drainOutput($process);
            if ($process->isRunning()) {
                return ClipboardImageReadPollResultDTO::pending();
            }
        }

        $this->checkCaptureTimeout($process);
        $this->drainOutput($process);

        return ClipboardImageReadPollResultDTO::terminal($this->finalizeCapture());
    }

    public function cancel(): void
    {
        if (null !== $this->process) {
            $this->stopCaptureProcess($this->process);
        }

        $this->closeFileHandle();
        if (null !== $this->tempPath) {
            @unlink($this->tempPath);
        }

        $this->resetOperation();
    }

    private function captureStopped(): bool
    {
        return $this->timedOut || $this->tooLarge || $this->writeFailed;
    }

    private function checkCaptureTimeout(Process $process): void
    {
        if ($this->captureStopped()) {
            return;
        }

        try {
            $process->checkTimeout();
        } catch (ProcessTimedOutException) {
            $this->timedOut = true;
            $this->stopCaptureProcess($process);
        }
    }

    private function drainOutput(Process $process): void
    {
        if ($this->captureStopped()) {
            return;
        }

        $buffer = $process->getIncrementalOutput();
        if ('' === $buffer) {
            return;
        }

        if ($this->bytesWritten + \strlen($buffer) > $this->imageConfig->maxBytes) {
            $this->tooLarge = true;
            $this->stopCaptureProcess($process);

            return;
        }

        $bytesWritten = @fwrite($this->fileHandle, $buffer);
        if (false === $bytesWritten) {
            $this->writeFailed = true;
            $this->stopCaptureProcess($process);
        } else {
            $this->bytesWritten += $bytesWritten;
        }
    }

    /**
     * @return array{name: string, argv: list<string>}|null
     */
    private function resolveBackend(): ?array
    {
        if ($this->isWayland() && null !== $this->findExecutable('wl-paste')) {
            return [
                'name' => 'wl-paste',
                'argv' => ['wl-paste', '--type', 'image/png', '--no-newline'],
            ];
        }

        if ('Darwin' === \PHP_OS_FAMILY && null !== $this->findExecutable('pngpaste')) {
            return [
                'name' => 'pngpaste',
                'argv' => ['pngpaste', '-'],
            ];
        }

        if (null !== $this->findExecutable('xclip')) {
            return [
                'name' => 'xclip',
                'argv' => ['xclip', '-selection', 'clipboard', '-t', 'image/png', '-o'],
            ];
        }

        return null;
    }

    private function finalizeCapture(): ClipboardImageReadResultDTO
    {
        $process = $this->process;
        $backend = $this->backend;
        $tempPath = $this->tempPath;
        $written = $this->bytesWritten;

        if (null === $process || null === $backend || null === $tempPath) {
            $this->cancel();

            return ClipboardImageReadResultDTO::failed('Failed to read image from clipboard.');
        }

        if ($this->timedOut) {
            $this->logger->info('Clipboard backend timed out', [
                'component' => 'ClipboardImageReader',
                'event_type' => 'clipboard_backend_timeout',
                'backend' => $backend['name'],
                'timeout_seconds' => self::PROCESS_TIMEOUT,
            ]);
            $this->cancel();

            return ClipboardImageReadResultDTO::failed('Failed to read image from clipboard (timed out).');
        }

        if ($this->tooLarge) {
            $this->cancel();

            return ClipboardImageReadResultDTO::failed('Clipboard image is too large.');
        }

        if ($this->writeFailed) {
            $this->cancel();

            return ClipboardImageReadResultDTO::failed('Failed to stage clipboard image.');
        }

        try {
            $process->wait();
        } catch (ProcessTimedOutException) {
            $this->logger->info('Clipboard backend timed out', [
                'component' => 'ClipboardImageReader',
                'event_type' => 'clipboard_backend_timeout',
                'backend' => $backend['name'],
                'timeout_seconds' => self::PROCESS_TIMEOUT,
            ]);
            $this->cancel();

            return ClipboardImageReadResultDTO::failed('Failed to read image from clipboard (timed out).');
        }

        $stderr = trim($process->getErrorOutput());

        if (!$process->isSuccessful()) {
            $exit = $process->getExitCode();

            if ($this->isNoImageExit($backend['name'], $exit, $stderr, $written)) {
                $this->cancel();

                return ClipboardImageReadResultDTO::noImage(
                    'Clipboard does not contain a supported image (JPEG, PNG, GIF, or WebP).',
                );
            }

            $this->logger->info('Clipboard backend returned error', [
                'component' => 'ClipboardImageReader',
                'event_type' => 'clipboard_backend_error',
                'backend' => $backend['name'],
                'exit_code' => $exit,
                'stderr' => $this->sanitizeDiagnostic($stderr),
            ]);
            $this->cancel();

            return ClipboardImageReadResultDTO::failed(
                'Failed to read image from clipboard.',
                $this->sanitizeDiagnostic('' !== $stderr ? $stderr : 'clipboard command failed'),
            );
        }

        if (0 === $written) {
            $this->cancel();

            return ClipboardImageReadResultDTO::noImage(
                'Clipboard does not contain a supported image (JPEG, PNG, GIF, or WebP).',
            );
        }

        $this->closeFileHandle();
        $this->resetOperation();

        return ClipboardImageReadResultDTO::image($tempPath);
    }

    private function resetOperation(): void
    {
        $this->process = null;
        $this->tempPath = null;
        $this->fileHandle = null;
        $this->backend = null;
        $this->bytesWritten = 0;
        $this->tooLarge = false;
        $this->timedOut = false;
        $this->writeFailed = false;
    }

    private function closeFileHandle(): void
    {
        if (\is_resource($this->fileHandle)) {
            @fclose($this->fileHandle);
        }
        $this->fileHandle = null;
    }

    private function stopCaptureProcess(Process $process): void
    {
        if ($process->isRunning()) {
            $process->stop(0, 15 /* SIGTERM */);
        }
    }

    private function createEmptyTempFile(): ?string
    {
        $temp = tempnam(sys_get_temp_dir(), 'hatfield-paste-');
        if (false === $temp) {
            return null;
        }

        @chmod($temp, 0o600);

        return $temp;
    }

    private function isNoImageExit(string $backend, ?int $exit, string $stderr, int $written): bool
    {
        if ($written > 0 || 1 !== $exit) {
            return false;
        }

        if ('' === $stderr) {
            return true;
        }

        $stderrLower = strtolower($stderr);
        foreach (self::NO_IMAGE_STDERR_MARKERS[$backend] ?? [] as $marker) {
            if (str_contains($stderrLower, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeDiagnostic(string $diagnostic): string
    {
        $text = u($diagnostic);

        return $text->length() > 500 ? $text->slice(0, 500)->append('…')->toString() : $diagnostic;
    }

    private function isWayland(): bool
    {
        $session = getenv('XDG_SESSION_TYPE');
        if (false !== $session && 'wayland' === strtolower($session)) {
            return true;
        }

        $wayland = getenv('WAYLAND_DISPLAY');

        return false !== $wayland && '' !== $wayland;
    }

    private function findExecutable(string $command): ?string
    {
        $path = $this->executableFinder->find($command);
        if (null === $path || !is_executable($path)) {
            return null;
        }

        return $path;
    }
}
