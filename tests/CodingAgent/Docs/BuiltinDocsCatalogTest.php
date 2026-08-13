<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Docs;

use Ineersa\CodingAgent\Docs\BuiltinDocsCatalog;
use Ineersa\CodingAgent\Docs\BuiltinDocsCatalogException;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: BuiltinDocsCatalog selects only strict YAML boolean builtin: true
 * under approved roots, fails closed on intended-but-invalid markers, and
 * exposes GitHub-style heading slugs for package-safe anchor validation.
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

    public function testHeadingSlugsUseGitHubStyleWithDuplicateSuffixes(): void
    {
        $md = "# Hello World\n\n## Hello World\n\n### API / Overview!\n";
        $slugs = BuiltinDocsCatalog::headingSlugsFromMarkdown($md);
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
