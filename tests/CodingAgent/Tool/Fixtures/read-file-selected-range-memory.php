<?php

declare(strict_types=1);

/**
 * Independent strict-128M probe for a small selected read from a large text file.
 *
 * Creates a giant unselected first line with bounded writes, validates the
 * offset through the native toolbox, and reads a tiny range with ReadFileTool.
 * Validation and execution must both avoid retaining the unselected line.
 */

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\Tool\Support\ToolValidationHarness;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\ReadFileTool;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

require dirname(__DIR__, 4).'/vendor/autoload.php';

ini_set('memory_limit', '128M');

$directory = $argv[1] ?? '';
if (!is_string($directory) || '' === $directory) {
    fwrite(\STDERR, "probe directory is required\n");
    exit(2);
}

TestDirectoryIsolation::ensureDirectory($directory);
$path = $directory.'/large-selected-range.txt';
$prefixLines = 1;
$selectedLines = 3;
$suffixLines = 1;
$unselectedLine = str_repeat('U', 1024)."\n";
$selected = ["want-1\n", "want-2\n", "want-3\n"];

$handle = fopen($path, 'wb');
if (false === $handle) {
    fwrite(\STDERR, "failed to open fixture\n");
    exit(2);
}

try {
    $chunk = str_repeat('U', 1024 * 1024);
    for ($i = 0; $i < 160; ++$i) {
        fwrite($handle, $chunk);
    }
    fwrite($handle, "\n");
    unset($chunk);
    foreach ($selected as $line) {
        fwrite($handle, $line);
    }
    for ($i = 0; $i < $suffixLines; ++$i) {
        fwrite($handle, $unselectedLine);
    }
} finally {
    fclose($handle);
}

$archiveBytes = filesize($path);
if (false === $archiveBytes) {
    fwrite(\STDERR, "failed to measure fixture\n");
    exit(2);
}

$before = memory_get_usage(true);
$accessor = new StackToolExecutionContextAccessor();
$cap = new OutputCap(new OutputCapConfig(storageDir: $directory.'/output-cap'), new LockFactory(new FlockStore($directory)), new NullLogger());
$tool = new ReadFileTool(new ToolRuntime($accessor), $cap, $accessor, static fn () => throw new LogicException('Unused retention capability.'));
$toolbox = ToolValidationHarness::toolbox($tool);
$result = (string) $toolbox->execute(new ToolCall('read-memory', 'read', [
    'path' => $path,
    'offset' => $prefixLines + 1,
    'limit' => $selectedLines,
]))->getResult();
$peak = memory_get_peak_usage(true);

$expected = implode('', $selected)
    ."\n--- {$suffixLines} more lines (use read with offset=".($prefixLines + $selectedLines + 1)." limit=200 to continue) ---\n";

echo json_encode([
    'archive_bytes' => $archiveBytes,
    'before_bytes' => $before,
    'peak_bytes' => $peak,
    'result_bytes' => strlen($result),
    'result_matches' => $result === $expected,
    'contains_unselected' => str_contains($result, 'UUUU'),
], \JSON_THROW_ON_ERROR)."\n";
