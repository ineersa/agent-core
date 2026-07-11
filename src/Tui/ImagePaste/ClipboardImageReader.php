<?php

declare(strict_types=1);

namespace Ineersa\Tui\ImagePaste;

use Ineersa\CodingAgent\Config\ImageToolConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Reads image/png (or convertible) clipboard data via wl-paste, xclip, or pngpaste.
 *
 * Order: Wayland wl-paste, macOS pngpaste, then X11 xclip. OSC 52 and inline terminal
 * image protocols are intentionally unsupported (GitHub issue #119 MVP).
 */
final class ClipboardImageReader implements ClipboardImageReaderInterface
{
    private const float PROCESS_TIMEOUT = 5.0;

    private readonly ExecutableFinder $executableFinder;

    public function __construct(
        private readonly ImageToolConfig $imageConfig,
        private readonly LoggerInterface $logger,
    ) {
        $this->executableFinder = new ExecutableFinder();
    }

    public function readImageToTempFile(): ClipboardImageReadResultDTO
    {
        $backend = $this->resolveBackend();
        if (null === $backend) {
            return ClipboardImageReadResultDTO::unavailable(
                'Image clipboard is unavailable (install wl-paste, xclip, or pngpaste).',
            );
        }

        try {
            return $this->captureFromBackend($backend);
        } catch (\Throwable $e) {
            $this->logger->info('Clipboard image read failed', [
                'component' => 'ClipboardImageReader',
                'event_type' => 'clipboard_read_failed',
                'backend' => $backend['name'],
                'exception' => $e,
            ]);

            return ClipboardImageReadResultDTO::failed(
                'Failed to read image from clipboard.',
                $e->getMessage(),
            );
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

    /**
     * @param array{name: string, argv: list<string>} $backend
     */
    private function captureFromBackend(array $backend): ClipboardImageReadResultDTO
    {
        $maxBytes = $this->imageConfig->maxBytes;
        $tempPath = $this->createEmptyTempFile();
        if (null === $tempPath) {
            return ClipboardImageReadResultDTO::failed('Failed to stage clipboard image.');
        }

        $process = new Process($backend['argv']);
        $process->setTimeout(self::PROCESS_TIMEOUT);
        $process->start();

        $written = 0;
        $tooLarge = false;
        $timedOut = false;
        $keepTempFile = false;
        $fileHandle = @fopen($tempPath, 'wb');
        if (false === $fileHandle) {
            $this->stopCaptureProcess($process);
            @unlink($tempPath);

            return ClipboardImageReadResultDTO::failed('Failed to stage clipboard image.');
        }

        try {
            while ($process->isRunning()) {
                try {
                    $process->checkTimeout();
                } catch (ProcessTimedOutException) {
                    $timedOut = true;
                    $this->stopCaptureProcess($process);
                    break;
                }

                $buffer = $process->getIncrementalOutput();
                if ('' !== $buffer) {
                    $length = \strlen($buffer);
                    if ($written + $length > $maxBytes) {
                        $tooLarge = true;
                        $this->stopCaptureProcess($process);
                        break;
                    }

                    $bytesWritten = @fwrite($fileHandle, $buffer);
                    if (false === $bytesWritten) {
                        $this->stopCaptureProcess($process);

                        return ClipboardImageReadResultDTO::failed('Failed to stage clipboard image.');
                    }

                    $written += $bytesWritten;
                }

                usleep(10_000);
            }

            if (!$tooLarge && !$timedOut) {
                try {
                    $process->checkTimeout();
                } catch (ProcessTimedOutException) {
                    $timedOut = true;
                    $this->stopCaptureProcess($process);
                }
            }

            if (!$tooLarge && !$timedOut) {
                $buffer = $process->getIncrementalOutput();
                if ('' !== $buffer) {
                    $length = \strlen($buffer);
                    if ($written + $length > $maxBytes) {
                        $tooLarge = true;
                    } else {
                        $bytesWritten = @fwrite($fileHandle, $buffer);
                        if (false !== $bytesWritten) {
                            $written += $bytesWritten;
                        }
                    }
                }
            }

            if ($timedOut) {
                $this->logger->info('Clipboard backend timed out', [
                    'component' => 'ClipboardImageReader',
                    'event_type' => 'clipboard_backend_timeout',
                    'backend' => $backend['name'],
                    'timeout_seconds' => self::PROCESS_TIMEOUT,
                ]);

                return ClipboardImageReadResultDTO::failed('Failed to read image from clipboard (timed out).');
            }

            if ($tooLarge) {
                return ClipboardImageReadResultDTO::failed('Clipboard image is too large.');
            }

            $process->wait();
            $stderr = trim($process->getErrorOutput());

            if (!$process->isSuccessful()) {
                $exit = $process->getExitCode();

                if ($this->isNoImageExit($backend['name'], $exit, $stderr, $written)) {
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

                return ClipboardImageReadResultDTO::failed(
                    'Failed to read image from clipboard.',
                    $this->sanitizeDiagnostic('' !== $stderr ? $stderr : 'clipboard command failed'),
                );
            }

            if (0 === $written) {
                return ClipboardImageReadResultDTO::noImage(
                    'Clipboard does not contain a supported image (JPEG, PNG, GIF, or WebP).',
                );
            }

            $keepTempFile = true;

            return ClipboardImageReadResultDTO::image($tempPath);
        } catch (\Throwable $e) {
            $this->stopCaptureProcess($process);
            $this->logger->info('Clipboard image capture failed', [
                'component' => 'ClipboardImageReader',
                'event_type' => 'clipboard_capture_failed',
                'backend' => $backend['name'],
                'exception' => $e,
            ]);

            return ClipboardImageReadResultDTO::failed(
                'Failed to read image from clipboard.',
                $this->sanitizeDiagnostic($e->getMessage()),
            );
        } finally {
            if (\is_resource($fileHandle)) {
                @fclose($fileHandle);
            }

            if (!$keepTempFile) {
                @unlink($tempPath);
            }
        }
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
        if ($written > 0) {
            return false;
        }

        if (null !== $exit && 1 === $exit && '' === $stderr) {
            return true;
        }

        if ('wl-paste' === $backend && null !== $exit && 1 === $exit
            && str_contains(strtolower($stderr), 'no data')) {
            return true;
        }

        $stderrLower = strtolower($stderr);

        if ('xclip' === $backend && null !== $exit && 1 === $exit) {
            if ('' === $stderr) {
                return true;
            }

            if (str_contains($stderrLower, 'no image/png')
                || str_contains($stderrLower, 'no image types')
                || str_contains($stderrLower, 'no selection')
                || str_contains($stderrLower, 'not available')) {
                return true;
            }
        }

        if ('pngpaste' === $backend && null !== $exit && 1 === $exit) {
            if (str_contains($stderrLower, 'no image')
                || str_contains($stderrLower, 'clipboard doesn\'t contain')
                || str_contains($stderrLower, 'clipboard does not contain')) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeDiagnostic(string $diagnostic): string
    {
        if (\strlen($diagnostic) > 500) {
            return substr($diagnostic, 0, 500).'…';
        }

        return $diagnostic;
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
