<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI;

use Symfony\Component\Filesystem\Filesystem;

/** Create the project-local ignore rules before startup writes runtime data. */
final class ProjectGitignoreInitializer
{
    private const string RULES = <<<'GITIGNORE'
# Hatfield runtime data. Project settings and other custom files remain trackable.
sessions/
tmp/
cache/
cache-*/
logs/
extensions-data/
rewind/
state.sqlite*
messenger-transport.sqlite*
messenger.sqlite*
GITIGNORE;

    public function __invoke(string $cwd): void
    {
        $directory = rtrim($cwd, '/').'/.hatfield';
        (new Filesystem())->mkdir($directory);

        $path = $directory.'/.gitignore';
        if (is_link($path) || file_exists($path)) {
            return;
        }

        // Exclusive creation leaves existing project rules untouched, even when
        // two Hatfield processes start in the same directory concurrently.
        $handle = @fopen($path, 'xb');
        if (false === $handle) {
            if (file_exists($path)) {
                return;
            }

            throw new \RuntimeException(\sprintf('Unable to create project gitignore at "%s".', $path));
        }

        try {
            $rules = self::RULES."\n";
            if (\strlen($rules) !== fwrite($handle, $rules)) {
                throw new \RuntimeException(\sprintf('Unable to write project gitignore at "%s".', $path));
            }
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        } finally {
            fclose($handle);
        }
    }
}
