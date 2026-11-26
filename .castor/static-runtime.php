<?php

declare(strict_types=1);

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Symfony\Component\Filesystem\Filesystem;
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
    $inputs = distribution_runtime_fingerprint_inputs($target ?? distribution_host_target(), distribution_static_pin());
    // Inputs go to stderr so a cache miss names what changed; stdout stays the bare key.
    fwrite(\STDERR, json_encode($inputs, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n");
    echo distribution_runtime_fingerprint_from_inputs($inputs)."\n";
}

/** @param array{php_version: string, extensions: list<string>, micro_fake_cli: bool, phpmicro_patch: string, phpmicro_patch_sha256: string, runtime_revision: int} $pin */
function distribution_runtime_fingerprint(string $target, array $pin): string
{
    return distribution_runtime_fingerprint_from_inputs(distribution_runtime_fingerprint_inputs($target, $pin));
}

/** @param array<string, mixed> $inputs */
function distribution_runtime_fingerprint_from_inputs(array $inputs): string
{
    return hash('sha256', serialize($inputs));
}

/**
 * Inputs that determine the compiled runtime, readable enough to diff two misses.
 *
 * Deliberately excluded: tool binary digests, full OS release files and runner
 * image labels. Those vary across runner VMs with identical toolchains and used
 * to force needless rebuilds. Bump runtime_revision after a toolchain change the
 * compiler identity below does not capture.
 *
 * @param array{php_version: string, extensions: list<string>, micro_fake_cli: bool, phpmicro_patch: string, phpmicro_patch_sha256: string, runtime_revision: int} $pin
 *
 * @return array<string, mixed>
 */
function distribution_runtime_fingerprint_inputs(string $target, array $pin): array
{
    distribution_assert_host_can_build($target);
    $patch = hash_file('sha256', distribution_root().'/'.$pin['phpmicro_patch']);
    if ($patch !== $pin['phpmicro_patch_sha256']) {
        throw new RuntimeException('phpmicro patch SHA-256 mismatch: '.$pin['phpmicro_patch']);
    }
    $probe = static fn (string ...$command): string => trim((new Process($command))->mustRun()->getOutput());

    if (str_starts_with($target, 'darwin-')) {
        // xcrun works with Command Line Tools alone; xcodebuild need not be installed.
        $os = ['macos' => explode('.', $probe('sw_vers', '-productVersion'))[0], 'sdk' => $probe('xcrun', '--show-sdk-version')];
        $compiler = $probe('xcrun', 'clang', '--version');
    } else {
        $release = parse_ini_file(is_file('/etc/os-release') ? '/etc/os-release' : '/usr/lib/os-release');
        if (false === $release || !isset($release['ID'], $release['VERSION_ID'])) {
            throw new RuntimeException('Unable to identify the native host OS.');
        }
        $os = ['id' => $release['ID'], 'version_id' => $release['VERSION_ID']];
        $compiler = $probe('cc', '--version');
    }
    // Only explicit build selectors; search paths and runner labels are excluded.
    $env = [];
    foreach (['CC', 'CXX', 'CFLAGS', 'CXXFLAGS', 'CPPFLAGS', 'LDFLAGS', 'MACOSX_DEPLOYMENT_TARGET', 'SPC_LIBC', 'SPC_TARGET', 'SPC_TOOLCHAIN', 'SPC_USE_LLVM', 'SPC_MUSL_DYNAMIC'] as $variable) {
        $env[$variable] = getenv($variable);
    }

    // Only runtime inputs belong here. In particular, neither the app PHAR
    // nor its dependency lockfile contributes to this key.
    return [
        'schema' => 'native-runtime-v2',
        'target' => $target,
        'runtime_revision' => $pin['runtime_revision'],
        'pin_sha256' => hash_file('sha256', distribution_root().'/tools/static/pin.json'),
        'phpmicro_patch_sha256' => $patch,
        'prerequisites_sha256' => hash_file('sha256', distribution_root().'/.github/actions/static-prerequisites/action.yml'),
        'recipe' => distribution_runtime_recipe($pin, '<phpmicro>'),
        'os' => $os,
        // First line names the compiler and its version, e.g. "cc (Ubuntu 15.2.0-4ubuntu4) 15.2.0".
        'compiler' => strtok($compiler, "\n"),
        'env' => $env,
    ];
}

function distribution_verify_runtime_cli(string $cli): void
{
    CastorTasks\run_checked(escapeshellarg($cli).' -n -r '.escapeshellarg(
        'if (!extension_loaded("zstd")) { fwrite(STDERR, "Static PHP requires zstd\n"); exit(1); }'
        .'if (!(curl_version()["features"] & CURL_VERSION_HTTP2)) { fwrite(STDERR, "Static curl requires HTTP/2 support\n"); exit(1); }'
    ));
}

/**
 * @param array<string, mixed> $fingerprintInputs
 *
 * @return array<string, mixed>
 */
function distribution_runtime_provenance(string $spcCache, array $fingerprintInputs): array
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

    return [
        'kind' => 'spc-download-inventory', 'target' => $fingerprintInputs['target'], 'fingerprint' => distribution_runtime_fingerprint_from_inputs($fingerprintInputs),
        'runtime_revision' => $fingerprintInputs['runtime_revision'], 'fingerprint_inputs' => $fingerprintInputs, 'downloads' => $downloads,
    ];
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
