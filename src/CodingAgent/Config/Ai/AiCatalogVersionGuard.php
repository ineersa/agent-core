<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

use Symfony\Component\Yaml\Yaml;

/**
 * Fail closed when config/ai-catalog.yaml changes without a version bump vs a git base.
 *
 * Used by {@code castor catalog:version-check} (wired into castor check). Soft-passes
 * when the base ref / merge-base is unavailable (fresh clone) so local bootstraps are
 * not blocked; production CI always has origin/main.
 *
 * @internal
 */
final class AiCatalogVersionGuard
{
    public const RELATIVE_PATH = 'config/ai-catalog.yaml';

    /**
     * @param (\Closure(string): array{exit: int, out: string, err: string})|null $gitRunner
     */
    public function __construct(
        private readonly string $appRoot,
        private readonly ?\Closure $gitRunner = null,
    ) {
    }

    /**
     * @return array{ok: bool, errors: list<string>, notes: list<string>}
     */
    public function check(string $baseRef = 'origin/main'): array
    {
        $notes = [];
        $errors = [];
        $root = rtrim($this->appRoot, '/');
        $relative = self::RELATIVE_PATH;
        $absolute = $root.'/'.$relative;

        $verify = $this->git('rev-parse --verify '.escapeshellarg($baseRef), $root);
        if (0 !== $verify['exit']) {
            $notes[] = \sprintf('base ref %s unavailable; soft-pass', $baseRef);

            return ['ok' => true, 'errors' => [], 'notes' => $notes];
        }

        $mergeBase = $this->git('merge-base HEAD '.escapeshellarg($baseRef), $root);
        if (0 !== $mergeBase['exit'] || '' === trim($mergeBase['out'])) {
            $notes[] = \sprintf('merge-base with %s unavailable; soft-pass', $baseRef);

            return ['ok' => true, 'errors' => [], 'notes' => $notes];
        }
        $baseSha = trim($mergeBase['out']);

        $existsAtBase = $this->git('cat-file -e '.escapeshellarg($baseSha.':'.$relative), $root);
        if (0 !== $existsAtBase['exit']) {
            // File is new/untracked relative to the base — first introduction is fine.
            $notes[] = \sprintf('%s is new relative to %s; pass', $relative, $baseRef);

            return ['ok' => true, 'errors' => [], 'notes' => $notes];
        }

        $diff = $this->git('diff --quiet '.escapeshellarg($baseSha).' -- '.escapeshellarg($relative), $root);
        if (0 === $diff['exit']) {
            return ['ok' => true, 'errors' => [], 'notes' => $notes];
        }

        $oldShow = $this->git('show '.escapeshellarg($baseSha.':'.$relative), $root);
        if (0 !== $oldShow['exit']) {
            $errors[] = \sprintf('failed to read %s at %s', $relative, $baseSha);

            return ['ok' => false, 'errors' => $errors, 'notes' => $notes];
        }

        if (!is_readable($absolute)) {
            $errors[] = \sprintf('%s is not readable in the working tree', $relative);

            return ['ok' => false, 'errors' => $errors, 'notes' => $notes];
        }
        $newRaw = file_get_contents($absolute);
        if (false === $newRaw) {
            $errors[] = \sprintf('failed to read working-tree %s', $relative);

            return ['ok' => false, 'errors' => $errors, 'notes' => $notes];
        }

        $oldVersion = $this->parseVersion($oldShow['out']);
        $newVersion = $this->parseVersion($newRaw);
        if (null === $oldVersion || null === $newVersion) {
            $errors[] = \sprintf(
                '%s changed vs %s but version: could not be parsed (old=%s, new=%s)',
                $relative,
                $baseRef,
                null === $oldVersion ? 'missing' : (string) $oldVersion,
                null === $newVersion ? 'missing' : (string) $newVersion,
            );

            return ['ok' => false, 'errors' => $errors, 'notes' => $notes];
        }

        if ($newVersion <= $oldVersion) {
            $errors[] = \sprintf(
                '%s changed vs %s without a version bump (old=%d, new=%d); bump version:',
                $relative,
                $baseRef,
                $oldVersion,
                $newVersion,
            );

            return ['ok' => false, 'errors' => $errors, 'notes' => $notes];
        }

        return ['ok' => true, 'errors' => [], 'notes' => $notes];
    }

    private function parseVersion(string $yaml): ?int
    {
        try {
            $data = Yaml::parse($yaml);
        } catch (\Throwable) {
            return null;
        }
        if (!\is_array($data) || !isset($data['version']) || !is_numeric($data['version'])) {
            return null;
        }

        return (int) $data['version'];
    }

    /**
     * @return array{exit: int, out: string, err: string}
     */
    private function git(string $args, string $cwd): array
    {
        if (null !== $this->gitRunner) {
            return ($this->gitRunner)($args);
        }

        $cmd = 'git -C '.escapeshellarg($cwd).' '.$args;
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!\is_resource($proc)) {
            return ['exit' => 127, 'out' => '', 'err' => 'proc_open failed'];
        }
        fclose($pipes[0]);
        $outRaw = stream_get_contents($pipes[1]);
        $errRaw = stream_get_contents($pipes[2]);
        $out = false === $outRaw ? '' : $outRaw;
        $err = false === $errRaw ? '' : $errRaw;
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return ['exit' => $exit, 'out' => $out, 'err' => $err];
    }
}
