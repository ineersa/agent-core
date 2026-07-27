<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Distribution;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use PHPUnit\Framework\TestCase;

/**
 * Regression: distribution:build-static must prefer an existing non-empty
 * dist/hatfield.phar handoff and must not rebuild/overwrite it.
 *
 * Source-level contract proof (no host static toolchain required).
 */
final class CanonicalPharHandoffTest extends TestCase
{
    public function testResolverHelperPrefersExistingDistPharAndDoesNotCallPharEnsureWhenPresent(): void
    {
        $root = ProjectDir::get();
        $source = file_get_contents($root.'/.castor/distribution.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString('function distribution_resolve_canonical_phar_for_static', $source);
        $this->assertStringContainsString("source' => 'handoff'", $source);
        $this->assertStringContainsString("source' => 'built'", $source);
        $this->assertStringContainsString('Using existing dist PHAR handoff (no rebuild)', $source);
        // build-static must call resolver, not unconditional phar_ensure overwrite path.
        $this->assertMatchesRegularExpression(
            '/function distribution_build_static[\s\S]*distribution_resolve_canonical_phar_for_static\(/',
            $source,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function distribution_build_static[\s\S]*remove_path_checked\(\$pharDest\)[\s\S]*copy_file_checked\(\$pharPath, \$pharDest\)/',
            $source,
        );
    }

    public function testPinRequiresExactPhpPatchPhpmicroAndSourceSha(): void
    {
        $root = ProjectDir::get();
        $pinPath = $root.'/tools/static/pin.json';
        $this->assertFileExists($pinPath);
        $pin = json_decode((string) file_get_contents($pinPath), true);
        $this->assertIsArray($pin);
        $this->assertSame('8.5.8', $pin['php_version'] ?? null);
        $this->assertSame(
            '58910198d19e873048fe87cdfe16bc790025417ede3d1651bfa1c4b533d573f2',
            $pin['php_source_sha256'] ?? null,
        );
        $this->assertSame('https://github.com/static-php/phpmicro.git', $pin['phpmicro_repository'] ?? null);
        $this->assertSame('fb6d497b6f4cf138ee3851a30c905d64b7b19aed', $pin['phpmicro_commit'] ?? null);

        $source = (string) file_get_contents($root.'/.castor/distribution.php');
        $this->assertStringContainsString('--custom-local=', $source);
        $this->assertStringContainsString('distribution_assert_php_source_sha256', $source);
        $this->assertStringContainsString('distribution_ensure_phpmicro_checkout', $source);
        $this->assertStringContainsString('php_version must be an exact patch version', $source);
    }

    public function testSmokeAcceptsExpectedVersionAndCommitArgs(): void
    {
        $root = ProjectDir::get();
        $source = (string) file_get_contents($root.'/.castor/distribution.php');
        $this->assertMatchesRegularExpression(
            '/function distribution_smoke_artifact\(\s*string \$artifactPath,\s*bool \$isPhar = false,\s*\?string \$expectedVersion = null,\s*\?string \$expectedCommit = null,/s',
            $source,
        );
        $this->assertStringContainsString('missing expected version', $source);
        $this->assertStringContainsString('missing expected commit', $source);
    }
}
