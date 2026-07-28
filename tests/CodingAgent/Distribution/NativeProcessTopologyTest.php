<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Distribution;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Contract: a fused native Hatfield binary starts a headless controller that
 * reaches runtime.ready, relaunches Messenger children through the same
 * one-element native executable, and leaves no owned descendants after stop.
 *
 * Opt-in via HATFIELD_NATIVE_BINARY_PATH. When unset/missing, this is a real
 * PHPUnit skip so generic source suites stay honest. CI and
 * castor distribution:verify must supply the artifact and hard-fail without it.
 *
 * Topology proof is the production Castor helper (same require pattern as
 * CanonicalPharHandoffTest) — no mirrored process-inspection implementation.
 */
#[Group('phar')]
#[Group('native-artifact')]
final class NativeProcessTopologyTest extends TestCase
{
    public function testNativeBinaryControllerReachesReadyAndRelaunchesConsumers(): void
    {
        $binary = getenv('HATFIELD_NATIVE_BINARY_PATH');
        if (false === $binary || '' === trim((string) $binary) || !is_file($binary)) {
            $this->markTestSkipped(
                'HATFIELD_NATIVE_BINARY_PATH not set to a native artifact. '
                .'CI / castor distribution:verify must supply it; generic suites skip.',
            );
        }

        $binary = realpath($binary) ?: $binary;
        $this->assertTrue(is_executable($binary), 'Native artifact must be executable: '.$binary);

        $root = ProjectDir::get();
        $helpersPhp = $root.'/.castor/helpers.php';
        $distPhp = $root.'/.castor/distribution.php';
        $this->assertFileExists($helpersPhp);
        $this->assertFileExists($distPhp);

        require_once $helpersPhp;
        require_once $distPhp;
        $this->assertTrue(
            \function_exists('distribution_smoke_native_process_topology'),
            'distribution_smoke_native_process_topology must load from .castor/distribution.php',
        );

        // Hard-fails with diagnostics on ready/transport/leak failures.
        distribution_smoke_native_process_topology($binary);
    }
}
