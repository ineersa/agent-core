<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Symfony\Component\Filesystem\Filesystem;

/** Exact-byte storage append recovery. This does not finalize owner coordination. */
final readonly class JsonlAppendJournal
{
    private const int CHUNK_BYTES = 65536;
    private const int MAX_STAGED_BYTES = 33554432;
    private const int MAX_RECORD_BYTES = 16777216;

    public function __construct(private Filesystem $filesystem = new Filesystem())
    {
    }

    /** @param iterable<string> $records
     * @param array<string, mixed> $work */
    public function append(string $path, iterable $records, array $work): void
    {
        if (is_file($this->manifestPath($path))) {
            throw new \RuntimeException('Canonical append requires reconciliation of the existing pending append.');
        }
        $this->filesystem->mkdir(\dirname($path));
        if ([] === $work) {
            throw new \InvalidArgumentException('Canonical append requires captured owner coordination work.');
        }
        $archive = $this->open($path, 'c+b');
        $stage = $this->open($this->stagePath($path), 'wb');
        try {
            $stat = fstat($archive);
            if (false === $stat) {
                throw new \RuntimeException('Cannot inspect canonical archive.');
            }
            $length = 0;
            $sequences = [];
            $previousSequence = $work['predecessor_seq'] ?? 0;
            foreach ($records as $record) {
                $bytes = \strlen($record);
                if ($bytes > self::MAX_RECORD_BYTES || $length + $bytes > self::MAX_STAGED_BYTES) {
                    throw new \RuntimeException('Canonical append exceeds the staging byte limit.');
                }
                $this->writeAll($stage, $record);
                $length += $bytes;
                $decoded = json_decode($record, true, 512, \JSON_THROW_ON_ERROR);
                if (!\is_array($decoded) || !\is_int($decoded['seq'] ?? null) || $decoded['seq'] <= $previousSequence) {
                    throw new \RuntimeException('Staged event has no allocated sequence.');
                }
                $sequences[] = $decoded['seq'];
                $previousSequence = $decoded['seq'];
                unset($decoded);
            }
            if (!fflush($stage)) {
                throw new \RuntimeException('Cannot flush staged canonical bytes.');
            }
            $work['event_sequences'] = $sequences;
            foreach (['actions', 'after_turn_actions'] as $key) {
                foreach ($work[$key] ?? [] as $index => $action) {
                    if ($action instanceof \Ineersa\AgentCore\Contract\CanonicalSequenceBoundActionInterface) {
                        $work[$key][$index] = $action->bindCanonicalSequences($sequences);
                    }
                }
            }
            $workBytes = (new \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer())->encode(new \Symfony\Component\Messenger\Envelope(new PendingTransitionWorkDTO($work)));
            $encodedWork = json_encode($workBytes, \JSON_THROW_ON_ERROR);
            if (\strlen($encodedWork) > self::MAX_STAGED_BYTES) {
                throw new \RuntimeException('Prepared transition work exceeds its byte limit.');
            }
            $this->filesystem->dumpFile($path.'.append.work', $encodedWork);
            $boundaryLength = min(self::CHUNK_BYTES, $stat['size']);
            $boundary = $this->readAt($archive, $stat['size'] - $boundaryLength, $boundaryLength);
            $manifest = [
                'version' => 1,
                'offset' => $stat['size'],
                'device' => $stat['dev'],
                'inode' => $stat['ino'],
                'boundary_length' => $boundaryLength,
                'boundary_hash' => hash('sha256', $boundary),
                'length' => $length,
                'stage_hash' => hash_file('sha256', $this->stagePath($path)),
                'transition' => true,
                'work_hash' => hash_file('sha256', $path.'.append.work'),
            ];
            $this->filesystem->dumpFile($this->manifestPath($path), json_encode($manifest, \JSON_THROW_ON_ERROR));
        } finally {
            fclose($stage);
            fclose($archive);
        }
        $this->reconcile($path);
        // Pending intent files stay until required coordination succeeds.
        // Operation rows and batch authority own redispatch; do not copy full work.
    }

    /**
     * Completes only the exact prepared physical append. Callers must separately
     * recover owner coordination; this method never dispatches effects.
     */
    public function reconcile(string $path): void
    {
        $manifest = $this->manifest($path);
        if (null === $manifest) {
            return;
        }
        if (!is_file($path.'.append.work') || hash_file('sha256', $path.'.append.work') !== ($manifest['work_hash'] ?? null)) {
            throw new \RuntimeException('Prepared coordination work is missing or corrupt.');
        }
        if (!is_file($this->stagePath($path)) || hash_file('sha256', $this->stagePath($path)) !== $manifest['stage_hash']) {
            throw new \RuntimeException('Staged canonical bytes are missing or corrupt.');
        }
        $archive = $this->open($path, 'r+b');
        $stage = $this->open($this->stagePath($path), 'rb');
        try {
            $stat = fstat($archive);
            $stagedStat = fstat($stage);
            if (false === $stat || false === $stagedStat
                || $stat['dev'] !== $manifest['device'] || $stat['ino'] !== $manifest['inode']
                || $stat['size'] < $manifest['offset'] || $stat['size'] > $manifest['offset'] + $manifest['length']
                || $stagedStat['size'] !== $manifest['length']) {
                throw new \RuntimeException('Canonical append predecessor or tail is inconsistent.');
            }
            $boundary = $this->readAt($archive, $manifest['offset'] - $manifest['boundary_length'], $manifest['boundary_length']);
            if (hash('sha256', $boundary) !== $manifest['boundary_hash']) {
                throw new \RuntimeException('Canonical append predecessor boundary changed.');
            }
            $present = $stat['size'] - $manifest['offset'];
            for ($position = 0; $position < $present; $position += self::CHUNK_BYTES) {
                $bytes = min(self::CHUNK_BYTES, $present - $position);
                if ($this->readAt($archive, $manifest['offset'] + $position, $bytes) !== $this->readAt($stage, $position, $bytes)) {
                    throw new \RuntimeException('Canonical append suffix does not match prepared bytes.');
                }
            }
            $this->seek($archive, $manifest['offset'] + $present);
            for ($position = $present; $position < $manifest['length']; $position += self::CHUNK_BYTES) {
                $this->writeAll($archive, $this->readAt($stage, $position, min(self::CHUNK_BYTES, $manifest['length'] - $position)));
            }
            if (!fflush($archive)) {
                throw new \RuntimeException('Cannot flush canonical append.');
            }
        } finally {
            fclose($stage);
            fclose($archive);
        }
    }

    public function verifiedPending(string $path): ?\Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO
    {
        $manifest = $this->manifest($path);
        if (null === $manifest) {
            return null;
        }
        $this->reconcile($path);
        $size = filesize($path.'.append.work');
        if (false === $size || $size > self::MAX_STAGED_BYTES) {
            throw new \RuntimeException('Invalid prepared coordination work size.');
        }
        $bytes = file_get_contents($path.'.append.work');
        if (false === $bytes) {
            throw new \RuntimeException('Cannot read prepared coordination work.');
        }
        $encoded = json_decode($bytes, true, 16, \JSON_THROW_ON_ERROR);
        if (!\is_array($encoded) || !\is_string($encoded['body'] ?? null)) {
            throw new \RuntimeException('Invalid prepared coordination envelope.');
        }
        $descriptor = (new \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer())->decode($encoded)->getMessage();
        if (!$descriptor instanceof PendingTransitionWorkDTO) {
            throw new \RuntimeException('Invalid prepared coordination descriptor.');
        }

        return new \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO(hash('sha256', json_encode($manifest, \JSON_THROW_ON_ERROR)), $descriptor->work, $descriptor->work['event_sequences'] ?? []);
    }

    /**
     * Exact staged JSONL records for a verified pending transition.
     *
     * @return list<string>
     */
    public function verifiedStagedRecords(string $path, string $identity): array
    {
        $pending = $this->verifiedPending($path);
        if (null === $pending || $pending->identity !== $identity) {
            throw new \RuntimeException('Prepared transition identity changed before batch read.');
        }
        $manifest = $this->manifest($path);
        if (null === $manifest) {
            throw new \RuntimeException('Verified transition missing before batch read.');
        }
        if (!is_file($this->stagePath($path)) || hash_file('sha256', $this->stagePath($path)) !== $manifest['stage_hash']) {
            throw new \RuntimeException('Staged canonical bytes are missing or corrupt.');
        }
        $contents = file_get_contents($this->stagePath($path));
        if (false === $contents || \strlen($contents) !== $manifest['length'] || hash('sha256', $contents) !== $manifest['stage_hash']) {
            throw new \RuntimeException('Staged canonical bytes are corrupt.');
        }
        if ('' === $contents) {
            return [];
        }

        $records = [];
        $offset = 0;
        $length = \strlen($contents);
        while ($offset < $length) {
            $end = strpos($contents, "\n", $offset);
            if (false === $end) {
                throw new \RuntimeException('Staged canonical records are incomplete.');
            }
            $records[] = substr($contents, $offset, $end - $offset + 1);
            $offset = $end + 1;
        }

        return $records;
    }

    public function finalizeVerified(string $path, string $identity): void
    {
        $pending = $this->verifiedPending($path);
        if (null === $pending || $pending->identity !== $identity) {
            throw new \RuntimeException('Prepared transition identity changed before finalization.');
        }
        $this->finalize($path);
    }

    public function assertReady(string $path): void
    {
        if (null === $this->manifest($path)) {
            return;
        }
        $this->reconcile($path);
        // Physical completion cannot establish mailbox/effect finalization.
        throw new \RuntimeException('Owner transition requires coordination recovery; prepared evidence was retained.');
    }

    public function finalize(string $path): void
    {
        $this->reconcile($path);
        // Call only after the current owner finished required coordination.
        $this->filesystem->remove($this->manifestPath($path));
        $this->filesystem->remove([$this->stagePath($path), $path.'.append.work']);
    }

    public function readableOffset(string $path, int $physicalSize): int
    {
        $manifest = $this->manifest($path);
        if (null === $manifest) {
            return $physicalSize;
        }
        clearstatcache(true, $path);
        $stat = stat($path);
        if (false === $stat || $stat['dev'] !== $manifest['device'] || $stat['ino'] !== $manifest['inode']) {
            throw new \RuntimeException('Canonical archive identity changed while an append was pending.');
        }
        if ($physicalSize < $manifest['offset'] || $physicalSize > $manifest['offset'] + $manifest['length']) {
            throw new \RuntimeException('Canonical archive size is inconsistent with its pending append.');
        }

        return $manifest['offset'];
    }

    /** @return array{version: int, offset: int, device: int, inode: int, boundary_length: int, boundary_hash: string, length: int, stage_hash: string, transition: bool, work_hash: string|null}|null */
    private function manifest(string $path): ?array
    {
        $file = $this->manifestPath($path);
        if (!is_file($file)) {
            return null;
        }
        $size = filesize($file);
        if (false === $size || $size > 4096) {
            throw new \RuntimeException('Invalid canonical append manifest size.');
        }
        $contents = file_get_contents($file);
        if (false === $contents) {
            throw new \RuntimeException('Cannot read canonical append manifest.');
        }
        $data = json_decode($contents, true, 16, \JSON_THROW_ON_ERROR);
        if (!\is_array($data) || 1 !== ($data['version'] ?? null)) {
            throw new \RuntimeException('Invalid canonical append manifest.');
        }
        if (true !== ($data['transition'] ?? null)) {
            throw new \RuntimeException('Pending append has no owner coordination evidence.');
        }
        if (!\is_string($data['work_hash'] ?? null) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $data['work_hash'])) {
            throw new \RuntimeException('Invalid canonical coordination manifest.');
        }
        foreach (['offset', 'device', 'inode', 'boundary_length', 'length'] as $field) {
            if (!\is_int($data[$field] ?? null) || $data[$field] < 0) {
                throw new \RuntimeException('Invalid canonical append manifest scalar.');
            }
        }
        if ($data['length'] > self::MAX_STAGED_BYTES || $data['boundary_length'] > self::CHUNK_BYTES || $data['boundary_length'] > $data['offset']) {
            throw new \RuntimeException('Invalid canonical append manifest bounds.');
        }
        foreach (['boundary_hash', 'stage_hash'] as $field) {
            if (!\is_string($data[$field] ?? null) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $data[$field])) {
                throw new \RuntimeException('Invalid canonical append manifest digest.');
            }
        }

        return $data;
    }

    private function manifestPath(string $path): string
    {
        return $path.'.append.pending.json';
    }

    private function stagePath(string $path): string
    {
        return $path.'.append.staged';
    }

    /** @return resource */
    private function open(string $path, string $mode)
    {
        $handle = fopen($path, $mode);
        if (false === $handle) {
            throw new \RuntimeException('Cannot open canonical append file.');
        }

        return $handle;
    }

    /** @param resource $handle */
    private function seek($handle, int $position): void
    {
        if (0 !== fseek($handle, $position)) {
            throw new \RuntimeException('Cannot seek canonical append file.');
        }
    }

    /** @param resource $handle */
    private function readAt($handle, int $position, int $length): string
    {
        $this->seek($handle, $position);
        $data = '';
        while (\strlen($data) < $length) {
            $chunk = fread($handle, $length - \strlen($data));
            if (false === $chunk || '' === $chunk) {
                throw new \RuntimeException('Cannot read expected canonical append bytes.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    /** @param resource $handle */
    private function writeAll($handle, string $bytes): void
    {
        $position = 0;
        while ($position < \strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $position, self::CHUNK_BYTES));
            if (false === $written || 0 === $written) {
                throw new \RuntimeException('Cannot write prepared canonical bytes.');
            }
            $position += $written;
        }
    }
}
