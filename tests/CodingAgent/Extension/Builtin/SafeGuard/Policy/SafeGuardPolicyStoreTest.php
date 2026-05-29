<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard\Policy;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicyStore;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SafeGuardPolicyStore — policy loading, merging, and fallback.
 */
final class SafeGuardPolicyStoreTest extends TestCase
{
    private SafeGuardPolicyStore $store;

    protected function setUp(): void
    {
        $this->store = new SafeGuardPolicyStore();
    }

    // ── Defaults ──

    public function testDefaultPolicyHasAllFiveFields(): void
    {
        $policy = $this->store->defaultPolicy();

        $this->assertSame([], $policy->allowCommandPatterns);
        $this->assertSame([], $policy->allowWriteOutsideCwd);
        $this->assertSame([], $policy->allowDestructiveInPaths);
        $this->assertNotEmpty($policy->protectedReadPatterns);
        $this->assertSame([], $policy->dangerousCommandPatterns);
    }

    public function testDefaultPolicyIncludesAllPiProtectedReadPatterns(): void
    {
        $policy = $this->store->defaultPolicy();

        $this->assertContains('.env.local', $policy->protectedReadPatterns);
        $this->assertContains('auth.json', $policy->protectedReadPatterns);
        $this->assertContains('.ssh/id_', $policy->protectedReadPatterns);
        $this->assertContains('.aws/credentials', $policy->protectedReadPatterns);
        $this->assertContains('.kube/config', $policy->protectedReadPatterns);
        $this->assertContains('.pem', $policy->protectedReadPatterns);
        $this->assertContains('service-account', $policy->protectedReadPatterns);
    }

    // ── Policy file paths ──

    public function testPolicyFilePathUsesProjectCwd(): void
    {
        $path = $this->store->policyFilePath('/home/user/project');

        $this->assertSame('/home/user/project/.hatfield/safe-guard.json', $path);
    }

    public function testGlobalPolicyFilePathUsesHome(): void
    {
        $path = $this->store->globalPolicyFilePath();

        // Just verify it contains the expected suffix — HOME varies by environment
        $this->assertStringEndsWith('/.hatfield/safe-guard.json', $path);
    }

    // ── Loading from files ──

    public function testLoadReturnsDefaultWhenNoPolicyFilesExist(): void
    {
        // Use a temporary directory that has no .hatfield/safe-guard.json
        $tmpDir = sys_get_temp_dir().'/safeguard-test-'.bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);

        try {
            $policy = $this->store->load($tmpDir);

            $this->assertSame([], $policy->allowCommandPatterns);
            $this->assertNotEmpty($policy->protectedReadPatterns);
        } finally {
            array_map('unlink', glob($tmpDir.'/*') ?: []);
            rmdir($tmpDir);
        }
    }

    public function testLoadFromValidPolicyFile(): void
    {
        $tmpDir = sys_get_temp_dir().'/safeguard-test-'.bin2hex(random_bytes(8));
        mkdir($tmpDir.'/.hatfield', 0700, true);

        $policyJson = json_encode([
            'allowCommandPatterns' => ['ls -la'],
            'dangerousCommandPatterns' => ['my-risky-cmd'],
            'protectedReadPatterns' => ['.my-custom-secret'],
        ]);
        \assert(false !== $policyJson);
        file_put_contents($tmpDir.'/.hatfield/safe-guard.json', $policyJson);

        try {
            $policy = $this->store->load($tmpDir);

            $this->assertSame(['ls -la'], $policy->allowCommandPatterns);
            $this->assertSame(['my-risky-cmd'], $policy->dangerousCommandPatterns);
            // Protected patterns are additive: defaults + file patterns
            $this->assertContains('.env.local', $policy->protectedReadPatterns);
            $this->assertContains('.my-custom-secret', $policy->protectedReadPatterns);
        } finally {
            unlink($tmpDir.'/.hatfield/safe-guard.json');
            rmdir($tmpDir.'/.hatfield');
            rmdir($tmpDir);
        }
    }

    public function testInvalidJsonIsSilentlyIgnored(): void
    {
        $tmpDir = sys_get_temp_dir().'/safeguard-test-'.bin2hex(random_bytes(8));
        mkdir($tmpDir.'/.hatfield', 0700, true);
        file_put_contents($tmpDir.'/.hatfield/safe-guard.json', '{ not valid json }');

        try {
            // Should not throw — silently falls through to defaults
            $policy = $this->store->load($tmpDir);

            $this->assertSame([], $policy->allowCommandPatterns);
            $this->assertNotEmpty($policy->protectedReadPatterns);
        } finally {
            unlink($tmpDir.'/.hatfield/safe-guard.json');
            rmdir($tmpDir.'/.hatfield');
            rmdir($tmpDir);
        }
    }

    public function testEmptyPolicyFileUsesDefaults(): void
    {
        $tmpDir = sys_get_temp_dir().'/safeguard-test-'.bin2hex(random_bytes(8));
        mkdir($tmpDir.'/.hatfield', 0700, true);
        file_put_contents($tmpDir.'/.hatfield/safe-guard.json', '');

        try {
            $policy = $this->store->load($tmpDir);

            $this->assertSame([], $policy->allowCommandPatterns);
            $this->assertNotEmpty($policy->protectedReadPatterns);
        } finally {
            unlink($tmpDir.'/.hatfield/safe-guard.json');
            rmdir($tmpDir.'/.hatfield');
            rmdir($tmpDir);
        }
    }

    public function testProtectedReadPatternsDeduplicated(): void
    {
        $tmpDir = sys_get_temp_dir().'/safeguard-test-'.bin2hex(random_bytes(8));
        mkdir($tmpDir.'/.hatfield', 0700, true);

        // Add a pattern that already exists in defaults
        $policyJson = json_encode([
            'protectedReadPatterns' => ['.env.local', '.my-custom'],
        ]);
        \assert(false !== $policyJson);
        file_put_contents($tmpDir.'/.hatfield/safe-guard.json', $policyJson);

        try {
            $policy = $this->store->load($tmpDir);

            // .env.local should appear only once
            $occurrences = array_filter(
                $policy->protectedReadPatterns,
                static fn (string $p): bool => '.env.local' === $p,
            );
            $this->assertCount(1, $occurrences);
            $this->assertContains('.my-custom', $policy->protectedReadPatterns);
        } finally {
            unlink($tmpDir.'/.hatfield/safe-guard.json');
            rmdir($tmpDir.'/.hatfield');
            rmdir($tmpDir);
        }
    }

    public function testNonArrayFieldsAreFilteredToEmpty(): void
    {
        $tmpDir = sys_get_temp_dir().'/safeguard-test-'.bin2hex(random_bytes(8));
        mkdir($tmpDir.'/.hatfield', 0700, true);

        $policyJson = json_encode([
            'allowCommandPatterns' => 'not-an-array',
            'dangerousCommandPatterns' => null,
        ]);
        \assert(false !== $policyJson);
        file_put_contents($tmpDir.'/.hatfield/safe-guard.json', $policyJson);

        try {
            $policy = $this->store->load($tmpDir);

            $this->assertSame([], $policy->allowCommandPatterns);
            $this->assertSame([], $policy->dangerousCommandPatterns);
        } finally {
            unlink($tmpDir.'/.hatfield/safe-guard.json');
            rmdir($tmpDir.'/.hatfield');
            rmdir($tmpDir);
        }
    }

    public function testNonExistentDirectoryDoesNotThrow(): void
    {
        // load() should not throw when cwd has no .hatfield directory
        $policy = $this->store->load('/nonexistent/path/that/does/not/exist');

        $this->assertNotEmpty($policy->protectedReadPatterns);
    }
}
