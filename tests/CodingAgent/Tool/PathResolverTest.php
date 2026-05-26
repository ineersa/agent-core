<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Tool\PathResolver;
use PHPUnit\Framework\TestCase;

final class PathResolverTest extends TestCase
{
    /* ───────── Absolute paths ───────── */

    public function testAbsolutePathPassesThrough(): void
    {
        $this->assertSame('/etc/passwd', PathResolver::resolve('/etc/passwd'));
    }

    public function testAbsolutePathWithTrailingSlashIsNormalized(): void
    {
        $this->assertSame('/etc', PathResolver::resolve('/etc/'));
    }

    public function testAbsolutePathWithDoubleSlashIsCollapsed(): void
    {
        $this->assertSame('/etc/passwd', PathResolver::resolve('//etc//passwd'));
    }

    public function testRootPath(): void
    {
        $this->assertSame('/', PathResolver::resolve('/'));
    }

    public function testDotSlashAbsolutePath(): void
    {
        $this->assertSame('/foo/bar', PathResolver::resolve('/foo/./bar/./baz/..'));
    }

    public function testAboveRootIsClamped(): void
    {
        // /../../etc resolves to /etc — cannot go above root
        $this->assertSame('/etc', PathResolver::resolve('/../../etc'));
    }

    /* ───────── Relative paths ───────── */

    public function testRelativePathResolvesAgainstCwd(): void
    {
        $this->assertSame('/project/src/file.txt', PathResolver::resolve('src/file.txt', '/project'));
    }

    public function testRelativePathDefaultsToGetcwd(): void
    {
        // When no cwd is given, resolve() uses getcwd()
        $cwd = getcwd();
        \assert(\is_string($cwd));

        $this->assertSame($cwd.'/some/file', PathResolver::resolve('some/file'));
    }

    public function testRelativeDotSlashIsNormalized(): void
    {
        $this->assertSame('/project/src', PathResolver::resolve('./src', '/project'));
    }

    public function testRelativeDoubleDotTraversesUp(): void
    {
        $this->assertSame('/project', PathResolver::resolve('src/../', '/project'));
    }

    public function testRelativeDotDotGoesAboveCwd(): void
    {
        $this->assertSame('/project/other', PathResolver::resolve('../other', '/project/src'));
    }

    public function testRelativeDoubleDotGoesAboveCwdParent(): void
    {
        $this->assertSame('/other', PathResolver::resolve('../../other', '/project/src'));
    }

    public function testDotDotFromRootishRelative(): void
    {
        // src/../../ resolves to the cwd's parent
        $this->assertSame('/', PathResolver::resolve('src/../../', '/project'));
    }

    /* ───────── Empty path ───────── */

    public function testEmptyStringReturnsCwd(): void
    {
        $this->assertSame('/project', PathResolver::resolve('', '/project'));
    }

    /* ───────── Home directory expansion ───────── */

    public function testTildeExpandsToHome(): void
    {
        $home = self::getenvOrSkip('HOME');

        $result = PathResolver::resolve('~');

        $this->assertSame($home, $result);
    }

    public function testTildeSlashExpandsToHomeSubdirectory(): void
    {
        $home = self::getenvOrSkip('HOME');

        $result = PathResolver::resolve('~/config/.hatfield');

        $this->assertSame($home.'/config/.hatfield', $result);
    }

    public function testTildeSlashRemovesDotDotAboveHome(): void
    {
        $home = self::getenvOrSkip('HOME');

        $result = PathResolver::resolve('~/../etc');

        // $home/../etc → /etc (normalized)
        $this->assertStringEndsWith('/etc', $result);
        // The result should be a clean absolute path, not containing "$home/../"
        $this->assertStringNotContainsString('..', $result);
    }

    /* ───────── Complex normalization ───────── */

    public function testComplexMixOfDotsAndSlashes(): void
    {
        $path = '/a/b/../c/./d/../../e/f/';
        $this->assertSame('/a/e/f', PathResolver::resolve($path));
    }

    public function testPathThatNormalizesToNothingRelative(): void
    {
        // ".." from /project → /
        $this->assertSame('/', PathResolver::resolve('..', '/project'));
    }

    public function testOnlyDotsRelative(): void
    {
        $this->assertSame('/project', PathResolver::resolve('.', '/project'));
    }

    public function testMultipleDoubleDotsAboveRootRelative(): void
    {
        // ../../.. from /a/b → /a/b/../../.. → /
        $this->assertSame('/', PathResolver::resolve('../../..', '/a/b'));
    }

    /* ───────── Windows-style paths (behavioural compatibility) ───────── */

    public function testWindowsDriveLetterTreatedAsAbsolute(): void
    {
        // The resolver does not natively handle Windows but should not crash.
        $result = PathResolver::resolve('C:\\Users\\test\\file.txt');

        // Drive letter paths are recognised as absolute; the backslashes become /.
        $this->assertStringStartsWith('C:', $result);
        $this->assertStringNotContainsString('\\', $result);
    }

    /* ───────── Helper ───────── */

    /**
     * Return the given environment variable or mark the test as skipped
     * when it is not set (e.g. in minimal containers).
     */
    private static function getenvOrSkip(string $name): string
    {
        $value = getenv($name);
        if (!\is_string($value) || '' === $value) {
            self::markTestSkipped(\sprintf('Environment variable %s is not set.', $name));
        }

        return $value;
    }
}
