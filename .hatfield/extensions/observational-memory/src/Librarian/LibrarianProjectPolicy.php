<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Librarian;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Path;

/** Worktree exclusion is a local policy, not fallback after an external failure. */
final readonly class LibrarianProjectPolicy
{
    public function __construct(private ExecInterface $exec, private LoggerInterface $logger)
    {
    }

    public function allows(string $cwd): bool
    {
        $resolved = realpath($cwd);
        $directory = false === $resolved ? Path::canonicalize($cwd) : $resolved;
        while (!file_exists($directory.'/.git') && !is_link($directory.'/.git')) {
            $parent = Path::getDirectory($directory);
            if ($parent === $directory) {
                return true; // Non-Git projects remain supported.
            }
            $directory = $parent;
        }

        try {
            $result = $this->exec->exec('git', [
                '-c', 'safe.directory='.$cwd,
                'rev-parse', '--path-format=absolute', '--git-dir', '--git-common-dir',
            ], new ExecOptionsDTO(cwd: $cwd, timeout: 5));
            if (0 === $result->exitCode && !$result->timedOut && !$result->cancelled) {
                $paths = explode("\n", trim($result->stdout));
                if (2 === \count($paths)) {
                    $git = realpath(trim($paths[0]));
                    $common = realpath(trim($paths[1]));
                    if (false !== $git && false !== $common && is_readable($git) && is_readable($common)) {
                        return $git === $common;
                    }
                }
            }
        } catch (\Throwable $error) {
            // Uncertain Git identity must never be registered or used for OM.
            $this->logger->warning('om.librarian.git_inspection_failed', [
                'run_id' => null, 'session_id' => null,
                'component' => 'observational_memory', 'event_type' => 'om.librarian.git_inspection_failed',
                'exception_class' => $error::class,
            ]);

            return false;
        }

        $this->logger->warning('om.librarian.git_inspection_unavailable', [
            'run_id' => null, 'session_id' => null,
            'component' => 'observational_memory', 'event_type' => 'om.librarian.git_inspection_unavailable',
        ]);

        return false;
    }
}
