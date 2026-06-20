<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard\Policy;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SafeGuardPolicy DTO construction and defaults.
 */
final class SafeGuardPolicyTest extends TestCase
{
    public function testDefaultConstructorProducesEmptyPolicy(): void
    {
        $policy = new SafeGuardPolicy();

        self::assertSame([], $policy->allowCommandPatterns);
        self::assertSame([], $policy->allowWriteOutsideCwd);
        self::assertSame([], $policy->allowDestructiveInPaths);
        self::assertSame([], $policy->protectedReadPatterns);
        self::assertSame([], $policy->dangerousCommandPatterns);
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

        self::assertSame(['rm -rf'], $policy->allowCommandPatterns);
        self::assertSame(['/tmp'], $policy->allowWriteOutsideCwd);
        self::assertSame(['/tmp'], $policy->allowDestructiveInPaths);
        self::assertSame(['.env.local'], $policy->protectedReadPatterns);
        self::assertSame(['risky-cmd'], $policy->dangerousCommandPatterns);
    }

    public function testAllowDestructiveInPathsFieldExistsButNotWired(): void
    {
        // This field is declared for serialization compatibility with Pi
        // but is deliberately never checked by classifier logic.
        $policy = new SafeGuardPolicy(
            allowDestructiveInPaths: ['/tmp', '/var/tmp'],
        );

        self::assertCount(2, $policy->allowDestructiveInPaths);
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

        self::assertSame(['ls -la'], $policy->allowCommandPatterns);
        self::assertSame(['/tmp'], $policy->allowWriteOutsideCwd);
        self::assertSame(['/safe'], $policy->allowDestructiveInPaths);
        self::assertContains('.env.local', $policy->protectedReadPatterns);
        self::assertContains('.extra', $policy->protectedReadPatterns);
        self::assertSame(['risky'], $policy->dangerousCommandPatterns);
    }

    public function testFromConfigDefaultIncludesAllProtectedReadPatterns(): void
    {
        $config = new SafeGuardConfig();
        $policy = SafeGuardPolicy::fromConfig($config);

        self::assertContains('.env.local', $policy->protectedReadPatterns);
        self::assertContains('auth.json', $policy->protectedReadPatterns);
        self::assertContains('.ssh/id_', $policy->protectedReadPatterns);
        self::assertContains('.aws/credentials', $policy->protectedReadPatterns);
    }
}
