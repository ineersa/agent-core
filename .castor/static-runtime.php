<?php

declare(strict_types=1);

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * @param array{php_version: string, extensions: list<string>, micro_fake_cli: bool} $pin
 *
 * @return array{download: list<string>, build: list<string>}
 */
function distribution_runtime_recipe(array $pin, string $phpmicroPath): array
{
    $extensions = implode(',', $pin['extensions']);
    $customLocal = 'php-micro:'.$phpmicroPath;

    return [
        'download' => ['download', '--for-extensions='.$extensions, '--with-php='.$pin['php_version'], '--custom-local='.$customLocal, '--prefer-binary'],
        'build' => [
            'build', $extensions, '--build-cli', '--build-micro',
            // GD needs JPEG/WebP; nghttp2 enables curl HTTP/2.
            '--with-packages=libjpeg,libwebp,nghttp2',
            '--dl-with-php='.$pin['php_version'], '--dl-custom-local='.$customLocal,
            ...($pin['micro_fake_cli'] ? ['--with-micro-fake-cli'] : []),
            // The pinned bare micro smoke segfaults; retain CLI and fused artifact checks.
            '--no-smoke-test=micro',
        ],
    ];
}

#[AsTask(name: 'distribution:runtime-fingerprint', description: 'Print the host-native PHP runtime cache key')]
function distribution_print_runtime_fingerprint(
    #[AsOption(description: 'Native target (default host)')]
    ?string $target = null,
): void {
    echo distribution_runtime_fingerprint($target ?? distribution_host_target(), distribution_static_pin())."\n";
}

/** @param array{php_version: string, extensions: list<string>, micro_fake_cli: bool, phpmicro_patch: string, phpmicro_patch_sha256: string, runtime_revision: int} $pin */
function distribution_runtime_fingerprint(string $target, array $pin): string
{
    distribution_assert_host_can_build($target);
    $patch = hash_file('sha256', distribution_root().'/'.$pin['phpmicro_patch']);
    if ($patch !== $pin['phpmicro_patch_sha256']) {
        throw new RuntimeException('phpmicro patch SHA-256 mismatch: '.$pin['phpmicro_patch']);
    }

    $tools = [];
    $finder = new ExecutableFinder();
    foreach (['cc', 'gcc', 'g++', 'clang', 'clang++', 'ld', 'ldd', 'ar', 'ranlib', 'strip', 'make', 'cmake', 'pkg-config', 'autoconf', 'automake', 'libtool', 're2c', 'flex', 'gperf', 'bison'] as $tool) {
        $path = $finder->find($tool);
        if (null === $path) {
            continue;
        }
        $digest = hash_file('sha256', $path);
        if (false === $digest) {
            throw new RuntimeException('Unable to fingerprint native build tool: '.$path);
        }
        // Apple ld/ar and other valid tools need not support --version.
        $tools[$tool] = $digest;
        if (in_array($tool, ['cc', 'gcc', 'g++', 'clang', 'clang++'], true)) {
            $tools[$tool] = [$digest, trim((new Process([$path, '--version']))->mustRun()->getOutput())];
        }
    }
    if (str_starts_with($target, 'darwin-')) {
        // xcrun works with Command Line Tools alone; xcodebuild need not be installed.
        $platform = array_map(static fn (array $probe): string => trim((new Process($probe))->mustRun()->getOutput()), [
            ['xcrun', 'clang', '--version'], ['xcrun', '--show-sdk-version'], ['xcrun', '--show-sdk-build-version'], ['sw_vers'],
        ]);
    } else {
        $osRelease = is_file('/etc/os-release') ? '/etc/os-release' : '/usr/lib/os-release';
        $platform = file_get_contents($osRelease);
        if (false === $platform) {
            throw new RuntimeException('Unable to fingerprint native host OS: '.$osRelease);
        }
    }
    $env = [];
    foreach (['CC', 'CXX', 'AR', 'LD', 'RANLIB', 'CFLAGS', 'CXXFLAGS', 'CPPFLAGS', 'LDFLAGS', 'SDKROOT', 'DEVELOPER_DIR', 'MACOSX_DEPLOYMENT_TARGET', 'CMAKE_PREFIX_PATH', 'PKG_CONFIG_PATH', 'PKG_CONFIG_LIBDIR', 'CPATH', 'LIBRARY_PATH', 'ImageOS', 'ImageVersion', 'SPC_LIBC', 'SPC_TARGET', 'SPC_TOOLCHAIN', 'SPC_USE_LLVM', 'SPC_MUSL_DYNAMIC'] as $variable) {
        $env[$variable] = getenv($variable);
    }

    return hash('sha256', serialize([
        // Only runtime inputs belong here. In particular, neither the app PHAR
        // nor its dependency lockfile contributes to this key.
        'native-runtime-v1', $target, $pin['runtime_revision'], hash_file('sha256', distribution_root().'/tools/static/pin.json'), $patch,
        hash_file('sha256', distribution_root().'/.github/actions/static-prerequisites/action.yml'),
        distribution_runtime_recipe($pin, '<phpmicro>'), $tools, $platform, $env,
    ]));
}

function distribution_verify_runtime_cli(string $cli): void
{
    CastorTasks\run_checked(escapeshellarg($cli).' -n -r '.escapeshellarg(
        'if (!extension_loaded("zstd")) { fwrite(STDERR, "Static PHP requires zstd\n"); exit(1); }'
        .'if (!(curl_version()["features"] & CURL_VERSION_HTTP2)) { fwrite(STDERR, "Static curl requires HTTP/2 support\n"); exit(1); }'
    ));
}

/** @return array<string, mixed> */
function distribution_runtime_provenance(string $spcCache, string $target, string $fingerprint, int $revision): array
{
    if (!is_file($spcCache)) {
        throw new RuntimeException('SPC download inventory is missing: '.$spcCache);
    }
    $cache = json_decode((string) file_get_contents($spcCache), true, flags: \JSON_THROW_ON_ERROR);
    if (!is_array($cache) || [] === $cache) {
        throw new RuntimeException('SPC download inventory is empty or invalid.');
    }
    $downloads = [];
    foreach ($cache as $artifact => $entry) {
        $rows = ['source' => $entry['source'] ?? null];
        foreach ($entry['binary'] ?? [] as $platform => $row) {
            $rows['binary:'.$platform] = $row;
        }
        foreach ($rows as $kind => $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = $row['cache_type'] ?? null;
            $filename = $row['filename'] ?? null;
            $version = $row['version'] ?? null;
            $hash = $row['hash'] ?? null;
            $downloads[$artifact][$kind] = [
                'type' => $type,
                'filename' => is_string($filename) ? basename($filename) : null,
                'version' => is_string($version) && 1 === preg_match('/\\A[\\w.+-]+\\z/', $version) ? $version : null,
                'hash_algorithm' => match ($type) {
                    'archive', 'file' => 'sha1', 'git' => 'git-commit', default => null,
                },
                'hash' => is_string($hash) && 1 === preg_match('/\\A[0-9a-f]{40,64}\\z/i', $hash) ? $hash : null,
            ];
        }
    }
    if ([] === $downloads) {
        throw new RuntimeException('SPC inventory contains no recorded downloads.');
    }

    return ['kind' => 'spc-download-inventory', 'target' => $target, 'fingerprint' => $fingerprint, 'runtime_revision' => $revision, 'downloads' => $downloads];
}

function distribution_cached_runtime_is_valid(string $cache, string $fingerprint): bool
{
    $manifest = $cache.'/sha256.json';
    if (!is_file($manifest)) {
        return false;
    }
    $expected = json_decode((string) file_get_contents($manifest), true);
    foreach (['php', 'micro.sfx', 'provenance.json'] as $name) {
        $file = $cache.'/'.$name;
        if (is_link($file) || !is_file($file) || 0 === filesize($file) || hash_file('sha256', $file) !== ($expected[$name] ?? null)) {
            return false;
        }
    }
    $provenance = json_decode((string) file_get_contents($cache.'/provenance.json'), true);
    $downloads = $provenance['downloads'] ?? null;
    if ('spc-download-inventory' !== ($provenance['kind'] ?? null) || $fingerprint !== ($provenance['fingerprint'] ?? null) || !is_array($downloads) || [] === $downloads) {
        return false;
    }
    try {
        distribution_verify_runtime_cli($cache.'/php');
    } catch (RuntimeException $e) {
        echo "Cached native runtime failed CLI verification; rebuilding: {$e->getMessage()}\n";

        return false;
    }

    return true;
}

/** @param array<string, mixed> $provenance */
function distribution_cache_runtime(string $cache, string $compiled, array $provenance): void
{
    distribution_verify_runtime_cli($compiled.'/php');
    if (!is_file($compiled.'/micro.sfx') || 0 === filesize($compiled.'/micro.sfx')) {
        throw new RuntimeException('SPC did not produce a nonempty micro.sfx: '.$compiled);
    }

    $filesystem = new Filesystem();
    $filesystem->mkdir($cache);
    // Publish the manifest last: a partial write cannot produce a valid hit.
    $filesystem->remove([$cache.'/sha256.json', $cache.'/php', $cache.'/micro.sfx', $cache.'/provenance.json']);
    $manifest = [];
    foreach (['php', 'micro.sfx'] as $name) {
        $filesystem->copy($compiled.'/'.$name, $cache.'/'.$name, true);
        $manifest[$name] = hash_file('sha256', $cache.'/'.$name);
    }
    $filesystem->dumpFile($cache.'/provenance.json', json_encode($provenance, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
    $manifest['provenance.json'] = hash_file('sha256', $cache.'/provenance.json');
    $filesystem->dumpFile($cache.'/sha256.json', json_encode($manifest, \JSON_THROW_ON_ERROR));
}
