<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SafeGuardConfig — construction, defaults, fromArray merging.
 */
final class SafeGuardConfigTest extends TestCase
{
    public function testDefaultConstructorHasAllFields(): void
    {
        $config = new SafeGuardConfig();

        $this->assertSame([], $config->allowCommandPatterns);
        $this->assertSame([], $config->allowWriteOutsideCwd);
        $this->assertSame([], $config->allowDestructiveInPaths);
        $this->assertNotEmpty($config->protectedReadPatterns);
        $this->assertSame([], $config->dangerousCommandPatterns);
        $this->assertSame('bash', $config->bashToolName);
        $this->assertSame('write', $config->writeToolName);
        $this->assertSame('edit', $config->editToolName);
        $this->assertSame('read', $config->readToolName);
    }

    public function testDefaultProtectedReadPatternsIncludeAllPiDefaults(): void
    {
        $config = new SafeGuardConfig();

        $this->assertContains('.env.local', $config->protectedReadPatterns);
        $this->assertContains('auth.json', $config->protectedReadPatterns);
        $this->assertContains('.ssh/id_', $config->protectedReadPatterns);
        $this->assertContains('.aws/credentials', $config->protectedReadPatterns);
        $this->assertContains('.kube/config', $config->protectedReadPatterns);
        $this->assertContains('.pem', $config->protectedReadPatterns);
        $this->assertContains('service-account', $config->protectedReadPatterns);
    }

    public function testFromArrayEmptyDataReturnsDefaults(): void
    {
        $config = SafeGuardConfig::fromArray([]);

        $this->assertSame([], $config->allowCommandPatterns);
        $this->assertSame([], $config->allowWriteOutsideCwd);
        $this->assertNotEmpty($config->protectedReadPatterns);
        $this->assertSame('bash', $config->bashToolName);
    }

    public function testFromArrayParsesAllFields(): void
    {
        $config = SafeGuardConfig::fromArray([
            'allow_command_patterns' => ['ls -la'],
            'allow_write_outside_cwd' => ['/tmp'],
            'allow_destructive_in_paths' => ['/safe'],
            'protected_read_patterns' => ['.my-custom'],
            'dangerous_command_patterns' => ['risky'],
            'tool_names' => [
                'bash' => 'execute',
                'write' => 'create_file',
                'edit' => 'patch_file',
                'read' => 'view_file',
            ],
        ]);

        $this->assertSame(['ls -la'], $config->allowCommandPatterns);
        $this->assertSame(['/tmp'], $config->allowWriteOutsideCwd);
        $this->assertSame(['/safe'], $config->allowDestructiveInPaths);
        $this->assertContains('.env.local', $config->protectedReadPatterns);
        $this->assertContains('.my-custom', $config->protectedReadPatterns);
        $this->assertSame(['risky'], $config->dangerousCommandPatterns);
        $this->assertSame('execute', $config->bashToolName);
        $this->assertSame('create_file', $config->writeToolName);
        $this->assertSame('patch_file', $config->editToolName);
        $this->assertSame('view_file', $config->readToolName);
    }

    public function testProtectedReadPatternsAreAdditive(): void
    {
        $config = SafeGuardConfig::fromArray([
            'protected_read_patterns' => ['.extra.secret'],
        ]);

        $this->assertContains('.env.local', $config->protectedReadPatterns);
        $this->assertContains('.extra.secret', $config->protectedReadPatterns);
    }

    public function testProtectedReadPatternsDeduplicate(): void
    {
        $config = SafeGuardConfig::fromArray([
            'protected_read_patterns' => ['.env.local'],
        ]);

        $occurrences = array_filter(
            $config->protectedReadPatterns,
            static fn (string $p): bool => '.env.local' === $p,
        );
        $this->assertCount(1, $occurrences);
    }

    public function testNonArrayFieldsBecomeEmpty(): void
    {
        $config = SafeGuardConfig::fromArray([
            'allow_command_patterns' => 'not-an-array',
            'dangerous_command_patterns' => null,
        ]);

        $this->assertSame([], $config->allowCommandPatterns);
        $this->assertSame([], $config->dangerousCommandPatterns);
    }

    public function testEmptyStringsAreFiltered(): void
    {
        $config = SafeGuardConfig::fromArray([
            'allow_command_patterns' => ['valid', '', '  '],
        ]);

        // Empty string '' is filtered, whitespace-only '  ' passes through
        $this->assertSame(['valid', '  '], $config->allowCommandPatterns);
    }

    public function testToolNamesDefaultWhenMissing(): void
    {
        $config = SafeGuardConfig::fromArray([
            'tool_names' => ['bash' => 'run'],
        ]);

        $this->assertSame('run', $config->bashToolName);
        $this->assertSame('write', $config->writeToolName);
        $this->assertSame('edit', $config->editToolName);
        $this->assertSame('read', $config->readToolName);
    }
}
