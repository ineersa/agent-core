<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard\Classifier;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier\SafeGuardPathMatcher;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
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

    public function testPathInsideCwd(): void
    {
        $this->assertTrue(
            $this->matcher->isInsideCwd('/home/user/project', 'src/foo.php'),
        );
    }

    public function testPathEqualsCwd(): void
    {
        $this->assertTrue(
            $this->matcher->isInsideCwd('/home/user/project', '.'),
        );
    }

    public function testPathOutsideCwd(): void
    {
        $this->assertFalse(
            $this->matcher->isInsideCwd('/home/user/project', '../other/file'),
        );
    }

    public function testAbsolutePathOutsideCwd(): void
    {
        $this->assertFalse(
            $this->matcher->isInsideCwd('/home/user/project', '/etc/passwd'),
        );
    }

    public function testDotDotTraversalOutsideCwd(): void
    {
        $this->assertFalse(
            $this->matcher->isInsideCwd('/home/user/project', '../../../etc/passwd'),
        );
    }

    // ── isPathInList ──

    public function testPathInListExactMatch(): void
    {
        $cwd = getcwd() ?: '.';

        $this->assertTrue(
            $this->matcher->isPathInList([$cwd.'/tmp'], $cwd.'/tmp/file.txt'),
        );
    }

    public function testPathInListPrefixMatch(): void
    {
        $cwd = getcwd() ?: '.';

        $this->assertTrue(
            $this->matcher->isPathInList([$cwd.'/tmp'], $cwd.'/tmp'),
        );
    }

    public function testPathNotInList(): void
    {
        $cwd = getcwd() ?: '.';

        $this->assertFalse(
            $this->matcher->isPathInList([$cwd.'/var'], $cwd.'/tmp/file.txt'),
        );
    }

    public function testEmptyListReturnsFalse(): void
    {
        $this->assertFalse(
            $this->matcher->isPathInList([], '/tmp/file.txt'),
        );
    }

    // ── isProtectedReadPath ──

    public function testEnvLocalIsProtected(): void
    {
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['.env.local'],
        );

        $this->assertTrue(
            $this->matcher->isProtectedReadPath($policy, '/home/user/project/.env.local'),
        );
    }

    public function testAuthJsonIsProtected(): void
    {
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['auth.json'],
        );

        $this->assertTrue(
            $this->matcher->isProtectedReadPath($policy, '/home/user/project/auth.json'),
        );
    }

    public function testSshKeyIsProtected(): void
    {
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['.ssh/id_'],
        );

        // .ssh/id_ matches .ssh/id_rsa (ends-with)
        $this->assertTrue(
            $this->matcher->isProtectedReadPath($policy, '/home/user/.ssh/id_rsa'),
        );
    }

    public function testSshKeyIsProtectedByExactBasename(): void
    {
        // With defaults: .ssh/id_ is in the list
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['.ssh/id_'],
        );

        $this->assertTrue(
            $this->matcher->isProtectedReadPath($policy, '/home/user/.ssh/id_rsa'),
        );
    }

    public function testAwsCredentialsIsProtected(): void
    {
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['.aws/credentials'],
        );

        $this->assertTrue(
            $this->matcher->isProtectedReadPath($policy, '/home/user/.aws/credentials'),
        );
    }

    public function testKubeConfigIsProtected(): void
    {
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['.kube/config'],
        );

        $this->assertTrue(
            $this->matcher->isProtectedReadPath($policy, '/home/user/.kube/config'),
        );
    }

    public function testPemFileIsProtected(): void
    {
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['.pem'],
        );

        $this->assertTrue(
            $this->matcher->isProtectedReadPath($policy, '/home/user/certs/server.pem'),
        );
    }

    public function testServiceAccountIsProtected(): void
    {
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['service-account'],
        );

        $this->assertTrue(
            $this->matcher->isProtectedReadPath($policy, '/home/user/service-account'),
        );
    }

    public function testProtectedReadIsCaseInsensitive(): void
    {
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['.env.local'],
        );

        $this->assertTrue(
            $this->matcher->isProtectedReadPath($policy, '/home/user/.ENV.LOCAL'),
        );
    }

    public function testRegularFileIsNotProtected(): void
    {
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['.env.local'],
        );

        $this->assertFalse(
            $this->matcher->isProtectedReadPath($policy, '/home/user/project/src/main.php'),
        );

        $this->assertFalse(
            $this->matcher->isProtectedReadPath($policy, '/home/user/project/README.md'),
        );
    }

    public function testTrackedEnvFileIsNotProtected(): void
    {
        // .env (tracked) is NOT protected; only .env.local and variants are
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['.env.local', '.env.dev.local'],
        );

        $this->assertFalse(
            $this->matcher->isProtectedReadPath($policy, '/home/user/project/.env'),
        );
    }

    public function testPathContainingPatternAsSegment(): void
    {
        $policy = new SafeGuardPolicy(
            protectedReadPatterns: ['.gcp/'],
        );

        $this->assertTrue(
            $this->matcher->isProtectedReadPath($policy, '/home/user/.gcp/credentials.json'),
        );
    }

    public function testDefaultPatternsListIsNotEmpty(): void
    {
        $defaults = $this->matcher->defaultProtectedReadPatterns();

        $this->assertNotEmpty($defaults);
        $this->assertGreaterThan(20, \count($defaults));
    }
}
