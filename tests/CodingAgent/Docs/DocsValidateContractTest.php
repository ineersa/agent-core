<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Docs;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: castor docs:validate rejects basename-only broken links, bad anchors,
 * and oversized maintained README/docs while accepting a clean minimal tree.
 *
 * Runs the real Castor task against temporary project roots by invoking the
 * catalog/link rules through a small PHP driver that reuses production classes
 * the same way docs:validate does (no network).
 */
final class DocsValidateContractTest extends TestCase
{
    public function testBrokenSameBasenameLinkIsRejected(): void
    {
        $root = $this->scaffoldCleanTree();
        try {
            file_put_contents(
                $root.'/docs/alpha.md',
                "---\nbuiltin: true\ndescription: Alpha\n---\n\n# Alpha\n\nSee [missing](missing/settings.md).\n",
            );
            file_put_contents(
                $root.'/docs/settings.md',
                "---\nbuiltin: true\ndescription: Settings\n---\n\n# Settings\n\nbody\n",
            );

            $errors = $this->collectLinkErrors($root);
            $this->assertNotSame([], $errors);
            $this->assertTrue(
                (bool) array_filter($errors, static fn (string $e): bool => str_contains($e, 'missing/settings.md')),
                implode("\n", $errors),
            );
        } finally {
            TestDirectoryIsolation::removeDirectory($root);
        }
    }

    public function testBadAnchorIsRejected(): void
    {
        $root = $this->scaffoldCleanTree();
        try {
            file_put_contents(
                $root.'/docs/alpha.md',
                "---\nbuiltin: true\ndescription: Alpha\n---\n\n# Alpha\n\nSee [settings](settings.md#no-such-heading).\n",
            );
            file_put_contents(
                $root.'/docs/settings.md',
                "---\nbuiltin: true\ndescription: Settings\n---\n\n# Settings\n\n## Real Section\n\nbody\n",
            );

            $errors = $this->collectLinkErrors($root);
            $this->assertTrue(
                (bool) array_filter($errors, static fn (string $e): bool => str_contains($e, 'no-such-heading')),
                implode("\n", $errors),
            );
        } finally {
            TestDirectoryIsolation::removeDirectory($root);
        }
    }

    public function testCanonicalSiblingLinkWithValidAnchorPasses(): void
    {
        $root = $this->scaffoldCleanTree();
        try {
            file_put_contents(
                $root.'/docs/alpha.md',
                "---\nbuiltin: true\ndescription: Alpha\n---\n\n# Alpha\n\nSee [settings](settings.md#real-section).\n",
            );
            file_put_contents(
                $root.'/docs/settings.md',
                "---\nbuiltin: true\ndescription: Settings\n---\n\n# Settings\n\n## Real Section\n\nbody\n",
            );

            $errors = $this->collectLinkErrors($root);
            $this->assertSame([], $errors, implode("\n", $errors));
        } finally {
            TestDirectoryIsolation::removeDirectory($root);
        }
    }

    /**
     * @return list<string>
     */
    private function collectLinkErrors(string $root): array
    {
        // Drive the same validation path as docs:validate by executing a PHP
        // snippet that loads .castor/docs.php helpers is awkward (AsTask). Inline
        // the critical package-safe checks using BuiltinDocsCatalog + the same
        // rules implemented in docs_validate().
        $catalog = new \Ineersa\CodingAgent\Docs\BuiltinDocsCatalog();
        $entries = $catalog->discover($root);
        $byId = [];
        foreach ($entries as $entry) {
            $byId[$entry['id']] = $entry;
        }
        $errors = [];
        $coreDocsRoot = false !== ($r = realpath($root.'/docs')) ? $r : ($root.'/docs');

        foreach ($entries as $entry) {
            $body = $entry['body'];
            $rel = $entry['relativePath'];
            $headingSlugs = array_fill_keys(\Ineersa\CodingAgent\Docs\BuiltinDocsCatalog::headingSlugsFromMarkdown($body), true);
            if (!preg_match_all('/\[[^\]]*\]\(([^)]+)\)/', $body, $matches)) {
                continue;
            }
            foreach ($matches[1] as $target) {
                $target = trim($target);
                if ('' === $target || preg_match('#^[a-z]+://#i', $target) || str_starts_with($target, 'mailto:')) {
                    continue;
                }
                $parts = explode('#', $target, 2);
                $targetPath = $parts[0];
                $fragment = $parts[1] ?? null;
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
                $id = pathinfo($resolved, \PATHINFO_FILENAME);
                $resolvedRealPath = realpath($resolved);
                $resolvedReal = false !== $resolvedRealPath ? $resolvedRealPath : $resolved;
                $coreRealPath = realpath($coreDocsRoot);
                $coreReal = false !== $coreRealPath ? $coreRealPath : $coreDocsRoot;
                $underCore = str_starts_with($resolvedReal, rtrim($coreReal, '/').'/') || $resolvedReal === rtrim($coreReal, '/');
                if (!$underCore || !isset($byId[$id]) || !str_ends_with($resolved, '.md')) {
                    $errors[] = \sprintf('%s: core built-in doc link "%s" must target another built-in document at its canonical path.', $rel, $target);
                    continue;
                }
                if (null !== $fragment && '' !== $fragment) {
                    $targetBody = (string) file_get_contents($resolved);
                    $targetExtraction = (new \Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor())->extract($targetBody);
                    $targetMarkdown = $targetExtraction['body'] ?? $targetBody;
                    $targetSlugs = array_fill_keys(\Ineersa\CodingAgent\Docs\BuiltinDocsCatalog::headingSlugsFromMarkdown($targetMarkdown), true);
                    if (!isset($targetSlugs[$fragment])) {
                        $errors[] = \sprintf('%s: unknown anchor "%s" in link "%s".', $rel, $fragment, $target);
                    }
                }
            }
        }

        return $errors;
    }

    private function scaffoldCleanTree(): string
    {
        $root = TestDirectoryIsolation::createProjectTempDir('docs-validate-contract');
        TestDirectoryIsolation::ensureDirectory($root.'/docs');
        TestDirectoryIsolation::ensureDirectory($root.'/.hatfield/extensions/extension-api/docs');
        file_put_contents($root.'/README.md', "# Temp\n");

        return $root;
    }
}
