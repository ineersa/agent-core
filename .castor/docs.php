<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;
use Ineersa\CodingAgent\Docs\BuiltinDocsCatalog;
use Ineersa\CodingAgent\Docs\BuiltinDocsCatalogException;
use Symfony\Component\Finder\Finder;

use function CastorTasks\project_root_dir;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';

/**
 * Deterministic documentation catalog and size validation.
 */
#[AsTask(name: 'docs:validate', description: 'Validate built-in docs catalog, package-safe links, and size limits')]
function docs_validate(): void
{
    $root = project_root_dir();
    $errors = [];
    $catalog = new BuiltinDocsCatalog();

    try {
        $entries = $catalog->discover($root);
    } catch (BuiltinDocsCatalogException $e) {
        throw new RuntimeException('docs:validate failed: '.$e->getMessage(), 0, $e);
    }

    $byId = [];
    foreach ($entries as $entry) {
        $byId[$entry['id']] = $entry;
        if ($entry['charCount'] > BuiltinDocsCatalog::MAX_DOCUMENT_CHARS) {
            $errors[] = sprintf(
                '%s exceeds %d characters (%d).',
                $entry['relativePath'],
                BuiltinDocsCatalog::MAX_DOCUMENT_CHARS,
                $entry['charCount'],
            );
        }
    }

    if ([] === $entries) {
        $errors[] = 'No built-in documents discovered (expected marked docs under approved roots).';
    }

    // Universal size limit for README + every maintained top-level docs/*.md + API docs.
    $sizeTargets = [];
    $readme = $root.'/README.md';
    if (is_file($readme)) {
        $sizeTargets[] = $readme;
    }
    foreach ([BuiltinDocsCatalog::CORE_DOCS_RELATIVE, BuiltinDocsCatalog::EXTENSION_API_DOCS_RELATIVE] as $relRoot) {
        $dir = $root.'/'.$relRoot;
        if (!is_dir($dir)) {
            continue;
        }
        $finder = Finder::create()->files()->in($dir)->depth('== 0')->name('*.md');
        foreach ($finder as $file) {
            $sizeTargets[] = $file->getPathname();
        }
    }
    foreach ($sizeTargets as $path) {
        $raw = (string) file_get_contents($path);
        $count = mb_strlen($raw, 'UTF-8');
        if ($count > BuiltinDocsCatalog::MAX_DOCUMENT_CHARS) {
            $rel = str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : $path;
            $errors[] = sprintf('%s exceeds %d characters (%d).', $rel, BuiltinDocsCatalog::MAX_DOCUMENT_CHARS, $count);
        }
    }

    // Package-safe reference checks for built-in docs only.
    $bundledIds = array_keys($byId);
    foreach ($entries as $entry) {
        $body = $entry['body'];
        $rel = $entry['relativePath'];

        if (str_contains($body, 'internal-docs')) {
            $errors[] = $rel.': must not reference internal-docs.';
        }
        if (preg_match('/(?:^|[^`])\.pi\//m', $body) || str_contains($body, '`](.pi/') || str_contains($body, '](.pi/')) {
            $errors[] = $rel.': must not reference .pi/ paths.';
        }
        if (preg_match('#/(?:home|Users)/[A-Za-z0-9._-]+/#', $body)) {
            $errors[] = $rel.': must not include local absolute home paths.';
        }
        if (preg_match('#vendor/bin/#', $body)) {
            $errors[] = $rel.': must not recommend raw vendor/bin tools.';
        }

        // Relative markdown links.
        if (preg_match_all('/\[[^\]]*\]\(([^)]+)\)/', $body, $matches)) {
            foreach ($matches[1] as $target) {
                $target = trim($target);
                if ('' === $target || str_starts_with($target, '#') || preg_match('#^[a-z]+://#i', $target) || str_starts_with($target, 'mailto:')) {
                    continue;
                }
                $targetPath = explode('#', $target, 2)[0];
                if ('' === $targetPath) {
                    continue;
                }
                // Package-local package sources are allowed for Extension API docs.
                $baseDir = dirname($entry['absolutePath']);
                $resolved = realpath($baseDir.'/'.$targetPath);
                $allowedPrefixes = [
                    realpath($root.'/'.BuiltinDocsCatalog::CORE_DOCS_RELATIVE) ?: ($root.'/'.BuiltinDocsCatalog::CORE_DOCS_RELATIVE),
                    realpath($root.'/'.BuiltinDocsCatalog::EXTENSION_API_DOCS_RELATIVE) ?: ($root.'/'.BuiltinDocsCatalog::EXTENSION_API_DOCS_RELATIVE),
                    realpath($root.'/.hatfield/extensions/extension-api') ?: ($root.'/.hatfield/extensions/extension-api'),
                ];
                if (false === $resolved || !is_file($resolved)) {
                    // Sibling built-in id links like settings.md / human-input.md
                    $basename = basename($targetPath);
                    if (str_ends_with($basename, '.md')) {
                        $id = substr($basename, 0, -3);
                        if (isset($byId[$id])) {
                            continue;
                        }
                    }
                    $errors[] = sprintf('%s: broken or unbundled link target "%s".', $rel, $target);
                    continue;
                }
                $ok = false;
                foreach ($allowedPrefixes as $prefix) {
                    if (is_string($prefix) && str_starts_with($resolved, rtrim($prefix, '/').'/')) {
                        $ok = true;
                        break;
                    }
                }
                // Built-in core docs may only link to other built-in markdown files or anchors.
                if (BuiltinDocsCatalog::CORE_DOCS_RELATIVE === $entry['rootRelative']) {
                    $id = pathinfo($resolved, \PATHINFO_FILENAME);
                    if (!isset($byId[$id]) || !str_ends_with($resolved, '.md')) {
                        $errors[] = sprintf('%s: core built-in doc link "%s" must target another built-in document.', $rel, $target);
                        continue;
                    }
                } elseif (!$ok) {
                    $errors[] = sprintf('%s: link "%s" resolves outside approved package roots.', $rel, $target);
                }
            }
        }
    }

    // Legacy internal-docs must be gone.
    if (is_dir($root.'/internal-docs')) {
        $errors[] = 'internal-docs/ must be removed; built-in docs are selected from approved roots.';
    }

    if ([] !== $errors) {
        $message = "docs:validate failed:\n - ".implode("\n - ", $errors);
        throw new RuntimeException($message);
    }

    echo sprintf(
        "docs:validate ok (%d built-in documents, max %d chars)\n",
        count($entries),
        BuiltinDocsCatalog::MAX_DOCUMENT_CHARS,
    );
    foreach ($entries as $entry) {
        echo sprintf("  - %s (%s)\n", $entry['id'], $entry['relativePath']);
    }
}
