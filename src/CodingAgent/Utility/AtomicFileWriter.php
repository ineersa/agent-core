<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Utility;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Shared internal atomic file writer for complete-string payloads.
 *
 * Why this exists instead of plain {@see Filesystem::dumpFile()}:
 * dumpFile() derives the new file's mode from the destination's current
 * permissions (or 0666 & ~umask when the destination does not exist) and
 * cannot guarantee a caller-selected mode — e.g. 0600 credentials — before
 * the atomic rename publishes the file. It also performs no LOCK_EX /
 * full-byte write verification and reports failures only as IOException.
 * This writer therefore owns the complete-write + pre-publish mode +
 * checked-rename + cleanup mechanics for call sites that need exact
 * modes, and every failure path removes the temporary file.
 *
 * Scope: complete-string payloads only. Streaming writers (e.g. the file
 * mention index) keep their own specialized loops. JSONL append stores are
 * deliberately not routed through this writer.
 *
 * Semantics:
 *   - parent directory is created with $directoryMode (default 0755);
 *     a non-directory entry at the path fails with stage "mkdir"
 *   - a collision-resistant sibling temp file (<destination>.tmp.<16 hex>
 *     random) is written with LOCK_EX and verified byte-for-byte
 *   - when $fileMode is given (e.g. 0600 auth, 0644 artifacts) it is
 *     applied BEFORE the rename so the published file never has a
 *     broader mode; after the rename the mode is defensively re-asserted
 *     (best-effort, matching the previous credential-store behavior)
 *   - rename is checked; failure keeps the prior destination intact
 *   - every failure path unlinks the temp file before the exception
 *     propagates (best-effort unlink; the primary error is always
 *     reported)
 *
 * No hierarchy, strategy, or configuration surface beyond the two
 * optional mode arguments.
 */
final class AtomicFileWriter
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {
    }

    /**
     * Atomically publish $contents at $destination.
     *
     * @param string   $destination   Absolute destination path
     * @param string   $contents      Complete payload to write
     * @param int|null $fileMode      Optional mode applied before publish
     *                                (and re-asserted after), e.g. 0600, 0644
     * @param int|null $directoryMode Optional mode for the parent directory
     *                                when it must be created (default 0755)
     *
     * @throws AtomicFileWriterException with the failing stage when any
     *                                   step fails; the temp file is removed
     *                                   before the exception propagates
     */
    public function write(string $destination, string $contents, ?int $fileMode = null, ?int $directoryMode = null): void
    {
        $dir = \dirname($destination);
        $this->ensureDirectory($dir, $directoryMode ?? 0o755);

        $tmpPath = $destination.'.tmp.'.bin2hex(random_bytes(8));

        try {
            $written = file_put_contents($tmpPath, $contents, \LOCK_EX);
            if (false === $written || $written !== \strlen($contents)) {
                throw new AtomicFileWriterException('write', $tmpPath, \sprintf('Failed to write temporary file "%s".', $tmpPath));
            }

            if (null !== $fileMode) {
                // Checked pre-publish chmod: a failure must not publish the
                // file with a broader mode than requested (security).
                if (!@chmod($tmpPath, $fileMode)) {
                    throw new AtomicFileWriterException('chmod', $tmpPath, \sprintf('Failed to apply mode %o to temporary file "%s".', $fileMode, $tmpPath));
                }
            }

            try {
                $this->filesystem->rename($tmpPath, $destination, true);
            } catch (IOException $exception) {
                throw new AtomicFileWriterException('rename', $tmpPath, \sprintf('Failed to rename temporary file "%s" to "%s".', $tmpPath, $destination), $exception);
            }

            if (null !== $fileMode) {
                // Defensive re-assertion after publish (rename preserves the
                // temp mode; this guards against exotic umask/ACL interference
                // and is required for 0600 credentials). Best-effort: the file
                // is already published with the correct mode.
                @chmod($destination, $fileMode);
            }
        } catch (AtomicFileWriterException $exception) {
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }

            throw $exception;
        }
    }

    private function ensureDirectory(string $dir, int $mode): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (file_exists($dir)) {
            throw new AtomicFileWriterException('mkdir', null, \sprintf('Cannot create directory: a non-directory entry exists at "%s".', $dir));
        }

        if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new AtomicFileWriterException('mkdir', null, \sprintf('Failed to create directory "%s".', $dir));
        }
    }
}
