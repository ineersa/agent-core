<?php

declare(strict_types=1);

/**
 * Independent strict-128M probe for a small selected read from a large text file.
 *
 * Creates the fixture with bounded writes, reads a tiny mid-file range through
 * ReadFileTool, and reports peak allocator usage. The selected result remains
 * legitimate retained text; unselected prefix/suffix bodies must not dominate.
 */

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\Arguments\ReadFileArgumentsDTO;
use Ineersa\CodingAgent\Tool\ReadFileTool;
use Ineersa\CodingAgent\Tool\ToolRuntime;

require dirname(__DIR__, 4).'/vendor/autoload.php';

ini_set('memory_limit', '128M');

$directory = $argv[1] ?? '';
if (!is_string($directory) || '' === $directory) {
    fwrite(\STDERR, "probe directory is required\n");
    exit(2);
}

TestDirectoryIsolation::ensureDirectory($directory);
$path = $directory.'/large-selected-range.txt';
$prefixLines = 45_000;
$selectedLines = 3;
$suffixLines = 45_000;
$unselectedLine = str_repeat('U', 1024)."\n";
$selected = ["want-1\n", "want-2\n", "want-3\n"];

$handle = fopen($path, 'wb');
if (false === $handle) {
    fwrite(\STDERR, "failed to open fixture\n");
    exit(2);
}

try {
    for ($i = 0; $i < $prefixLines; ++$i) {
        fwrite($handle, $unselectedLine);
    }
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
$tool = new ReadFileTool(new ToolRuntime(new StackToolExecutionContextAccessor()));
$result = $tool(new ReadFileArgumentsDTO(
    path: $path,
    offset: $prefixLines + 1,
    limit: $selectedLines,
));
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
