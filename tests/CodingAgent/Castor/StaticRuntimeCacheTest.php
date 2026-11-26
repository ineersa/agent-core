<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class StaticRuntimeCacheTest extends TestCase
{
    public function testRuntimeCliWithoutZstdIsRejected(): void
    {
        require_once ProjectDir::get().'/.castor/distribution.php';
        $probe = new Process([\PHP_BINARY, '-n', '-r', 'echo extension_loaded("zstd") ? "yes" : "no";']);
        if ('yes' === $probe->mustRun()->getOutput()) {
            $this->markTestSkipped('The test PHP has built-in zstd even without INI files.');
        }
        $this->assertSame('no', $probe->getOutput());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("output:\nStatic PHP requires zstd");
        distribution_verify_runtime_cli(\PHP_BINARY);
    }

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
            $inputs = ['target' => 'linux-amd64', 'runtime_revision' => 1];
            $fingerprint = distribution_runtime_fingerprint_from_inputs($inputs);
            $inventory = $work.'/downloads/.cache.json';
            $filesystem->dumpFile($inventory, json_encode(['curl' => ['source' => [
                'cache_type' => 'archive', 'filename' => '/private/downloads/curl.tar.gz', 'version' => '1.0',
                'hash' => str_repeat('b', 40), 'config' => ['private' => '/not-for-publication'],
            ]]], \JSON_THROW_ON_ERROR));
            $provenance = distribution_runtime_provenance($inventory, $inputs);
            $this->assertSame($inputs, $provenance['fingerprint_inputs'], 'Provenance names the inputs behind its key.');
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
            distribution_cache_runtime($cache, $compiled, $provenance);
            $filesystem->dumpFile($cache.'/php', "#!/bin/sh\nexit 1\n");
            $filesystem->chmod($cache.'/php', 0755);
            $manifest = json_decode((string) file_get_contents($cache.'/sha256.json'), true, flags: \JSON_THROW_ON_ERROR);
            $manifest['php'] = hash_file('sha256', $cache.'/php');
            $filesystem->dumpFile($cache.'/sha256.json', json_encode($manifest, \JSON_THROW_ON_ERROR));
            $this->expectOutputRegex('/Cached native runtime failed CLI verification/');
            $this->assertFalse(distribution_cached_runtime_is_valid($cache, $fingerprint));
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    public function testOnlyRuntimeInputsChangeTheKey(): void
    {
        require_once ProjectDir::get().'/.castor/distribution.php';
        try {
            $target = distribution_host_target();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }
        if (!isset(distribution_targets()[$target])) {
            $this->markTestSkipped('No native artifact target for this host.');
        }
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

        // Runner labels and search paths vary across VMs with identical toolchains.
        foreach (['ImageVersion', 'ImageOS', 'PKG_CONFIG_PATH', 'CMAKE_PREFIX_PATH', 'LIBRARY_PATH'] as $label) {
            $originalValue = getenv($label);
            try {
                putenv($label.'='.$originalValue.'-fingerprint-test');
                $this->assertSame($original, distribution_runtime_fingerprint($target, $pin), $label);
            } finally {
                putenv(false === $originalValue ? $label : $label.'='.$originalValue);
            }
        }

        foreach (['CFLAGS', 'SPC_TARGET', 'SPC_TOOLCHAIN', 'SPC_USE_LLVM', 'SPC_MUSL_DYNAMIC'] as $selector) {
            $originalValue = getenv($selector);
            try {
                putenv($selector.'='.$originalValue.'-fingerprint-test');
                $this->assertNotSame($original, distribution_runtime_fingerprint($target, $pin), $selector);
            } finally {
                putenv(false === $originalValue ? $selector : $selector.'='.$originalValue);
            }
        }
    }
}
