<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Docs;

use Ineersa\CodingAgent\Docs\BuiltinDocsCatalog;
use Ineersa\CodingAgent\Docs\BuiltinDocsCatalogException;
use Ineersa\CodingAgent\Docs\BuiltinDocsMarkdownScanner;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: BuiltinDocsCatalog selects only strict YAML boolean builtin: true
 * under approved roots, fails closed on intended-but-invalid markers, rejects
 * symlink/path-escape candidates, uses shared AST H1 extraction, and exposes
 * GitHub-style heading slugs for package-safe anchor validation.
 */
final class BuiltinDocsCatalogTest extends TestCase
{
    private string $appRoot;

    protected function setUp(): void
    {
        $this->appRoot = TestDirectoryIsolation::createProjectTempDir('builtin-docs-catalog');
        TestDirectoryIsolation::ensureDirectory($this->appRoot.'/docs');
        TestDirectoryIsolation::ensureDirectory($this->appRoot.'/.hatfield/extensions/extension-api/docs');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->appRoot);
    }

    public function testDiscoversOnlyStrictBuiltinTrueAndSortsById(): void
    {
        $this->write('docs/z.md', "---\nbuiltin: true\ndescription: Z\n---\n\n# Z\n\nbody\n");
        $this->write('docs/a.md', "---\nbuiltin: true\ndescription: A\n---\n\n# A\n\nbody\n");
        $this->write('docs/skip.md', "# Skip\n\nunmarked\n");
        $this->write('docs/false.md', "---\nbuiltin: false\ndescription: no\n---\n\n# False\n\nno\n");
        $this->write('.hatfield/extensions/extension-api/docs/extension-api.md', "---\nbuiltin: true\ndescription: API\n---\n\n# API\n\nbody\n");

        $entries = (new BuiltinDocsCatalog())->discover($this->appRoot);
        $this->assertSame(['a', 'extension-api', 'z'], array_column($entries, 'id'));
    }

    public function testDuplicateIdAcrossApprovedRootsFailsClosed(): void
    {
        $this->write('docs/settings.md', "---\nbuiltin: true\ndescription: Core\n---\n\n# Settings\n\ncore\n");
        $this->write(
            '.hatfield/extensions/extension-api/docs/settings.md',
            "---\nbuiltin: true\ndescription: API\n---\n\n# Settings API\n\napi\n",
        );

        self::expectException(BuiltinDocsCatalogException::class);
        self::expectExceptionMessage('Duplicate built-in documentation id "settings"');
        (new BuiltinDocsCatalog())->discover($this->appRoot);
    }

    public function testMalformedYamlWithIntendedBuiltinTrueFailsClosed(): void
    {
        $this->write(
            'docs/broken.md',
            "---\nbuiltin: true\ndescription: [unterminated\n---\n\n# Broken\n\nbody\n",
        );

        self::expectException(BuiltinDocsCatalogException::class);
        self::expectExceptionMessage('invalid frontmatter YAML but appears to set builtin: true');
        (new BuiltinDocsCatalog())->discover($this->appRoot);
    }

    public function testBuiltinTrueWithTrailingGarbageFailsClosed(): void
    {
        // Symfony YAML accepts unquoted "true garbage" as a string scalar, not a parse error.
        // Intent detection must still fail closed because the token starts with true.
        $this->write(
            'docs/garbage.md',
            "---\nbuiltin: true garbage\ndescription: bad\n---\n\n# Garbage\n\nbody\n",
        );

        self::expectException(BuiltinDocsCatalogException::class);
        self::expectExceptionMessage('not as YAML boolean true');
        (new BuiltinDocsCatalog())->discover($this->appRoot);
    }

    public function testBuiltinTrueishDoesNotFailClosedAsIntendedMarker(): void
    {
        $this->write(
            'docs/trueish.md',
            "---\nbuiltin: trueish\ndescription: no\n---\n\n# Trueish\n\nbody\n",
        );

        $entries = (new BuiltinDocsCatalog())->discover($this->appRoot);
        $this->assertSame([], $entries);
    }

    public function testBodyProseAboutBuiltinTrueDoesNotFailClosedWithoutFrontmatterMarker(): void
    {
        $this->write(
            'docs/prose.md',
            "# Prose\n\nRepository docs may mention builtin: true without selecting the file.\n",
        );

        $entries = (new BuiltinDocsCatalog())->discover($this->appRoot);
        $this->assertSame([], $entries);
    }

    public function testMissingClosingFrontmatterDelimiterWithBuiltinTrueFailsClosed(): void
    {
        $this->write(
            'docs/unclosed.md',
            "---\nbuiltin: true\ndescription: unclosed\n\n# Unclosed\n\nbody without closing delimiter\n",
        );

        self::expectException(BuiltinDocsCatalogException::class);
        self::expectExceptionMessage('missing a closing delimiter');
        (new BuiltinDocsCatalog())->discover($this->appRoot);
    }

    public function testNonBooleanBuiltinTrueStringFailsClosed(): void
    {
        $this->write(
            'docs/quoted.md',
            "---\nbuiltin: \"true\"\ndescription: quoted\n---\n\n# Quoted\n\nbody\n",
        );

        self::expectException(BuiltinDocsCatalogException::class);
        self::expectExceptionMessage('not as YAML boolean true');
        (new BuiltinDocsCatalog())->discover($this->appRoot);
    }

    public function testSymlinkCandidateIsRejected(): void
    {
        if (!\function_exists('symlink')) {
            $this->markTestSkipped('symlink() unavailable on this platform');
        }

        $targetOutside = $this->appRoot.'/outside-settings.md';
        file_put_contents(
            $targetOutside,
            "---\nbuiltin: true\ndescription: Escape\n---\n\n# Escape\n\nbody\n",
        );
        $link = $this->appRoot.'/docs/settings.md';
        if (!@symlink($targetOutside, $link)) {
            $this->markTestSkipped('Unable to create symlink in test environment');
        }

        self::expectException(BuiltinDocsCatalogException::class);
        self::expectExceptionMessage('must not be a symlink');
        (new BuiltinDocsCatalog())->discover($this->appRoot);
    }

    public function testSymlinkedDocsRootOutsideAppRootFailsClosedWithoutDeletingExternalFixture(): void
    {
        if (!\function_exists('symlink')) {
            $this->markTestSkipped('symlink() unavailable on this platform');
        }

        $externalRoot = TestDirectoryIsolation::createProjectTempDir('builtin-docs-external-root');
        try {
            TestDirectoryIsolation::ensureDirectory($externalRoot.'/docs');
            file_put_contents(
                $externalRoot.'/docs/escape.md',
                "---\nbuiltin: true\ndescription: External\n---\n\n# External\n\nbody\n",
            );

            // Replace in-tree docs/ with a symlink to the external fixture.
            TestDirectoryIsolation::removeDirectory($this->appRoot.'/docs');
            if (!@symlink($externalRoot.'/docs', $this->appRoot.'/docs')) {
                $this->markTestSkipped('Unable to create docs-root symlink in test environment');
            }

            try {
                (new BuiltinDocsCatalog())->discover($this->appRoot);
                $this->fail('Expected escaped docs root to fail closed');
            } catch (BuiltinDocsCatalogException $e) {
                $this->assertStringContainsString('outside application root', $e->getMessage());
            }

            $this->assertFileExists($externalRoot.'/docs/escape.md');
        } finally {
            // Cleanup only the external fixture tree; appRoot tearDown must not follow the symlink.
            if (is_link($this->appRoot.'/docs')) {
                unlink($this->appRoot.'/docs');
                TestDirectoryIsolation::ensureDirectory($this->appRoot.'/docs');
            }
            TestDirectoryIsolation::removeDirectory($externalRoot);
        }
    }

    public function testTildeFencedDecoyAndIndentedHeadingUseSharedScanner(): void
    {
        $this->write(
            'docs/fence.md',
            "---\nbuiltin: true\ndescription: Fence\n---\n\n~~~markdown\n# Decoy Title\n~~~\n\n   # Real Title\n\nbody\n",
        );

        $entries = (new BuiltinDocsCatalog())->discover($this->appRoot);
        $this->assertCount(1, $entries);
        $this->assertSame('Real Title', $entries[0]['title']);
    }

    public function testPathIsUnderRootUsesSeparatorBoundary(): void
    {
        $this->assertTrue(BuiltinDocsCatalog::pathIsUnderRoot('/app/docs/settings.md', '/app/docs'));
        $this->assertTrue(BuiltinDocsCatalog::pathIsUnderRoot('/app/docs', '/app/docs'));
        $this->assertFalse(BuiltinDocsCatalog::pathIsUnderRoot('/app/docs2/settings.md', '/app/docs'));
        $this->assertFalse(BuiltinDocsCatalog::pathIsUnderRoot('/app/doc/settings.md', '/app/docs'));
    }

    public function testHeadingSlugsUseGitHubStyleWithDuplicateSuffixes(): void
    {
        $md = "# Hello World\n\n## Hello World\n\n### API / Overview!\n";
        $slugs = (new BuiltinDocsMarkdownScanner())->headingSlugs($md);
        $this->assertSame(['hello-world', 'hello-world-1', 'api-overview'], $slugs);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->appRoot.'/'.$relative;
        $dir = \dirname($path);
        TestDirectoryIsolation::ensureDirectory($dir);
        file_put_contents($path, $contents);
    }
}
