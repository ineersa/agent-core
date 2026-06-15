<?php

declare(strict_types=1);

/**
 * Log management tasks.
 *
 * Thin wrappers that delegate to Symfony console commands.
 * Parameter signatures mirror the command options/arguments so Castor
 * validates them. Values are forwarded directly to bin/console.
 * The app container resolves logging.path from Hatfield config —
 * Castor never resolves config or instantiates app services.
 */

use Castor\Attribute\AsTask;

use function CastorTasks\is_llm_mode;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';

#[AsTask(name: 'log:tail', description: 'Show recent log entries (→ bin/console log:tail)')]
function log_tail(?string $level = null, int $lines = 50, ?string $search = null): void
{
    $cmd = escapeshellcmd(\PHP_BINARY).' '.__DIR__.'/../bin/console log:tail';
    if (null !== $level) {
        $cmd .= ' --level='.escapeshellarg($level);
    }
    $cmd .= ' --lines='.$lines;
    if (null !== $search) {
        $cmd .= ' --search='.escapeshellarg($search);
    }
    $cmd .= ' --format='.(is_llm_mode() ? 'toon' : 'jsonl');
    passthru($cmd, $exitCode);
    exit($exitCode);
}

#[AsTask(name: 'log:search', description: 'Search log entries across all log files (→ bin/console log:search)')]
function log_search(string $query, ?string $level = null, ?string $from = null, ?string $to = null): void
{
    $cmd = escapeshellcmd(\PHP_BINARY).' '.__DIR__.'/../bin/console log:search '.escapeshellarg($query);
    if (null !== $level) {
        $cmd .= ' --level='.escapeshellarg($level);
    }
    if (null !== $from) {
        $cmd .= ' --from='.escapeshellarg($from);
    }
    if (null !== $to) {
        $cmd .= ' --to='.escapeshellarg($to);
    }
    $cmd .= ' --format='.(is_llm_mode() ? 'toon' : 'jsonl');
    passthru($cmd, $exitCode);
    exit($exitCode);
}

#[AsTask(name: 'log:files', description: 'List log files with size and modification date (→ bin/console log:files)')]
function log_files(): void
{
    $cmd = escapeshellcmd(\PHP_BINARY).' '.__DIR__.'/../bin/console log:files';
    $cmd .= ' --format='.(is_llm_mode() ? 'toon' : 'jsonl');
    passthru($cmd, $exitCode);
    exit($exitCode);
}

#[AsTask(name: 'log:clear', description: 'Remove old rotated log files (→ bin/console log:clear)')]
function log_clear(string $olderThan = '7 days ago'): void
{
    passthru(escapeshellcmd(\PHP_BINARY).' '.__DIR__.'/../bin/console log:clear --older-than='.escapeshellarg($olderThan), $exitCode);
    exit($exitCode);
}
