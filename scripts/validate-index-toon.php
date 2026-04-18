<?php

declare(strict_types=1);

/**
 * Validate ai-index.toon files.
 *
 * Usage:
 *   php scripts/validate-index-toon.php                           — validate all
 *   php scripts/validate-index-toon.php src/Domain/ai-index.toon  — validate one file
 *   php scripts/validate-index-toon.php src/Domain/               — validate all in directory
 */

require __DIR__.'/../vendor/autoload.php';

use HelgeSverre\Toon\Toon;

$projectRoot = realpath(__DIR__.'/..');
$target = $argv[1] ?? null;

$toonFiles = collectToonFiles($projectRoot, $target);

if (empty($toonFiles)) {
    echo "No ai-index.toon files found.\n";
    exit(0);
}

$valid = 0;
$errors = [];

foreach ($toonFiles as $toonPath) {
    $fileErrors = validateFile($projectRoot, $toonPath);
    $relPath = str_replace($projectRoot.'/', '', $toonPath);

    if (empty($fileErrors)) {
        echo "  ✓ {$relPath}\n";
        $valid++;
    } else {
        echo "  ✗ {$relPath}\n";
        foreach ($fileErrors as $error) {
            echo "    - {$error}\n";
        }
        $errors[$relPath] = $fileErrors;
    }
}

echo "\n";
echo sprintf("Valid: %d/%d files\n", $valid, count($toonFiles));

if (!empty($errors)) {
    echo sprintf("Failed: %d files with errors\n", count($errors));
    exit(1);
}

echo "All files valid.\n";

function collectToonFiles(string $projectRoot, ?string $target): array
{
    $toonFiles = [];

    if ($target === null) {
        // Validate all
        if (file_exists($projectRoot.'/ai-index.toon')) {
            $toonFiles[] = $projectRoot.'/ai-index.toon';
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot.'/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === 'ai-index.toon') {
                $toonFiles[] = $file->getPathname();
            }
        }
    } elseif (is_dir($target)) {
        $dir = realpath($target);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === 'ai-index.toon') {
                $toonFiles[] = $file->getPathname();
            }
        }
    } elseif (is_file($target)) {
        $toonFiles[] = realpath($target);
    } else {
        echo "Path not found: {$target}\n";
        exit(1);
    }

    sort($toonFiles);

    return $toonFiles;
}

function validateFile(string $projectRoot, string $toonPath): array
{
    $errors = [];
    $relPath = str_replace($projectRoot.'/', '', $toonPath);
    $dir = dirname($toonPath);

    // 1. Decode
    $content = file_get_contents($toonPath);
    try {
        $data = Toon::decode($content);
    } catch (Throwable $e) {
        $errors[] = sprintf('Decode error: %s', $e->getMessage());

        return $errors;
    }

    if (!is_array($data)) {
        $errors[] = 'Decoded data is not an array';

        return $errors;
    }

    // 2. Check spec field
    if (!isset($data['spec']) || $data['spec'] !== 'agent-core.ai-docs/v1') {
        $errors[] = 'Missing or invalid spec field (expected: agent-core.ai-docs/v1)';
    }

    // 3. Check docs references exist
    if (isset($data['files']) && is_array($data['files'])) {
        foreach ($data['files'] as $i => $file) {
            if (!is_array($file)) {
                $errors[] = sprintf('files[%d] is not an object', $i);
                continue;
            }
            if (isset($file['docs']) && is_string($file['docs'])) {
                $docsPath = $dir.'/'.$file['docs'];
                if (!file_exists($docsPath)) {
                    $errors[] = sprintf('files[%d] docs reference not found: %s', $i, $file['docs']);
                }
            }
        }
    }

    // 4. Check sub-namespace index references exist
    if (isset($data['subNamespaces']) && is_array($data['subNamespaces'])) {
        foreach ($data['subNamespaces'] as $i => $ns) {
            if (!is_array($ns)) {
                $errors[] = sprintf('subNamespaces[%d] is not an object', $i);
                continue;
            }
            if (isset($ns['index']) && is_string($ns['index'])) {
                $indexPath = $dir.'/'.$ns['index'];
                if (!file_exists($indexPath)) {
                    $errors[] = sprintf('subNamespaces[%d] index not found: %s', $i, $ns['index']);
                }
            }
        }
    }

    // 5. Check namespace index references (root level)
    if (isset($data['namespaces']) && is_array($data['namespaces'])) {
        foreach ($data['namespaces'] as $i => $ns) {
            if (!is_array($ns)) {
                $errors[] = sprintf('namespaces[%d] is not an object', $i);
                continue;
            }
            if (isset($ns['index']) && is_string($ns['index'])) {
                $indexPath = $projectRoot.'/'.$ns['index'];
                if (!file_exists($indexPath)) {
                    $errors[] = sprintf('namespaces[%d] index not found: %s', $i, $ns['index']);
                }
            }
        }
    }

    // 6. Round-trip check: decode → encode → decode and compare structure
    try {
        $reencoded = Toon::encode($data);
        $redecoded = Toon::decode($reencoded);
        // Compare as JSON strings for structural equality
        if (json_encode($data, JSON_UNESCAPED_SLASHES) !== json_encode($redecoded, JSON_UNESCAPED_SLASHES)) {
            $errors[] = 'Round-trip check failed: decode→encode→decode produces different structure';
        }
    } catch (Throwable $e) {
        $errors[] = sprintf('Round-trip check error: %s', $e->getMessage());
    }

    return $errors;
}
