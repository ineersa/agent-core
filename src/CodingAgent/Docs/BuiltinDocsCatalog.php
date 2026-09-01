<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Docs;

use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Discovers model-visible / package-selected Markdown documentation.
 *
 * Approved roots only:
 * - top-level {@see docs/*.md}
 * - {@see .hatfield/extensions/extension-api/docs/*.md}
 *
 * Selection requires strict YAML frontmatter {@code builtin: true}.
 * Catalog IDs are filename stems and must be unique across roots.
 *
 * @internal
 */
final class BuiltinDocsCatalog
{
    public const int MAX_DOCUMENT_CHARS = 25000;

    public const string CORE_DOCS_RELATIVE = 'docs';

    public const string EXTENSION_API_DOCS_RELATIVE = '.hatfield/extensions/extension-api/docs';

    public function __construct(
        private readonly MarkdownFrontmatterExtractor $extractor = new MarkdownFrontmatterExtractor(),
    ) {
    }

    /**
     * Relative approved documentation roots under an app/install root.
     *
     * @return list<string>
     */
    public static function approvedRelativeRoots(): array
    {
        return [
            self::CORE_DOCS_RELATIVE,
            self::EXTENSION_API_DOCS_RELATIVE,
        ];
    }

    /**
     * Discover marked built-in documents under the approved roots.
     *
     * @return list<array{
     *     id: string,
     *     title: string,
     *     description: string,
     *     body: string,
     *     absolutePath: string,
     *     relativePath: string,
     *     rootRelative: string,
     *     charCount: int
     * }>
     */
    public function discover(string $appRoot): array
    {
        $appRootResolved = $this->resolveDirectoryPath($appRoot, 'Application root');
        $byId = [];

        foreach (self::approvedRelativeRoots() as $rootRelative) {
            $rootPath = rtrim($appRoot, '/').'/'.$rootRelative;
            if (!is_dir($rootPath) && !is_link($rootPath)) {
                continue;
            }

            $rootResolved = $this->assertApprovedRootContainedInAppRoot($rootPath, $appRootResolved, $rootRelative);

            $files = Finder::create()
                ->files()
                ->in($rootPath)
                ->depth('== 0')
                ->name('*.md')
                ->sortByName();

            foreach ($files as $file) {
                $id = $file->getBasename('.md');
                if ('' === $id) {
                    continue;
                }

                $absolutePath = $file->getPathname();
                $this->assertCandidateContainedInRoot($absolutePath, $rootResolved, $id);

                $raw = @file_get_contents($absolutePath);
                if (false === $raw) {
                    throw new BuiltinDocsCatalogException(\sprintf('Unable to read documentation file "%s".', $absolutePath));
                }

                $parsed = $this->parseCandidate($raw, $id, $absolutePath);
                if (null === $parsed) {
                    continue;
                }

                $relativePath = $rootRelative.'/'.$id.'.md';
                if (isset($byId[$id])) {
                    throw new BuiltinDocsCatalogException(\sprintf('Duplicate built-in documentation id "%s" at "%s" and "%s".', $id, $byId[$id]['relativePath'], $relativePath));
                }

                $byId[$id] = [
                    'id' => $id,
                    'title' => $parsed['title'],
                    'description' => $parsed['description'],
                    'body' => $parsed['body'],
                    'absolutePath' => $absolutePath,
                    'relativePath' => $relativePath,
                    'rootRelative' => $rootRelative,
                    'charCount' => $parsed['charCount'],
                ];
            }
        }

        ksort($byId);

        return array_values($byId);
    }

    /**
     * @return list<string> absolute paths of marked documents
     */
    public function selectedAbsolutePaths(string $appRoot): array
    {
        return array_map(
            static fn (array $entry): string => $entry['absolutePath'],
            $this->discover($appRoot),
        );
    }

    /**
     * Parse one candidate Markdown file. Returns null when builtin is not true.
     *
     * @return array{title: string, description: string, body: string, charCount: int}|null
     */
    public function parseCandidate(string $raw, string $id, string $pathForErrors): ?array
    {
        $charCount = mb_strlen($raw, 'UTF-8');
        $extraction = $this->extractor->extract($raw);

        // Fail closed when an intended builtin marker is present but frontmatter is incomplete
        // (missing closing delimiter yields yamlBlock=null with hasOpeningDelimiter=true).
        if ($extraction['hasOpeningDelimiter'] && !$extraction['hasClosingDelimiter']) {
            if (self::frontmatterRegionMentionsBuiltinTrue($raw)) {
                throw new BuiltinDocsCatalogException(\sprintf('Document "%s" (%s) opens frontmatter and appears to set builtin: true but is missing a closing delimiter.', $id, $pathForErrors));
            }

            // Incomplete unmarked frontmatter is ignored at discovery time.
            return null;
        }

        if (null === $extraction['yamlBlock'] || !$extraction['hasOpeningDelimiter'] || !$extraction['hasClosingDelimiter']) {
            // Repository-only docs may omit frontmatter entirely.
            return null;
        }

        $yamlBlock = $extraction['yamlBlock'];
        $intendsBuiltin = self::yamlBlockMentionsBuiltinTrue($yamlBlock);

        try {
            $parsed = Yaml::parse($yamlBlock);
        } catch (ParseException $e) {
            if ($intendsBuiltin) {
                throw new BuiltinDocsCatalogException(\sprintf('Document "%s" (%s) has invalid frontmatter YAML but appears to set builtin: true: %s', $id, $pathForErrors, $e->getMessage()), 0, $e);
            }

            // Invalid unmarked frontmatter is ignored at discovery time.
            return null;
        }

        if (!\is_array($parsed)) {
            if ($intendsBuiltin) {
                throw new BuiltinDocsCatalogException(\sprintf('Document "%s" (%s) frontmatter must parse to a map when builtin: true is intended.', $id, $pathForErrors));
            }

            return null;
        }

        if (!\array_key_exists('builtin', $parsed)) {
            return null;
        }

        // Strict selection requires YAML boolean true only.
        if (true !== $parsed['builtin']) {
            if ($intendsBuiltin) {
                throw new BuiltinDocsCatalogException(\sprintf('Document "%s" (%s) frontmatter sets builtin but not as YAML boolean true.', $id, $pathForErrors));
            }

            return null;
        }

        // Strict validation only for selected built-in documents.
        if (!isset($parsed['description']) || !\is_string($parsed['description']) || '' === trim($parsed['description'])) {
            throw new BuiltinDocsCatalogException(\sprintf('Document "%s" (%s) frontmatter must include a non-empty string description.', $id, $pathForErrors));
        }

        $body = $extraction['body'];
        $title = $this->extractSingleUsefulH1($body, $id, $pathForErrors);

        return [
            'title' => $title,
            'description' => trim($parsed['description']),
            'body' => $body,
            'charCount' => $charCount,
        ];
    }

    /**
     * GitHub-style heading slug used for local Markdown fragment validation.
     */
    public static function githubStyleHeadingSlug(string $heading): string
    {
        $slug = mb_strtolower(trim($heading), 'UTF-8');
        $slug = preg_replace('/[^\p{L}\p{N}\s_-]+/u', '', $slug) ?? '';
        $slug = preg_replace('/[\s_]+/u', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug;
    }

    /**
     * Separator-boundary containment: root match or root + '/' prefix only.
     */
    public static function pathIsUnderRoot(string $path, string $root): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);

        return $path === $root || str_starts_with($path, $root.'/');
    }

    /**
     * Enforce exactly one useful Markdown H1 outside fenced code blocks via shared AST scanner.
     */
    private function extractSingleUsefulH1(string $body, string $id, string $pathForErrors): string
    {
        $result = (new BuiltinDocsMarkdownScanner())->usefulH1($body);
        if (1 !== $result['count'] || '' === $result['title']) {
            throw new BuiltinDocsCatalogException(\sprintf('Document "%s" (%s) must contain exactly one useful H1 title outside code fences (found %d).', $id, $pathForErrors, $result['count']));
        }

        return $result['title'];
    }

    /**
     * Reject symlink candidates and path escapes outside the approved root.
     */
    private function assertCandidateContainedInRoot(string $absolutePath, string $rootResolved, string $id): void
    {
        if (is_link($absolutePath)) {
            throw new BuiltinDocsCatalogException(\sprintf('Document "%s" (%s) must not be a symlink; built-in docs must be regular files under the approved root.', $id, $absolutePath));
        }

        $resolved = $this->resolveFilePath($absolutePath, \sprintf('Document "%s"', $id));
        if (!self::pathIsUnderRoot($resolved, $rootResolved)) {
            throw new BuiltinDocsCatalogException(\sprintf('Document "%s" (%s) resolves outside approved root "%s".', $id, $absolutePath, $rootResolved));
        }
    }

    /**
     * Resolve an approved docs root and ensure it remains under the app root.
     *
     * Symlinked roots that escape the resolved app root (including parent
     * component escapes) are rejected clearly. A normal in-tree root may itself
     * resolve safely (including when the app root is reached via a safe realpath).
     * Stream wrappers such as {@code phar://} may not support realpath(); those
     * keep the logical path after is_dir checks.
     */
    private function assertApprovedRootContainedInAppRoot(string $rootPath, string $appRootResolved, string $rootRelative): string
    {
        $rootResolved = $this->resolveDirectoryPath($rootPath, \sprintf('Approved documentation root "%s"', $rootRelative));

        if (!self::pathIsUnderRoot($rootResolved, $appRootResolved)) {
            throw new BuiltinDocsCatalogException(\sprintf('Approved documentation root "%s" resolves to "%s", which is outside application root "%s".', $rootRelative, $rootResolved, $appRootResolved));
        }

        return $rootResolved;
    }

    /**
     * Resolve a directory path, preferring realpath when available.
     *
     * realpath() returns false for stream wrappers like phar:// even when is_dir()
     * succeeds; fall back to the normalized logical path in that case so PHAR
     * discovery continues to work while filesystem symlink escapes still fail closed.
     */
    private function resolveDirectoryPath(string $path, string $label): string
    {
        $path = rtrim($path, '/');
        $resolved = realpath($path);
        if (false !== $resolved && is_dir($resolved)) {
            return $resolved;
        }

        if (is_dir($path)) {
            return $path;
        }

        throw new BuiltinDocsCatalogException(\sprintf('%s "%s" could not be resolved as a directory.', $label, $path));
    }

    /**
     * Resolve a regular file path, preferring realpath when available.
     */
    private function resolveFilePath(string $path, string $label): string
    {
        $resolved = realpath($path);
        if (false !== $resolved && is_file($resolved)) {
            return $resolved;
        }

        if (is_file($path) && !is_link($path)) {
            return $path;
        }

        throw new BuiltinDocsCatalogException(\sprintf('%s (%s) could not be resolved as a regular file under the approved root.', $label, $path));
    }

    /**
     * Detect an intended builtin: true marker in raw YAML text even when parse fails.
     *
     * Matches a line whose key is builtin and whose scalar token is true / "true" / 1,
     * including invalid trailing content after that token (fail closed). Does not match
     * trueish or unrelated prose. Selection still requires parsed YAML boolean true.
     */
    private static function yamlBlockMentionsBuiltinTrue(string $yamlBlock): bool
    {
        return 1 === preg_match(
            '/^\s*builtin\s*:\s*(?:true|["\']true["\']|1)(?![A-Za-z0-9_])/mi',
            $yamlBlock,
        );
    }

    /**
     * Detect intended builtin: true only inside the opening frontmatter region.
     *
     * When the closing delimiter is missing, inspect from the opening --- through the
     * remainder of the file so body prose cannot create false positives for unmarked docs.
     * When a closed frontmatter block exists, callers should use the yaml block only.
     */
    private static function frontmatterRegionMentionsBuiltinTrue(string $raw): bool
    {
        if (!str_starts_with(ltrim($raw, " \t"), '---')) {
            return false;
        }

        // Opening delimiter present; strip it and scan only that region (no body after a close).
        $trimmed = ltrim($raw, " \t");
        $afterOpen = preg_replace('/^---\R?/', '', $trimmed, 1) ?? $trimmed;
        if (1 === preg_match('/^(.*?)(?:\R---\s*(?:\R|$))/s', $afterOpen, $m)) {
            return self::yamlBlockMentionsBuiltinTrue($m[1]);
        }

        // No closing delimiter: entire remainder is the incomplete frontmatter region.
        return self::yamlBlockMentionsBuiltinTrue($afterOpen);
    }
}
