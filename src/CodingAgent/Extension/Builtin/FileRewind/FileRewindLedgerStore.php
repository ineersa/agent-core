<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\FileRewind;

/**
 * Extension-local JSON ledger under .hatfield/rewind (not AgentCore events).
 */
final class FileRewindLedgerStore
{
    public function __construct(
        private readonly string $projectCwd,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function readCheckpoints(RewindProjectIdentity $identity): array
    {
        $data = $this->readRoot($identity);

        return \is_array($data['checkpoints'] ?? null) ? $data['checkpoints'] : [];
    }

    /** @param array<string, mixed> $checkpoint */
    public function appendCheckpoint(RewindProjectIdentity $identity, array $checkpoint): void
    {
        $this->mutateRoot($identity, static function (array $data) use ($checkpoint): array {
            $rows = \is_array($data['checkpoints'] ?? null) ? $data['checkpoints'] : [];
            $rows[] = $checkpoint;
            $data['checkpoints'] = $rows;

            return $data;
        });
    }

    /** @param array<string, mixed> $restore */
    public function appendRestore(RewindProjectIdentity $identity, array $restore): void
    {
        $this->mutateRoot($identity, static function (array $data) use ($restore): array {
            $rows = \is_array($data['restores'] ?? null) ? $data['restores'] : [];
            $rows[] = $restore;
            $data['restores'] = $rows;

            return $data;
        });
    }

    /** @return list<array<string, mixed>> */
    public function readRestores(RewindProjectIdentity $identity): array
    {
        $data = $this->readRoot($identity);

        return \is_array($data['restores'] ?? null) ? $data['restores'] : [];
    }

    /** @return array<string, mixed> */
    private function readRoot(RewindProjectIdentity $identity): array
    {
        return $this->withLedgerLock($identity, function (mixed $handle): array {
            return $this->decodeLedgerFromHandle($handle);
        });
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     */
    private function mutateRoot(RewindProjectIdentity $identity, callable $mutator): void
    {
        $this->withLedgerLock($identity, function (mixed $handle) use ($mutator): void {
            $data = $this->decodeLedgerFromHandle($handle);
            $data = $mutator($data);
            $this->writeLedgerToHandle($handle, $data);
        });
    }

    /**
     * @template T
     *
     * @param callable(resource): T $callback
     *
     * @return T
     */
    private function withLedgerLock(RewindProjectIdentity $identity, callable $callback): mixed
    {
        $path = $this->ledgerPath($identity);
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $handle = fopen($path, 'c+b');
        if (false === $handle) {
            throw new \RuntimeException('Cannot open file rewind ledger for locking.');
        }
        try {
            if (!flock($handle, \LOCK_EX)) {
                throw new \RuntimeException('Cannot acquire file rewind ledger lock.');
            }
            try {
                return $callback($handle);
            } finally {
                flock($handle, \LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     *
     * @return array<string, mixed>
     */
    private function decodeLedgerFromHandle(mixed $handle): array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);
        if (false === $raw || '' === trim($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param resource             $handle
     * @param array<string, mixed> $data
     */
    private function writeLedgerToHandle(mixed $handle, array $data): void
    {
        $encoded = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if (false === $encoded) {
            throw new \RuntimeException('Cannot encode file rewind ledger JSON.');
        }
        rewind($handle);
        ftruncate($handle, 0);
        $written = fwrite($handle, $encoded);
        if (false === $written) {
            throw new \RuntimeException('Cannot write file rewind ledger.');
        }
        fflush($handle);
    }

    private function ledgerPath(RewindProjectIdentity $identity): string
    {
        return rtrim($this->projectCwd, '/').'/.hatfield/rewind/snapshots/'.$identity->projectHash.'/ledger.json';
    }
}
