<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI;

/**
 * Rebuilds the relaunch argv for a /reload bootstrap.
 *
 * The original invocation argv is the already-normalized launch policy;
 * reload only adjusts what must change for the fresh boot:
 *  - one-shot input (--prompt) is dropped so the prompt does not
 *    execute twice,
 *  - a stale --resume is replaced with the current session id,
 *  - everything else (model, reasoning, transport, tools, skills, cwd)
 *    is preserved deliberately.
 *
 * AgentCommand's --prompt Option has no shortcut; -p never reaches here.
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

            // Drop one-shot --prompt (space or = form). Symfony already
            // required a value at first parse, so the next token is always
            // the prompt when using the space form.
            if ('--prompt' === $arg) {
                if ($i + 1 < $count) {
                    ++$i;
                }
                continue;
            }
            if (str_starts_with($arg, '--prompt=')) {
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
