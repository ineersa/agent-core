<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Bootstrap;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\SerializerInterface;

/** Disposable, private, bounded transfer files. The archive remains authoritative. */
final readonly class SessionBootstrapSpoolStore
{
    public const int FRAME_BYTES = 32768;
    public const int MAX_BYTES = 8 * 1024 * 1024;
    private const int MAX_RECORDS = 2001;
    private const int TIMEOUT_SECONDS = 60;

    public function __construct(private HatfieldSessionStore $sessions, private LockFactory $locks, private Filesystem $filesystem, private SerializerInterface $serializer)
    {
    }

    /** @param list<TranscriptBlock> $blocks
     * @param array{status: string, model: ?string, turn_no: int} $resume */
    public function seal(string $runId, int $sequence, int $endOffset, int $anchor, array $blocks, array $resume): SessionBootstrapDescriptorDTO
    {
        if ($sequence < 1 || $endOffset < 1 || $anchor < 0) {
            throw new \InvalidArgumentException('Bootstrap requires a complete canonical cut.');
        }
        $lock = $this->locks->createLock('session-bootstrap-'.$runId);
        $lock->acquire(true);
        try {
            $dir = $this->directory($runId);
            $this->filesystem->mkdir($dir, 0700);
            $this->filesystem->chmod($dir, 0700);
            $this->removeTransfer($dir);
            $epochPath = $dir.'/epoch';
            $previous = is_file($epochPath) ? file_get_contents($epochPath) : '0';
            if (!\is_string($previous) || !ctype_digit(trim($previous)) || (int) $previous >= \PHP_INT_MAX) {
                throw new \RuntimeException('Invalid bootstrap epoch.');
            }
            $epoch = (int) $previous + 1;
            $this->filesystem->dumpFile($epochPath, (string) $epoch);
            $this->filesystem->chmod($epochPath, 0600);
            $id = bin2hex(random_bytes(16));
            $path = $dir.'/'.$id.'.jsonl';
            $stream = fopen($path, 'xb');
            if (false === $stream) {
                throw new \RuntimeException('Cannot create bootstrap spool.');
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            $records = 0;
            $viewBytes = 2;
            try {
                $this->filesystem->chmod($path, 0600);
                $values = (function () use ($blocks, $resume, $runId, &$viewBytes): \Generator {
                    yield $this->serializer->serialize(['kind' => 'resume', 'data' => $resume], 'json');
                    foreach ($blocks as $block) {
                        if ($block->runId !== $runId) {
                            throw new \InvalidArgumentException('Bootstrap contains a block from another run.');
                        }
                        $encoded = $this->serializer->serialize($block, 'json');
                        $viewBytes += \strlen($encoded) + 1;
                        if ($viewBytes > 4 * 1024 * 1024) {
                            throw new \LengthException('Bootstrap transcript exceeds its view budget.');
                        }
                        yield '{"kind":"block","data":'.$encoded.'}';
                    }
                })();
                foreach ($values as $value) {
                    $line = $value."\n";
                    $bytes += \strlen($line);
                    if ($bytes > self::MAX_BYTES || ++$records > self::MAX_RECORDS) {
                        throw new \LengthException('Bootstrap spool exceeds its transfer budget.');
                    }
                    if (!JsonlCodec::write($stream, $line)) {
                        throw new \RuntimeException('Cannot write bootstrap spool.');
                    }
                    hash_update($hash, $line);
                }
                if (!fflush($stream)) {
                    throw new \RuntimeException('Cannot flush bootstrap spool.');
                }
            } catch (\Throwable $exception) {
                fclose($stream);
                $this->filesystem->remove($path);
                throw $exception;
            }
            fclose($stream);
            $descriptor = new SessionBootstrapDescriptorDTO($runId, $id, $epoch, $sequence, $endOffset, $anchor, $records, $bytes, hash_final($hash));
            try {
                $this->filesystem->dumpFile($dir.'/active.json', json_encode($descriptor->toArray() + ['expires_at' => Clock::get()->now()->getTimestamp() + self::TIMEOUT_SECONDS], \JSON_THROW_ON_ERROR));
                $this->filesystem->chmod($dir.'/active.json', 0600);
            } catch (\Throwable $exception) {
                $this->filesystem->remove($path);
                throw $exception;
            }

            return $descriptor;
        } finally {
            $lock->release();
        }
    }

    /** Reads only the current token. Arbitrary UI paths never reach the filesystem.
     * @return \Generator<int, string> bounded raw byte chunks, not decoded products */
    public function frames(SessionBootstrapDescriptorDTO $descriptor): \Generator
    {
        $this->matchingManifest($descriptor);
        $stream = fopen($this->directory($descriptor->runId).'/'.$descriptor->bootstrapId.'.jsonl', 'rb');
        if (false === $stream) {
            throw new \RuntimeException('Bootstrap spool is unavailable.');
        }
        try {
            $hash = hash_init('sha256');
            $bytes = 0;
            while (!feof($stream)) {
                // Supersession, timeout and cancellation invalidate even an already-open transfer.
                $this->matchingManifest($descriptor);
                $chunk = fread($stream, self::FRAME_BYTES);
                if (false === $chunk) {
                    throw new \RuntimeException('Cannot read bootstrap frame.');
                }
                if ('' === $chunk) {
                    if (!feof($stream)) {
                        throw new \RuntimeException('Bootstrap transfer made no progress.');
                    }
                    break;
                }
                $bytes += \strlen($chunk);
                if ($bytes > $descriptor->bytes || $bytes > self::MAX_BYTES) {
                    throw new \RuntimeException('Bootstrap spool length changed.');
                }
                hash_update($hash, $chunk);
                yield $chunk;
                unset($chunk);
            }
            $this->matchingManifest($descriptor);
            if ($bytes !== $descriptor->bytes || !hash_equals($descriptor->checksum, hash_final($hash))) {
                throw new \RuntimeException('Bootstrap spool checksum mismatch.');
            }
        } finally {
            fclose($stream);
        }
    }

    /** A complete identity/cut acknowledgement, not just possession of a token. */
    public function acknowledge(SessionBootstrapDescriptorDTO $descriptor): void
    {
        $lock = $this->locks->createLock('session-bootstrap-'.$descriptor->runId);
        $lock->acquire(true);
        try {
            $this->matchingManifest($descriptor, true);
            $this->removeTransfer($this->directory($descriptor->runId));
        } finally {
            $lock->release();
        }
    }

    public function isActive(string $runId): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9_-]+$/D', $runId) && $this->sessions->exists($runId)
            && is_file($this->directory($runId).'/active.json');
    }

    /** Disconnect/cancellation cleanup. The scalar epoch remains to reject old mounts. */
    public function cancel(string $runId): void
    {
        if (!$this->sessions->exists($runId)) {
            return;
        }
        $lock = $this->locks->createLock('session-bootstrap-'.$runId);
        $lock->acquire(true);
        try {
            $this->removeTransfer($this->directory($runId));
        } finally {
            $lock->release();
        }
    }

    private function matchingManifest(SessionBootstrapDescriptorDTO $descriptor, bool $underLock = false): void
    {
        if (1 !== preg_match('/^[a-f0-9]{32}$/D', $descriptor->bootstrapId)) {
            throw new \InvalidArgumentException('Invalid bootstrap token.');
        }
        $dir = $this->directory($descriptor->runId);
        $path = $dir.'/active.json';
        if (!is_file($path)) {
            throw new \RuntimeException('Bootstrap transfer is no longer active.');
        }
        $encoded = file_get_contents($path, false, null, 0, 4096);
        if (false === $encoded) {
            throw new \RuntimeException('Cannot read bootstrap identity.');
        }
        $manifest = json_decode($encoded, true, 32, \JSON_THROW_ON_ERROR);
        if (!\is_array($manifest) || !\is_int($manifest['expires_at'] ?? null)) {
            throw new \RuntimeException('Invalid bootstrap identity.');
        }
        foreach ($descriptor->toArray() as $field => $value) {
            if (($manifest[$field] ?? null) !== $value) {
                throw new \RuntimeException('Bootstrap identity or committed cut changed.');
            }
        }
        if ($manifest['expires_at'] <= Clock::get()->now()->getTimestamp()) {
            if ($underLock) {
                $this->removeTransfer($dir);
            } else {
                $lock = $this->locks->createLock('session-bootstrap-'.$descriptor->runId);
                $lock->acquire(true);
                try {
                    // A newer transfer may have superseded this one before locking.
                    // Revalidate identity under the lock before removing anything.
                    $this->matchingManifest($descriptor, true);
                } finally {
                    $lock->release();
                }
            }
            throw new \RuntimeException('Bootstrap transfer expired.');
        }
    }

    private function directory(string $runId): string
    {
        if (1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $runId) || !$this->sessions->exists($runId)) {
            throw new \InvalidArgumentException('Bootstrap requires a registered parent session.');
        }

        return $this->sessions->resolveSessionsBasePath().'/'.$runId.'/runtime/bootstrap';
    }

    private function removeTransfer(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        // Interrupted seals can leave one private body without an active manifest.
        // Clean only transfer artifacts in this dedicated directory, never canonical files.
        foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $file) {
            if ('active.json' === $file->getFilename() || 1 === preg_match('/^[a-f0-9]{32}\.jsonl$/D', $file->getFilename())) {
                $this->filesystem->remove($file->getPathname());
            }
        }
    }
}
