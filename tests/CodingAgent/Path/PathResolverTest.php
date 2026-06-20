<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Path;

use Ineersa\CodingAgent\Path\PathResolver;
use PHPUnit\Framework\TestCase;

final class PathResolverTest extends TestCase
{
    /* ──────── Null byte rejection ──────── */

    public function testNullByteInPathThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('null byte');
        PathResolver::resolve("/etc/passwd\0.txt");
    }

    public function testNullByteInCwdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('null byte');
        PathResolver::resolve('file', "/cwd\0");
    }

    /* ──────── Absolute paths ──────── */

    public function testAbsolutePathPassesThrough(): void
    {
        self::assertSame('/etc/passwd', PathResolver::resolve('/etc/passwd'));
    }

    public function testAbsolutePathWithTrailingSlashIsNormalized(): void
    {
        self::assertSame('/etc', PathResolver::resolve('/etc/'));
    }

    public function testAbsolutePathWithDoubleSlashIsCollapsed(): void
    {
        self::assertSame('/etc/passwd', PathResolver::resolve('//etc//passwd'));
    }

    public function testRootPath(): void
    {
        self::assertSame('/', PathResolver::resolve('/'));
    }

    public function testDotSlashAbsolutePath(): void
    {
        self::assertSame('/foo/bar', PathResolver::resolve('/foo/./bar/./baz/..'));
    }

    public function testAboveRootIsClamped(): void
    {
        self::assertSame('/etc', PathResolver::resolve('/../../etc'));
    }

    /* ──────── Relative paths ──────── */

    public function testRelativePathResolvesAgainstCwd(): void
    {
        self::assertSame('/project/src/file.txt', PathResolver::resolve('src/file.txt', '/project'));
    }

    public function testRelativePathDefaultsToGetcwd(): void
    {
        $cwd = getcwd();
        \assert(\is_string($cwd));

        self::assertSame($cwd.'/some/file', PathResolver::resolve('some/file'));
    }

    public function testRelativeDotSlashIsNormalized(): void
    {
        self::assertSame('/project/src', PathResolver::resolve('./src', '/project'));
    }

    public function testRelativeDoubleDotTraversesUp(): void
    {
        self::assertSame('/project', PathResolver::resolve('src/../', '/project'));
    }

    public function testRelativeDotDotGoesAboveCwd(): void
    {
        self::assertSame('/project/other', PathResolver::resolve('../other', '/project/src'));
    }

    public function testRelativeDoubleDotGoesAboveCwdParent(): void
    {
        self::assertSame('/other', PathResolver::resolve('../../other', '/project/src'));
    }

    public function testDotDotFromRootishRelative(): void
    {
        self::assertSame('/', PathResolver::resolve('src/../../', '/project'));
    }

    /* ──────── Empty path ──────── */

    public function testEmptyStringReturnsCwd(): void
    {
        self::assertSame('/project', PathResolver::resolve('', '/project'));
    }

    /* ──────── Home directory expansion ──────── */

    public function testTildeExpandsToHome(): void
    {
        $home = self::getenvOrSkip('HOME');

        $result = PathResolver::resolve('~');

        // Bare ~ should be normalized (not raw getenv result)
        self::assertTrue(
            str_starts_with($result, '/'),
            'Tilde expansion must return absolute path, got "'.$result.'"',
        );
        self::assertStringContainsString(basename($home), $result);
    }

    public function testTildeSlashExpandsToHomeSubdirectory(): void
    {
        $home = self::getenvOrSkip('HOME');

        $result = PathResolver::resolve('~/config/.hatfield');

        self::assertSame($home.'/config/.hatfield', $result);
    }

    public function testTildeSlashRemovesDotDotAboveHome(): void
    {
        $home = self::getenvOrSkip('HOME');

        $result = PathResolver::resolve('~/../etc');

        // $home/../etc → /etc (normalized)
        self::assertStringEndsWith('/etc', $result);
        self::assertStringNotContainsString('..', $result);
        // Must not contain double slash from concatenation
        self::assertStringNotContainsString('//', $result);
    }

    public function testTildeUserNotSupported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tilde expansion only supports bare ~');
        PathResolver::resolve('~jane/file');
    }

    public function testTildeUserBareNotSupported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tilde expansion only supports bare ~');
        PathResolver::resolve('~foo');
    }

    /* ──────── Cwd validation ──────── */

    public function testRelativeCwdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute');
        PathResolver::resolve('foo', 'relative/path');
    }

    public function testEmptyCwdDefaultsToGetcwd(): void
    {
        // Empty string cwd should default to getcwd()
        $cwd = getcwd();
        \assert(\is_string($cwd));

        self::assertSame($cwd.'/foo', PathResolver::resolve('foo', ''));
    }

    public function testEmptyCwdWithEmptyPath(): void
    {
        $cwd = getcwd();
        \assert(\is_string($cwd));

        self::assertSame($cwd, PathResolver::resolve('', ''));
    }

    /* ──────── Absolute-path detection ──────── */

    public function testColonPathIsRelativeOnUnix(): void
    {
        // A path like "a:b" is a valid Unix filename, NOT absolute
        $result = PathResolver::resolve('a:b', '/project');
        self::assertSame('/project/a:b', $result);
    }

    public function testDriveColonPathIsRelativeOnUnix(): void
    {
        // C:foo without separator should be treated as relative on Unix
        $result = PathResolver::resolve('C:foo', '/project');
        self::assertSame('/project/C:foo', $result);
    }

    public function testDriveColonSlashWindowsStyleAbsolute(): void
    {
        // C:/foo is an absolute Windows path — resolve as-is
        $result = PathResolver::resolve('C:/Users/test/file.txt');
        self::assertSame('C:/Users/test/file.txt', $result);
    }

    public function testDriveColonBackslashWindowsStyleAbsolute(): void
    {
        // C:\foo is an absolute Windows path — backslashes become /
        $result = PathResolver::resolve('C:\\Users\\test\\file.txt');
        self::assertSame('C:/Users/test/file.txt', $result);
    }

    /* ──────── Complex normalization ──────── */

    public function testComplexMixOfDotsAndSlashes(): void
    {
        $path = '/a/b/../c/./d/../../e/f/';
        self::assertSame('/a/e/f', PathResolver::resolve($path));
    }

    public function testPathThatNormalizesToNothingRelative(): void
    {
        self::assertSame('/', PathResolver::resolve('..', '/project'));
    }

    public function testOnlyDotsRelative(): void
    {
        self::assertSame('/project', PathResolver::resolve('.', '/project'));
    }

    public function testMultipleDoubleDotsAboveRootRelative(): void
    {
        self::assertSame('/', PathResolver::resolve('../../..', '/a/b'));
    }

    public function testEmbeddedTildeNotExpanded(): void
    {
        // Tilde not at position 0 must not be expanded
        self::assertSame('/foo/~/bar', PathResolver::resolve('/foo/~/bar'));
    }

    public function testDoubleTildeResolvesAsRelative(): void
    {
        // ~~ is a valid filename starting with ~, not ~user syntax
        self::assertSame('/project/~~', PathResolver::resolve('~~', '/project'));
    }

    /* ──────── Dot-Dot at root ──────── */

    public function testDotDotFromRootClamped(): void
    {
        // resolve('..', '/') → '/'
        self::assertSame('/', PathResolver::resolve('..', '/'));
    }

    public function testAboveRootDotDotFromRootClamped(): void
    {
        self::assertSame('/', PathResolver::resolve('../../', '/'));
    }

    /* ──────── Helper ──────── */

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
