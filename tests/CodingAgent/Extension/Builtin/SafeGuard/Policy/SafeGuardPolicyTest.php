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
    public function testFromConfigCopiesAllFields(): void
    {
        $config = SafeGuardConfig::fromArray([
            'allow_command_patterns' => ['ls -la'],
            'allow_write_outside_cwd' => ['/tmp'],
            'protected_read_patterns' => ['.extra'],
            'dangerous_command_patterns' => ['risky'],
        ]);

        $policy = SafeGuardPolicy::fromConfig($config);

        $this->assertSame(['ls -la'], $policy->allowCommandPatterns);
        $this->assertSame(['/tmp'], $policy->allowWriteOutsideCwd);
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
