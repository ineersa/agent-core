<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\Filesystem;

use Ineersa\AgentCore\Infrastructure\Filesystem\PathSymlinkGuard;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class PathSymlinkGuardTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = TestDirectoryIsolation::createOsTempDir('path-symlink-guard');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmp);
    }

    public function testDetectsLeafAndAncestorSymlinksUnderStopBoundary(): void
    {
        $root = $this->tmp.'/root';
        $nested = $root.'/owned/nested';
        (new Filesystem())->mkdir($nested, 0700);
        $leaf = $nested.'/file.txt';
        file_put_contents($leaf, 'ok');
        $rootReal = realpath($root);
        $this->assertNotFalse($rootReal);
        $this->assertFalse(PathSymlinkGuard::pathHasSymlinkAncestor($leaf, $rootReal));

        $foreign = $this->tmp.'/foreign';
        (new Filesystem())->mkdir($foreign, 0700);
        $foreignFile = $foreign.'/secret.txt';
        file_put_contents($foreignFile, 'secret');
        $linkLeaf = $nested.'/linked.txt';
        symlink($foreignFile, $linkLeaf);
        $this->assertTrue(PathSymlinkGuard::pathHasSymlinkAncestor($linkLeaf, $rootReal));

        $redirected = $root.'/owned-link';
        symlink($foreign, $redirected);
        $redirectedChild = $redirected.'/secret.txt';
        $this->assertTrue(PathSymlinkGuard::pathHasSymlinkAncestor($redirectedChild, $rootReal));
    }

    public function testResolveDirectChildDirectoryRefusesRedirectedOwnedName(): void
    {
        $root = $this->tmp.'/scopes';
        $foreign = $this->tmp.'/foreign-scope';
        (new Filesystem())->mkdir([$root, $foreign], 0700);
        $rootReal = realpath($root);
        $this->assertNotFalse($rootReal);

        $owned = $root.'/run-owned';
        (new Filesystem())->mkdir($owned, 0700);
        $this->assertSame(realpath($owned), PathSymlinkGuard::resolveDirectChildDirectory($rootReal, 'run-owned'));

        symlink($foreign, $root.'/run-redirected');
        $this->assertNull(PathSymlinkGuard::resolveDirectChildDirectory($rootReal, 'run-redirected'));
        $this->assertNull(PathSymlinkGuard::resolveDirectChildDirectory($rootReal, '../escape'));
        $this->assertNull(PathSymlinkGuard::resolveDirectChildDirectory($rootReal, 'missing'));
    }

    public function testResolveContainedRegularFileRefusesAncestorRedirection(): void
    {
        $scope = $this->tmp.'/scope';
        $foreign = $this->tmp.'/foreign';
        (new Filesystem())->mkdir([$scope, $foreign], 0700);
        $ownedFile = $scope.'/owned.txt';
        file_put_contents($ownedFile, 'owned');
        $foreignFile = $foreign.'/foreign.txt';
        file_put_contents($foreignFile, 'foreign');
        $scopeReal = realpath($scope);
        $this->assertNotFalse($scopeReal);
        $this->assertSame(realpath($ownedFile), PathSymlinkGuard::resolveContainedRegularFile($scopeReal, $ownedFile));

        $leafLink = $scope.'/leaf-link.txt';
        symlink($foreignFile, $leafLink);
        $this->assertNull(PathSymlinkGuard::resolveContainedRegularFile($scopeReal, $leafLink));

        $nested = $scope.'/nested';
        (new Filesystem())->mkdir($nested, 0700);
        rmdir($nested);
        symlink($foreign, $nested);
        $redirectedFile = $nested.'/foreign.txt';
        $this->assertNull(PathSymlinkGuard::resolveContainedRegularFile($scopeReal, $redirectedFile));
        $this->assertNull(PathSymlinkGuard::resolveContainedRegularFile($scopeReal, $foreignFile));
    }
}
