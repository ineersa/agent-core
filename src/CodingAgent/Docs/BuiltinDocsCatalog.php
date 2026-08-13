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
     * Absolute approved documentation roots for $appRoot.
     *
     * @return list<string>
     */
    public function absoluteRoots(string $appRoot): array
    {
        $appRoot = rtrim($appRoot, '/');

        return array_map(
            static fn (string $relative): string => $appRoot.'/'.$relative,
            self::approvedRelativeRoots(),
        );
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
        $appRoot = rtrim($appRoot, '/');
        $byId = [];

        foreach (self::approvedRelativeRoots() as $rootRelative) {
            $rootPath = $appRoot.'/'.$rootRelative;
            if (!is_dir($rootPath)) {
                continue;
            }

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
        if (null === $extraction['yamlBlock'] || !$extraction['hasOpeningDelimiter'] || !$extraction['hasClosingDelimiter']) {
            // Repository-only docs may omit frontmatter entirely.
            return null;
        }

        try {
            $parsed = Yaml::parse($extraction['yamlBlock']);
        } catch (ParseException) {
            // Invalid unmarked frontmatter is ignored at discovery time.
            return null;
        }

        if (!\is_array($parsed) || !\array_key_exists('builtin', $parsed) || true !== $parsed['builtin']) {
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
     * Enforce exactly one useful Markdown H1 outside fenced code blocks.
     */
    private function extractSingleUsefulH1(string $body, string $id, string $pathForErrors): string
    {
        $titles = [];
        $inFence = false;
        $lines = preg_split("/\n/", $body);
        if (!\is_array($lines)) {
            $lines = [];
        }
        foreach ($lines as $line) {
            if (preg_match('/^\s*```/', $line)) {
                $inFence = !$inFence;
                continue;
            }
            if ($inFence) {
                continue;
            }
            if (preg_match('/^#\s+(.+?)\s*$/', $line, $matches)) {
                $title = trim($matches[1]);
                if ('' !== $title) {
                    $titles[] = $title;
                }
            }
        }

        if (1 !== \count($titles)) {
            throw new BuiltinDocsCatalogException(\sprintf('Document "%s" (%s) must contain exactly one useful H1 title outside code fences (found %d).', $id, $pathForErrors, \count($titles)));
        }

        return $titles[0];
    }
}
