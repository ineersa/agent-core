<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Claude;

use Ineersa\HatfieldExt\ObservationalMemory\DTO\ClaudeTranscriptCursorDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;

/**
 * Counts the complete lines of an append-only Claude Code transcript,
 * reading only the bytes added since the previous turn.
 *
 * A per-session cursor (inode, byte offset after the last counted newline,
 * line count) lives in the plugin data directory. Cursors are written
 * atomically and need no lock: concurrent hooks each store a consistent
 * pair, and a stale one only means a few bytes are counted again. A missing,
 * unreadable or invalid cursor, a replaced file or a shrunken file falls back
 * to counting from the start.
 */
final readonly class ClaudeTranscriptLineCounter
{
    private const int READ_CHUNK_BYTES = 1_048_576;

    public function __construct(private string $cursorDirectory, private LoggerInterface $logger)
    {
    }

    public function completeLines(string $sessionId, string $path): int
    {
        $handle = fopen($path, 'rb');
        if (false === $handle) {
            throw new \RuntimeException('Cannot open the Claude Code transcript.');
        }
        try {
            $stat = fstat($handle);
            if (false === $stat) {
                throw new \RuntimeException('Cannot stat the Claude Code transcript.');
            }
            $cursor = $this->cursor($sessionId);
            if (null === $cursor || $cursor->inode !== $stat['ino'] || $cursor->offset > $stat['size']) {
                $cursor = new ClaudeTranscriptCursorDTO();
                $cursor->inode = $stat['ino'];
            }
            if (0 !== fseek($handle, $cursor->offset)) {
                throw new \RuntimeException('Cannot seek the Claude Code transcript.');
            }
            // Librarian ignores an unterminated last line (still being written):
            // count newlines, and resume just after the last one.
            $position = $cursor->offset;
            $lines = $cursor->lines;
            $offset = $cursor->offset;
            while (!feof($handle)) {
                $chunk = fread($handle, self::READ_CHUNK_BYTES);
                if (false === $chunk) {
                    throw new \RuntimeException('Cannot read the Claude Code transcript.');
                }
                $newlines = substr_count($chunk, "\n");
                if ($newlines > 0) {
                    $lines += $newlines;
                    $offset = $position + (int) strrpos($chunk, "\n") + 1;
                }
                $position += \strlen($chunk);
            }
            if ($offset !== $cursor->offset) {
                $next = new ClaudeTranscriptCursorDTO();
                $next->inode = $cursor->inode;
                $next->offset = $offset;
                $next->lines = $lines;
                (new Filesystem())->dumpFile($this->cursorPath($sessionId), (string) json_encode($next, \JSON_THROW_ON_ERROR));
            }

            return $lines;
        } finally {
            fclose($handle);
        }
    }

    /** The session ended: its transcript will not grow again. */
    public function forget(string $sessionId): void
    {
        (new Filesystem())->remove($this->cursorPath($sessionId));
    }

    /** Removes cursors of sessions that ended without a SessionEnd hook (crash, kill). */
    public function prune(int $maxAgeSeconds): void
    {
        $files = glob($this->cursorDirectory.'/*.json');
        if (false === $files) {
            return;
        }
        $cutoff = time() - $maxAgeSeconds;
        foreach ($files as $file) {
            $modified = filemtime($file);
            if (false !== $modified && $modified < $cutoff) {
                (new Filesystem())->remove($file);
            }
        }
    }

    private function cursor(string $sessionId): ?ClaudeTranscriptCursorDTO
    {
        $file = $this->cursorPath($sessionId);
        if (!is_file($file)) {
            return null;
        }
        try {
            $cursor = (new Serializer([new ObjectNormalizer(propertyTypeExtractor: new ReflectionExtractor())], [new JsonEncoder()]))
                ->deserialize((string) file_get_contents($file), ClaudeTranscriptCursorDTO::class, 'json');
            if (0 !== \count(Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($cursor))) {
                throw new \UnexpectedValueException('Invalid transcript cursor.');
            }

            return $cursor;
        } catch (\Throwable $error) {
            // Intentional local degradation: recount from the start and overwrite the cursor.
            $this->logger->warning('om.claude.transcript_cursor_invalid', [
                'component' => 'observational_memory', 'event_type' => 'om.claude.transcript_cursor_invalid',
                'run_id' => $sessionId, 'session_id' => $sessionId,
                'exception_class' => $error::class,
            ]);

            return null;
        }
    }

    private function cursorPath(string $sessionId): string
    {
        // The session id is a validated UUID, so it is a safe file name.
        return $this->cursorDirectory.'/'.$sessionId.'.json';
    }
}
