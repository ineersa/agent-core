<?php

declare(strict_types=1);

namespace Ineersa\Tui\ImagePaste;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Reads image/png (or convertible) clipboard data via wl-paste, xclip, or pngpaste.
 *
 * Order: Wayland wl-paste, X11 xclip, macOS pngpaste. OSC 52 and inline terminal
 * image protocols are intentionally unsupported (GitHub issue #119 MVP).
 */
final class ClipboardImageReader implements ClipboardImageReaderInterface
{
    private const int MAX_CLIPBOARD_BYTES = 12_582_912;
    private const float PROCESS_TIMEOUT = 5.0;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
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
            $bytes = $this->captureFromBackend($backend);
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

        if (null === $bytes || '' === $bytes) {
            return ClipboardImageReadResultDTO::noImage(
                'Clipboard does not contain a supported image (JPEG, PNG, GIF, or WebP).',
            );
        }

        if (\strlen($bytes) > self::MAX_CLIPBOARD_BYTES) {
            return ClipboardImageReadResultDTO::failed('Clipboard image is too large.');
        }

        $tempPath = $this->writeTempFile($bytes);
        if (null === $tempPath) {
            return ClipboardImageReadResultDTO::failed('Failed to stage clipboard image.');
        }

        return ClipboardImageReadResultDTO::image($tempPath);
    }

    /**
     * @return array{name: string, argv: list<string>}|null
     */
    private function resolveBackend(): ?array
    {
        if ($this->isWayland() && $this->commandExists('wl-paste')) {
            return [
                'name' => 'wl-paste',
                'argv' => ['wl-paste', '--type', 'image/png', '--no-newline'],
            ];
        }

        if ($this->commandExists('xclip')) {
            return [
                'name' => 'xclip',
                'argv' => ['xclip', '-selection', 'clipboard', '-t', 'image/png', '-o'],
            ];
        }

        if ('Darwin' === \PHP_OS_FAMILY && $this->commandExists('pngpaste')) {
            return [
                'name' => 'pngpaste',
                'argv' => ['pngpaste', '-'],
            ];
        }

        return null;
    }

    /**
     * @param array{name: string, argv: list<string>} $backend
     */
    private function captureFromBackend(array $backend): ?string
    {
        $process = new Process($backend['argv']);
        $process->setTimeout(self::PROCESS_TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $exit = $process->getExitCode();
            if (null !== $exit && 1 === $exit) {
                return null;
            }

            throw new \RuntimeException('' !== $process->getErrorOutput() ? $process->getErrorOutput() : 'clipboard command failed');
        }

        $output = $process->getOutput();
        if ('' === $output) {
            return null;
        }

        return $output;
    }

    private function writeTempFile(string $bytes): ?string
    {
        $temp = tempnam(sys_get_temp_dir(), 'hatfield-paste-');
        if (false === $temp) {
            return null;
        }

        @chmod($temp, 0o600);

        if (false === file_put_contents($temp, $bytes, \LOCK_EX)) {
            @unlink($temp);

            return null;
        }

        return $temp;
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

    private function commandExists(string $cmd): bool
    {
        try {
            $which = new Process(['which', $cmd]);
            $which->setTimeout(2.0);
            $which->run();

            return $which->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }
}
