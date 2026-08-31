<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Utility;

/**
 * Atomic complete-string write: Symfony's dumpFile() cannot guarantee a
 * caller-selected mode before publish or full-byte LOCK_EX verification.
 */
final class AtomicFileWriter
{
    public static function write(string $destination, string $contents, ?int $fileMode = null, ?int $directoryMode = null): void
    {
        $dir = \dirname($destination);
        if (!is_dir($dir) && !@mkdir($dir, $directoryMode ?? 0o755, true) && !is_dir($dir)) {
            throw new AtomicFileWriterException('mkdir', \sprintf('Failed to create directory "%s".', $dir));
        }

        $tmpPath = $destination.'.tmp.'.bin2hex(random_bytes(8));

        try {
            // @: Symfony's debug ErrorHandler would convert a warning into an ErrorException.
            $written = @file_put_contents($tmpPath, $contents, \LOCK_EX);
            if (false === $written || $written !== \strlen($contents)) {
                throw new AtomicFileWriterException('write', \sprintf('Failed to write temporary file "%s".', $tmpPath));
            }

            if (null !== $fileMode && !@chmod($tmpPath, $fileMode)) {
                throw new AtomicFileWriterException('chmod', \sprintf('Failed to apply mode %o to temporary file "%s".', $fileMode, $tmpPath));
            }

            if (!@rename($tmpPath, $destination)) {
                throw new AtomicFileWriterException('rename', \sprintf('Failed to rename temporary file "%s" to "%s".', $tmpPath, $destination));
            }

            if (null !== $fileMode) {
                @chmod($destination, $fileMode);
            }
        } catch (\Throwable $exception) {
            @unlink($tmpPath);

            throw $exception;
        }
    }
}
