<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard\Policy;

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

        // The classifier does not use this field — we just verify the DTO carries it
    }
}
