<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard\Policy;

use Ineersa\CodingAgent\Config\SafeGuardConfig;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SafeGuardPolicy DTO construction and defaults.
 */
final class SafeGuardPolicyTest extends TestCase
{
    public function testDefaultConstructorProducesEmptyPolicy(): void
    {
        $policy = new SafeGuardPolicy();

        $this->assertSame([], $policy->allowCommandPatterns);
        $this->assertSame([], $policy->allowWriteOutsideCwd);
        $this->assertSame([], $policy->allowDestructiveInPaths);
        $this->assertSame([], $policy->protectedReadPatterns);
        $this->assertSame([], $policy->dangerousCommandPatterns);
    }

    public function testAllFieldsAccepted(): void
    {
        $policy = new SafeGuardPolicy(
            allowCommandPatterns: ['rm -rf'],
            allowWriteOutsideCwd: ['/tmp'],
            allowDestructiveInPaths: ['/tmp'],
            protectedReadPatterns: ['.env.local'],
            dangerousCommandPatterns: ['risky-cmd'],
        );

        $this->assertSame(['rm -rf'], $policy->allowCommandPatterns);
        $this->assertSame(['/tmp'], $policy->allowWriteOutsideCwd);
        $this->assertSame(['/tmp'], $policy->allowDestructiveInPaths);
        $this->assertSame(['.env.local'], $policy->protectedReadPatterns);
        $this->assertSame(['risky-cmd'], $policy->dangerousCommandPatterns);
    }

    public function testAllowDestructiveInPathsFieldExistsButNotWired(): void
    {
        // This field is declared for serialization compatibility with Pi
        // but is deliberately never checked by classifier logic.
        $policy = new SafeGuardPolicy(
            allowDestructiveInPaths: ['/tmp', '/var/tmp'],
        );

        $this->assertCount(2, $policy->allowDestructiveInPaths);
    }

    public function testFromConfigCopiesAllFields(): void
    {
        $config = SafeGuardConfig::fromArray([
            'allow_command_patterns' => ['ls -la'],
            'allow_write_outside_cwd' => ['/tmp'],
            'allow_destructive_in_paths' => ['/safe'],
            'protected_read_patterns' => ['.extra'],
            'dangerous_command_patterns' => ['risky'],
        ]);

        $policy = SafeGuardPolicy::fromConfig($config);

        $this->assertSame(['ls -la'], $policy->allowCommandPatterns);
        $this->assertSame(['/tmp'], $policy->allowWriteOutsideCwd);
        $this->assertSame(['/safe'], $policy->allowDestructiveInPaths);
        $this->assertContains('.env.local', $policy->protectedReadPatterns);
        $this->assertContains('.extra', $policy->protectedReadPatterns);
        $this->assertSame(['risky'], $policy->dangerousCommandPatterns);
    }

    public function testFromConfigDefaultIncludesAllProtectedReadPatterns(): void
    {
        $config = new SafeGuardConfig();
        $policy = SafeGuardPolicy::fromConfig($config);

        $this->assertContains('.env.local', $policy->protectedReadPatterns);
        $this->assertContains('auth.json', $policy->protectedReadPatterns);
        $this->assertContains('.ssh/id_', $policy->protectedReadPatterns);
        $this->assertContains('.aws/credentials', $policy->protectedReadPatterns);
    }
}
