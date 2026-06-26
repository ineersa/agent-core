<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard\Classifier;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier\SafeGuardPathMatcher;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SafeGuardPathMatcher — faithful port of Pi's path matching logic.
 */
final class SafeGuardPathMatcherTest extends TestCase
{
    private SafeGuardPathMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new SafeGuardPathMatcher();
    }

    // ── isInsideCwd ──

    #[DataProvider('isInsideCwdProvider')]
    public function testIsInsideCwd(string $cwd, string $path, bool $expected): void
    {
        $this->assertSame($expected, $this->matcher->isInsideCwd($cwd, $path));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function isInsideCwdProvider(): iterable
    {
        yield 'inside'           => ['/home/user/project', 'src/foo.php', true];
        yield 'equals cwd'       => ['/home/user/project', '.', true];
        yield 'outside relative' => ['/home/user/project', '../other/file', false];
        yield 'outside absolute' => ['/home/user/project', '/etc/passwd', false];
        yield 'dotdot traversal' => ['/home/user/project', '../../../etc/passwd', false];
    }

    // ── isPathInList ──

    #[DataProvider('isPathInListProvider')]
    public function testIsPathInList(array $patterns, string $path, bool $expected): void
    {
        $this->assertSame($expected, $this->matcher->isPathInList($patterns, $path));
    }

    /** @return iterable<string, array{list<string>, string, bool}> */
    public static function isPathInListProvider(): iterable
    {
        $cwd = getcwd() ?: '.';
        yield 'exact match'  => [[$cwd.'/tmp'], $cwd.'/tmp/file.txt', true];
        yield 'prefix match' => [[$cwd.'/tmp'], $cwd.'/tmp', true];
        yield 'not in list'  => [[$cwd.'/var'], $cwd.'/tmp/file.txt', false];
        yield 'empty list'   => [[], '/tmp/file.txt', false];
    }

    // ── isProtectedReadPath ──

    #[DataProvider('protectedReadProvider')]
    public function testIsProtectedReadPath(array $patterns, string $path, bool $expected): void
    {
        $policy = new SafeGuardPolicy(protectedReadPatterns: $patterns);
        $this->assertSame($expected, $this->matcher->isProtectedReadPath($policy, $path));
    }

    /** @return iterable<string, array{list<string>, string, bool}> */
    public static function protectedReadProvider(): iterable
    {
        yield '.env.local'           => [['.env.local'], '/home/user/project/.env.local', true];
        yield 'auth.json'            => [['auth.json'], '/home/user/project/auth.json', true];
        yield '.ssh/id_ (ends-with)' => [['.ssh/id_'], '/home/user/.ssh/id_rsa', true];
        yield '.aws/credentials'     => [['.aws/credentials'], '/home/user/.aws/credentials', true];
        yield '.kube/config'         => [['.kube/config'], '/home/user/.kube/config', true];
        yield '.pem'                 => [['.pem'], '/home/user/certs/server.pem', true];
        yield 'service-account'      => [['service-account'], '/home/user/service-account', true];
        yield 'case insensitive'     => [['.env.local'], '/home/user/.ENV.LOCAL', true];
        yield 'segment match .gcp/'  => [['.gcp/'], '/home/user/.gcp/credentials.json', true];
        yield 'regular file not protected' => [['.env.local'], '/home/user/project/src/main.php', false];
        yield 'tracked .env not protected' => [['.env.local', '.env.dev.local'], '/home/user/project/.env', false];
    }

    // ── Defaults ──

    public function testDefaultPatternsListIsNotEmpty(): void
    {
        $defaults = $this->matcher->defaultProtectedReadPatterns();
        $this->assertNotEmpty($defaults);
        $this->assertGreaterThan(20, \count($defaults));
    }
}
