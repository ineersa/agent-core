<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Docs;

use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Symfony\Component\Finder\Finder;

/**
 * Production documentation validation used by {@code castor docs:validate}.
 *
 * Validates selected built-in catalog entries, package-safe relative links,
 * local anchors, and the universal ≤25k character gate for maintained Markdown.
 *
 * @internal
 */
final class BuiltinDocsValidator
{
    public function __construct(
        private readonly BuiltinDocsCatalog $catalog = new BuiltinDocsCatalog(),
        private readonly BuiltinDocsMarkdownScanner $scanner = new BuiltinDocsMarkdownScanner(),
        private readonly MarkdownFrontmatterExtractor $extractor = new MarkdownFrontmatterExtractor(),
    ) {
    }

    /**
     * @return list<string> empty when valid
     */
    public function validate(string $appRoot): array
    {
        $appRoot = rtrim($appRoot, '/');
        $errors = [];

        try {
            $entries = $this->catalog->discover($appRoot);
        } catch (BuiltinDocsCatalogException $e) {
            return ['catalog: '.$e->getMessage()];
        }

        $byId = [];
        foreach ($entries as $entry) {
            $byId[$entry['id']] = $entry;
            if ($entry['charCount'] > BuiltinDocsCatalog::MAX_DOCUMENT_CHARS) {
                $errors[] = \sprintf(
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

        foreach ($this->sizeTargetPaths($appRoot) as $path) {
            $raw = (string) file_get_contents($path);
            $count = mb_strlen($raw, 'UTF-8');
            if ($count > BuiltinDocsCatalog::MAX_DOCUMENT_CHARS) {
                $rel = str_starts_with($path, $appRoot.'/') ? substr($path, \strlen($appRoot) + 1) : $path;
                $errors[] = \sprintf('%s exceeds %d characters (%d).', $rel, BuiltinDocsCatalog::MAX_DOCUMENT_CHARS, $count);
            }
        }

        $coreDocsRoot = $this->realpathOr($appRoot.'/'.BuiltinDocsCatalog::CORE_DOCS_RELATIVE);
        $apiDocsRoot = $this->realpathOr($appRoot.'/'.BuiltinDocsCatalog::EXTENSION_API_DOCS_RELATIVE);
        $apiPackageRoot = $this->realpathOr($appRoot.'/.hatfield/extensions/extension-api');

        foreach ($entries as $entry) {
            $body = $entry['body'];
            $rel = $entry['relativePath'];
            $headingSlugs = array_fill_keys($this->scanner->headingSlugs($body), true);

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

            foreach ($this->scanner->linkDestinations($body) as $target) {
                $target = trim($target);
                if ('' === $target || preg_match('#^[a-z][a-z0-9+.-]*:#i', $target)) {
                    // Skip absolute URIs (http:, mailto:, etc.) and empty.
                    continue;
                }

                $parts = explode('#', $target, 2);
                $targetPath = rawurldecode($parts[0]);
                $fragment = isset($parts[1]) ? rawurldecode($parts[1]) : null;

                if ('' === $targetPath) {
                    if (null !== $fragment && '' !== $fragment && !isset($headingSlugs[$fragment])) {
                        $errors[] = \sprintf('%s: unknown local anchor "#%s".', $rel, $fragment);
                    }
                    continue;
                }

                $candidate = \dirname($entry['absolutePath']).'/'.$targetPath;
                $resolved = realpath($candidate);
                if (false === $resolved || !is_file($resolved)) {
                    $errors[] = \sprintf('%s: broken or unbundled link target "%s".', $rel, $target);
                    continue;
                }

                $resolvedReal = $this->realpathOr($resolved);
                $id = pathinfo($resolved, \PATHINFO_FILENAME);
                $underCore = $this->isUnder($resolvedReal, $coreDocsRoot);
                $underApiDocs = $this->isUnder($resolvedReal, $apiDocsRoot);
                $underApiPackage = $this->isUnder($resolvedReal, $apiPackageRoot);

                if (BuiltinDocsCatalog::CORE_DOCS_RELATIVE === $entry['rootRelative']) {
                    $ok = str_ends_with($resolved, '.md')
                        && isset($byId[$id])
                        && ($underCore || $underApiDocs)
                        && (
                            ($underCore && ($byId[$id]['absolutePath'] === $resolved || realpath($byId[$id]['absolutePath']) === $resolvedReal))
                            || ($underApiDocs && ($byId[$id]['absolutePath'] === $resolved || realpath($byId[$id]['absolutePath']) === $resolvedReal))
                        );
                    if (!$ok) {
                        $errors[] = \sprintf('%s: core built-in doc link "%s" must target another selected built-in document at its canonical path.', $rel, $target);
                        continue;
                    }
                } else {
                    // Extension API docs: Markdown under the API docs root must itself be selected.
                    // Non-docs package source files may link within the packaged Extension API tree.
                    if ($underApiDocs) {
                        if (!str_ends_with($resolved, '.md') || !isset($byId[$id])) {
                            $errors[] = \sprintf('%s: Extension API doc link "%s" must target a selected built-in document.', $rel, $target);
                            continue;
                        }
                        $canonical = realpath($byId[$id]['absolutePath']);
                        if (false === $canonical || $canonical !== $resolvedReal) {
                            $errors[] = \sprintf('%s: Extension API doc link "%s" must resolve to the selected document path.', $rel, $target);
                            continue;
                        }
                    } elseif ($underCore) {
                        if (!str_ends_with($resolved, '.md') || !isset($byId[$id])) {
                            $errors[] = \sprintf('%s: link "%s" into core docs must target a selected built-in document.', $rel, $target);
                            continue;
                        }
                    } elseif (!$underApiPackage) {
                        $errors[] = \sprintf('%s: link "%s" resolves outside approved package roots.', $rel, $target);
                        continue;
                    }
                }

                if (null !== $fragment && '' !== $fragment) {
                    $targetBody = (string) file_get_contents($resolved);
                    $targetExtraction = $this->extractor->extract($targetBody);
                    $targetMarkdown = $targetExtraction['body'] ?? $targetBody;
                    $targetSlugs = array_fill_keys($this->scanner->headingSlugs($targetMarkdown), true);
                    if (!isset($targetSlugs[$fragment])) {
                        $errors[] = \sprintf('%s: unknown anchor "%s" in link "%s".', $rel, $fragment, $target);
                    }
                }
            }
        }

        if (is_dir($appRoot.'/internal-docs')) {
            $errors[] = 'internal-docs/ must be removed; built-in docs are selected from approved roots.';
        }

        return $errors;
    }

    /**
     * Maintained Markdown paths subject to the 25k size gate.
     *
     * @return list<string>
     */
    public function sizeTargetPaths(string $appRoot): array
    {
        $appRoot = rtrim($appRoot, '/');
        $paths = [];
        $excludeDirNames = [
            'vendor' => true,
            'var' => true,
            '.git' => true,
            'node_modules' => true,
            'dist' => true,
            'build' => true,
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($appRoot, \FilesystemIterator::SKIP_DOTS),
                static function (\SplFileInfo $current) use ($excludeDirNames): bool {
                    if ($current->isDir()) {
                        return !isset($excludeDirNames[$current->getFilename()]);
                    }

                    return true;
                },
            ),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && 'README.md' === $file->getFilename()) {
                $paths[] = $file->getPathname();
            }
        }

        foreach (BuiltinDocsCatalog::approvedRelativeRoots() as $relRoot) {
            $dir = $appRoot.'/'.$relRoot;
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

    private function realpathOr(string $path): string
    {
        $real = realpath($path);

        return false !== $real ? $real : $path;
    }

    private function isUnder(string $path, string $root): bool
    {
        $root = rtrim($root, '/');

        return $path === $root || str_starts_with($path, $root.'/');
    }
}
