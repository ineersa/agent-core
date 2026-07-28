<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Distribution;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Behavioral contract: distribution_resolve_canonical_phar_for_static prefers an
 * existing non-empty dist/hatfield.phar handoff (smoke + identity) and does not
 * rebuild/overwrite it. Pin structure is checked against committed tools/static/pin.json.
 */
final class CanonicalPharHandoffTest extends TestCase
{
    public function testResolverPrefersExistingDistPharHandoffWithoutRebuild(): void
    {
        $root = ProjectDir::get();
        $distPhp = $root.'/.castor/distribution.php';
        $helpersPhp = $root.'/.castor/helpers.php';
        $this->assertFileExists($distPhp);
        $this->assertFileExists($helpersPhp);

        require_once $helpersPhp;
        require_once $distPhp;
        $this->assertTrue(
            \function_exists('distribution_resolve_canonical_phar_for_static'),
            'distribution_resolve_canonical_phar_for_static must load from .castor/distribution.php',
        );

        $work = TestDirectoryIsolation::createProjectTempDir('canonical-phar-handoff');
        $dist = $work.'/dist';
        mkdir($dist, 0755, true);
        $pharPath = $dist.'/hatfield.phar';

        // Fake >1KB "PHAR" that answers --version / list like the real product.
        $version = '9.9.9-test';
        $commit = 'deadbeefcafebabe';
        $payload = <<<PHP
#!/usr/bin/env php
<?php
declare(strict_types=1);
\$args = array_slice(\$argv, 1);
if (\$args === ['--version'] || (\$args[0] ?? '') === '--version') {
    echo "Hatfield {$version} (commit {$commit})\\n";
    exit(0);
}
if ((\$args[0] ?? '') === 'list') {
    echo "agent\\nabout\\nlist\\n";
    exit(0);
}
fwrite(STDERR, "unexpected argv: " . implode(' ', \$args) . "\\n");
exit(2);
PHP;
        // Pad past the 1KB smoke size gate without changing behavior.
        $payload .= "\n".str_repeat("// pad-to-exceed-1kb-smoke-gate\n", 40);
        $this->assertGreaterThan(1024, \strlen($payload));
        file_put_contents($pharPath, $payload);
        chmod($pharPath, 0755);
        $hashBefore = hash_file('sha256', $pharPath);
        $this->assertNotFalse($hashBefore);

        try {
            $resolved = distribution_resolve_canonical_phar_for_static($dist, $version, $commit);
            $this->assertSame('handoff', $resolved['source']);
            $this->assertSame($pharPath, $resolved['path']);
            $this->assertFileExists($pharPath);
            $hashAfter = hash_file('sha256', $pharPath);
            $this->assertSame($hashBefore, $hashAfter, 'handoff PHAR must not be rebuilt/overwritten');

            // Mismatch fail-closed: wrong expected version must not rewrite handoff.
            try {
                distribution_resolve_canonical_phar_for_static($dist, '0.0.0-mismatch', $commit);
                $this->fail('Expected RuntimeException on version mismatch');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('missing expected version', $e->getMessage());
            }
            $this->assertSame($hashBefore, hash_file('sha256', $pharPath));
        } finally {
            TestDirectoryIsolation::removeDirectory($work);
        }
    }

    public function testPinRequiresExactPhpPatchPhpmicroAndSourceSha(): void
    {
        $root = ProjectDir::get();
        $pinPath = $root.'/tools/static/pin.json';
        $this->assertFileExists($pinPath);
        $pin = json_decode((string) file_get_contents($pinPath), true);
        $this->assertIsArray($pin);

        // Exact committed pins (release reproducibility contract).
        $this->assertSame('8.5.8', $pin['php_version'] ?? null);
        $this->assertSame(
            '58910198d19e873048fe87cdfe16bc790025417ede3d1651bfa1c4b533d573f2',
            $pin['php_source_sha256'] ?? null,
        );
        $this->assertSame('https://github.com/static-php/phpmicro.git', $pin['phpmicro_repository'] ?? null);
        $this->assertSame('fb6d497b6f4cf138ee3851a30c905d64b7b19aed', $pin['phpmicro_commit'] ?? null);
        $this->assertSame('tools/static/phpmicro-linux-self-path.patch', $pin['phpmicro_patch'] ?? null);
        $this->assertSame(
            '4b9b19379f76fe37a7e91ed202655b5d8b4464604e800de3bc9ea56006e70cc8',
            $pin['phpmicro_patch_sha256'] ?? null,
        );
        $patchPath = $root.'/'.(string) $pin['phpmicro_patch'];
        $this->assertFileExists($patchPath);
        $this->assertSame(
            $pin['phpmicro_patch_sha256'],
            hash_file('sha256', $patchPath),
            'tracked phpmicro patch bytes must match pin SHA-256',
        );
        $patchBody = (string) file_get_contents($patchPath);
        $this->assertStringContainsString('realpath("/proc/self/exe"', $patchBody);
        $this->assertStringContainsString('getauxval(AT_EXECFN)', $patchBody);

        // Invariant/format checks beyond exact literals.
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $pin['php_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $pin['php_source_sha256']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', (string) $pin['phpmicro_commit']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $pin['phpmicro_patch_sha256']);
        $this->assertIsArray($pin['extensions'] ?? null);
        $this->assertNotEmpty($pin['extensions']);
        $this->assertContains('phar', $pin['extensions']);
        $this->assertContains('pcntl', $pin['extensions']);
        $this->assertTrue((bool) ($pin['micro_fake_cli'] ?? false));
        $this->assertSame(['cli', 'micro'], $pin['sapi'] ?? null);
        $this->assertNotEmpty($pin['static_php_cli_commit'] ?? null);
        $this->assertNotEmpty($pin['static_php_cli_repository'] ?? null);
    }
}
