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
        $data = $this->readRoot($identity);
        $rows = \is_array($data['checkpoints'] ?? null) ? $data['checkpoints'] : [];
        $rows[] = $checkpoint;
        $data['checkpoints'] = $rows;
        $this->writeRoot($identity, $data);
    }

    /** @param array<string, mixed> $restore */
    public function appendRestore(RewindProjectIdentity $identity, array $restore): void
    {
        $data = $this->readRoot($identity);
        $rows = \is_array($data['restores'] ?? null) ? $data['restores'] : [];
        $rows[] = $restore;
        $data['restores'] = $rows;
        $this->writeRoot($identity, $data);
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
        $path = $this->ledgerPath($identity);
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if (false === $raw || '' === trim($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $data */
    private function writeRoot(RewindProjectIdentity $identity, array $data): void
    {
        $path = $this->ledgerPath($identity);
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($path, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    private function ledgerPath(RewindProjectIdentity $identity): string
    {
        return rtrim($this->projectCwd, '/').'/.hatfield/rewind/snapshots/'.$identity->projectHash.'/ledger.json';
    }
}
