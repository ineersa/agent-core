<?php

declare(strict_types=1);

/**
 * Convert all ai-index.json files to ai-index.toon format.
 *
 * Usage: php scripts/convert-index-to-toon.php
 *
 * Scans all ai-index.json files under src/ and the project root.
 * For each: json_decode → HelgeSverre\Toon\Toon::encode → write ai-index.toon.
 * Updates all index references from .json to .toon.
 */

require __DIR__.'/../vendor/autoload.php';

use HelgeSverre\Toon\EncodeOptions;
use HelgeSverre\Toon\Toon;

$projectRoot = realpath(__DIR__.'/..');
$srcDir = $projectRoot.'/src';

// Collect all ai-index.json paths
$jsonFiles = [];
// Root index
if (file_exists($projectRoot.'/ai-index.json')) {
    $jsonFiles[] = $projectRoot.'/ai-index.json';
}
// All src/ indexes
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
);
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getFilename() === 'ai-index.json') {
        $jsonFiles[] = $file->getPathname();
    }
}

sort($jsonFiles);

if (empty($jsonFiles)) {
    echo "No ai-index.json files found.\n";
    exit(0);
}

$converted = 0;
$totalJsonBytes = 0;
$totalToonBytes = 0;
$errors = [];

foreach ($jsonFiles as $jsonPath) {
    $jsonContent = file_get_contents($jsonPath);
    $data = json_decode($jsonContent, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $errors[] = sprintf('Invalid JSON in %s: %s', $jsonPath, json_last_error_msg());
        continue;
    }

    // Update all index references from .json to .toon
    $data = updateIndexReferences($data);

    // Encode to TOON
    try {
        $toonContent = Toon::encode($data, EncodeOptions::default());
    } catch (Throwable $e) {
        $errors[] = sprintf('TOON encode error for %s: %s', $jsonPath, $e->getMessage());
        continue;
    }

    $toonPath = preg_replace('/\.json$/', '.toon', $jsonPath);
    file_put_contents($toonPath, $toonContent."\n");

    $jsonBytes = strlen($jsonContent);
    $toonBytes = strlen($toonContent);
    $totalJsonBytes += $jsonBytes;
    $totalToonBytes += $toonBytes;

    $relPath = str_replace($projectRoot.'/', '', $jsonPath);
    $saved = $jsonBytes - $toonBytes;
    $pct = $jsonBytes > 0 ? round(($saved / $jsonBytes) * 100, 1) : 0;
    echo sprintf("  ✓ %s (%d → %d bytes, %s%d%%)\n",
        $relPath,
        $jsonBytes,
        $toonBytes,
        $saved >= 0 ? '' : '+',
        $saved >= 0 ? $pct : -$pct,
    );
    $converted++;
}

echo "\n";
echo sprintf("Converted: %d files\n", $converted);
echo sprintf("Total: %d → %d bytes (saved %d bytes, %s%%)\n",
    $totalJsonBytes,
    $totalToonBytes,
    $totalJsonBytes - $totalToonBytes,
    $totalJsonBytes > 0 ? round((($totalJsonBytes - $totalToonBytes) / $totalJsonBytes) * 100, 1) : '0',
);

if (!empty($errors)) {
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo "  ✗ {$error}\n";
    }
    exit(1);
}

echo "\nDone. Run 'castor dev:index-validate' to verify.\n";

/**
 * Recursively update all 'index' keys from .json to .toon extension.
 */
function updateIndexReferences(array $data): array
{
    if (isset($data['index']) && is_string($data['index'])) {
        $data['index'] = preg_replace('/\.json$/', '.toon', $data['index']);
    }

    // Handle namespaces and subNamespaces arrays
    foreach (['namespaces', 'subNamespaces'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            $data[$key] = array_map(updateIndexReferences(...), $data[$key]);
        }
    }

    return $data;
}
