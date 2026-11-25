<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class StaticRuntimeCacheTest extends TestCase
{
    public function testCompiledRuntimeRequiresVerifiedArtifacts(): void
    {
        require_once ProjectDir::get().'/.castor/distribution.php';
        $work = TestDirectoryIsolation::createOsTempDir('static-runtime');
        $filesystem = new Filesystem();
        try {
            $cache = $work.'/cache';
            $compiled = $work.'/compiled';
            $filesystem->dumpFile($compiled.'/php', "#!/bin/sh\nexit 0\n");
            $filesystem->chmod($compiled.'/php', 0755);
            $filesystem->dumpFile($compiled.'/micro.sfx', 'compiled micro');
            $fingerprint = str_repeat('a', 64);
            $inventory = $work.'/downloads/.cache.json';
            $filesystem->dumpFile($inventory, json_encode(['curl' => ['source' => [
                'cache_type' => 'archive', 'filename' => '/private/downloads/curl.tar.gz', 'version' => '1.0',
                'hash' => str_repeat('b', 40), 'config' => ['private' => '/not-for-publication'],
            ]]], \JSON_THROW_ON_ERROR));
            $provenance = distribution_runtime_provenance($inventory, 'linux-amd64', $fingerprint, 1);
            $this->assertSame(['type' => 'archive', 'filename' => 'curl.tar.gz', 'version' => '1.0', 'hash_algorithm' => 'sha1', 'hash' => str_repeat('b', 40)], $provenance['downloads']['curl']['source']);
            $this->assertFalse(distribution_cached_runtime_is_valid($cache, $fingerprint));
            distribution_cache_runtime($cache, $compiled, $provenance);
            $this->assertTrue(distribution_cached_runtime_is_valid($cache, $fingerprint));
            $this->assertFalse(distribution_cached_runtime_is_valid($cache, str_repeat('c', 64)));
            $filesystem->remove($cache.'/provenance.json');
            $this->assertFalse(distribution_cached_runtime_is_valid($cache, $fingerprint));
            distribution_cache_runtime($cache, $compiled, $provenance);
            $filesystem->dumpFile($cache.'/micro.sfx', 'changed micro');
            $this->assertFalse(distribution_cached_runtime_is_valid($cache, $fingerprint));
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    public function testAppVersionDoesNotInvalidateRuntimeButBuildFlagsDo(): void
    {
        require_once ProjectDir::get().'/.castor/distribution.php';
        $target = distribution_host_target();
        $pin = distribution_static_pin();
        $original = distribution_runtime_fingerprint($target, $pin);
        $version = getenv('HATFIELD_BUILD_VERSION');
        try {
            putenv('HATFIELD_BUILD_VERSION=app-only-change');
            $this->assertSame($original, distribution_runtime_fingerprint($target, $pin));
            $this->assertNotSame($original, distribution_runtime_fingerprint($target, ['micro_fake_cli' => !$pin['micro_fake_cli']] + $pin));
            $this->assertNotSame($original, distribution_runtime_fingerprint($target, ['runtime_revision' => $pin['runtime_revision'] + 1] + $pin));
        } finally {
            putenv(false === $version ? 'HATFIELD_BUILD_VERSION' : 'HATFIELD_BUILD_VERSION='.$version);
        }
    }
}
