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

    // Universal size limit: every tracked source README.md + top-level docs + API docs.
    foreach (docs_size_target_paths($root) as $path) {
        $raw = (string) file_get_contents($path);
        $count = mb_strlen($raw, 'UTF-8');
        if ($count > BuiltinDocsCatalog::MAX_DOCUMENT_CHARS) {
            $rel = str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : $path;
            $errors[] = sprintf('%s exceeds %d characters (%d).', $rel, BuiltinDocsCatalog::MAX_DOCUMENT_CHARS, $count);
        }
    }

    $coreDocsRoot = realpath($root.'/'.BuiltinDocsCatalog::CORE_DOCS_RELATIVE) ?: ($root.'/'.BuiltinDocsCatalog::CORE_DOCS_RELATIVE);
    $apiDocsRoot = realpath($root.'/'.BuiltinDocsCatalog::EXTENSION_API_DOCS_RELATIVE) ?: ($root.'/'.BuiltinDocsCatalog::EXTENSION_API_DOCS_RELATIVE);
    $apiPackageRoot = realpath($root.'/.hatfield/extensions/extension-api') ?: ($root.'/.hatfield/extensions/extension-api');

    // Package-safe reference checks for built-in docs only.
    foreach ($entries as $entry) {
        $body = $entry['body'];
        $rel = $entry['relativePath'];
        $headingSlugs = array_fill_keys(BuiltinDocsCatalog::headingSlugsFromMarkdown($body), true);

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

        // Relative markdown links + local anchors.
        if (preg_match_all('/\[[^\]]*\]\(([^)]+)\)/', $body, $matches)) {
            foreach ($matches[1] as $target) {
                $target = trim($target);
                if ('' === $target || preg_match('#^[a-z]+://#i', $target) || str_starts_with($target, 'mailto:')) {
                    continue;
                }

                $parts = explode('#', $target, 2);
                $targetPath = $parts[0];
                $fragment = $parts[1] ?? null;

                if ('' === $targetPath) {
                    // Same-document anchor.
                    if (null !== $fragment && '' !== $fragment && !isset($headingSlugs[$fragment])) {
                        $errors[] = sprintf('%s: unknown local anchor "#%s".', $rel, $fragment);
                    }
                    continue;
                }

                $baseDir = dirname($entry['absolutePath']);
                $candidate = $baseDir.'/'.$targetPath;
                $resolved = realpath($candidate);
                if (false === $resolved || !is_file($resolved)) {
                    $errors[] = sprintf('%s: broken or unbundled link target "%s".', $rel, $target);
                    continue;
                }

                if (BuiltinDocsCatalog::CORE_DOCS_RELATIVE === $entry['rootRelative']) {
                    $id = pathinfo($resolved, \PATHINFO_FILENAME);
                    $resolvedReal = realpath($resolved) ?: $resolved;
                    $coreReal = realpath($coreDocsRoot) ?: $coreDocsRoot;
                    $underCore = str_starts_with($resolvedReal, rtrim($coreReal, '/').'/')
                        || $resolvedReal === rtrim($coreReal, '/');
                    // Core built-ins may only link to other selected built-in markdown under docs/.
                    if (!$underCore || !isset($byId[$id]) || !str_ends_with($resolved, '.md')) {
                        // Allow selected API docs only via exact catalog relative path resolution under API docs root.
                        $apiReal = realpath($apiDocsRoot) ?: $apiDocsRoot;
                        $underApi = str_starts_with($resolvedReal, rtrim($apiReal, '/').'/');
                        if (!($underApi && isset($byId[$id]) && str_ends_with($resolved, '.md'))) {
                            // Prefer sibling core built-ins; reject anything else including basename-only misses.
                            if (!($underCore && isset($byId[$id]) && str_ends_with($resolved, '.md'))) {
                                $errors[] = sprintf('%s: core built-in doc link "%s" must target another built-in document at its canonical path.', $rel, $target);
                                continue;
                            }
                        }
                    }
                } else {
                    $allowedPrefixes = [
                        realpath($coreDocsRoot) ?: $coreDocsRoot,
                        realpath($apiDocsRoot) ?: $apiDocsRoot,
                        realpath($apiPackageRoot) ?: $apiPackageRoot,
                    ];
                    $ok = false;
                    $resolvedReal = realpath($resolved) ?: $resolved;
                    foreach ($allowedPrefixes as $prefix) {
                        if (is_string($prefix) && (str_starts_with($resolvedReal, rtrim($prefix, '/').'/') || $resolvedReal === rtrim((string) $prefix, '/'))) {
                            $ok = true;
                            break;
                        }
                    }
                    if (!$ok) {
                        $errors[] = sprintf('%s: link "%s" resolves outside approved package roots.', $rel, $target);
                        continue;
                    }
                }

                if (null !== $fragment && '' !== $fragment) {
                    $targetBody = (string) file_get_contents($resolved);
                    // Strip frontmatter for heading scan when present.
                    $targetExtraction = (new Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor())->extract($targetBody);
                    $targetMarkdown = $targetExtraction['body'] ?? $targetBody;
                    $targetSlugs = array_fill_keys(BuiltinDocsCatalog::headingSlugsFromMarkdown($targetMarkdown), true);
                    if (!isset($targetSlugs[$fragment])) {
                        $errors[] = sprintf('%s: unknown anchor "%s" in link "%s".', $rel, $fragment, $target);
                    }
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

/**
 * Maintained Markdown paths under the source tree subject to the 25k size gate.
 *
 * @return list<string>
 */
function docs_size_target_paths(string $root): array
{
    $paths = [];
    $excludeDirNames = [
        'vendor' => true,
        'var' => true,
        '.git' => true,
        'node_modules' => true,
        'dist' => true,
        'build' => true,
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $current) use ($excludeDirNames): bool {
                if ($current->isDir()) {
                    return !isset($excludeDirNames[$current->getFilename()]);
                }

                return true;
            },
        ),
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile()) {
            continue;
        }
        if ('README.md' === $file->getFilename()) {
            $paths[] = $file->getPathname();
        }
    }

    foreach ([BuiltinDocsCatalog::CORE_DOCS_RELATIVE, BuiltinDocsCatalog::EXTENSION_API_DOCS_RELATIVE] as $relRoot) {
        $dir = $root.'/'.$relRoot;
        if (!is_dir($dir)) {
            continue;
        }
        $finder = Finder::create()->files()->in($dir)->depth('== 0')->name('*.md');
        foreach ($finder as $file) {
            $paths[] = $file->getPathname();
        }
    }

    $paths = array_values(array_unique($paths));
    sort($paths);

    return $paths;
}
