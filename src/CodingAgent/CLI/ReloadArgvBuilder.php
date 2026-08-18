<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI;

/**
 * Rebuilds the relaunch argv for a /reload bootstrap.
 *
 * The original invocation argv is the already-normalized launch policy;
 * reload only adjusts what must change for the fresh boot:
 *  - one-shot input (--prompt / -p) is dropped so the prompt does not
 *    execute twice,
 *  - a stale --resume is replaced with the current session id,
 *  - everything else (model, reasoning, transport, tools, skills, cwd)
 *    is preserved deliberately.
 */
final class ReloadArgvBuilder
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $originalArgv    argv as captured at startup ([0] = script name)
     * @param string       $resumeSessionId session id to resume; '' launches a fresh draft
     *
     * @return list<string> argv for the relaunch
     */
    public static function build(array $originalArgv, string $resumeSessionId): array
    {
        $argv = [$originalArgv[0] ?? ''];

        $count = \count($originalArgv);
        for ($i = 1; $i < $count; ++$i) {
            $arg = $originalArgv[$i];

            // Drop one-shot prompt input in all Symfony forms.
            if ('--prompt' === $arg || '-p' === $arg) {
                // Consume the trailing value unless it looks like an option.
                if ($i + 1 < $count && !str_starts_with($originalArgv[$i + 1], '-')) {
                    ++$i;
                }
                continue;
            }
            if (str_starts_with($arg, '--prompt=') || str_starts_with($arg, '-p')) {
                continue;
            }

            // Drop any pre-existing --resume so the current session id wins.
            if ('--resume' === $arg) {
                if ($i + 1 < $count && !str_starts_with($originalArgv[$i + 1], '-')) {
                    ++$i;
                }
                continue;
            }
            if (str_starts_with($arg, '--resume=')) {
                continue;
            }

            $argv[] = $arg;
        }

        if ('' !== $resumeSessionId) {
            $argv[] = '--resume='.$resumeSessionId;
        }

        return $argv;
    }
}
