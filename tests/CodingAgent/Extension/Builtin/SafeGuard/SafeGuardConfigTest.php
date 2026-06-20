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

        self::assertSame([], $config->allowCommandPatterns);
        self::assertSame([], $config->allowWriteOutsideCwd);
        self::assertSame([], $config->allowDestructiveInPaths);
        self::assertNotEmpty($config->protectedReadPatterns);
        self::assertSame([], $config->dangerousCommandPatterns);
        self::assertSame('bash', $config->bashToolName);
        self::assertSame('write', $config->writeToolName);
        self::assertSame('edit', $config->editToolName);
        self::assertSame('read', $config->readToolName);
    }

    public function testDefaultProtectedReadPatternsIncludeAllPiDefaults(): void
    {
        $config = new SafeGuardConfig();

        self::assertContains('.env.local', $config->protectedReadPatterns);
        self::assertContains('auth.json', $config->protectedReadPatterns);
        self::assertContains('.ssh/id_', $config->protectedReadPatterns);
        self::assertContains('.aws/credentials', $config->protectedReadPatterns);
        self::assertContains('.kube/config', $config->protectedReadPatterns);
        self::assertContains('.pem', $config->protectedReadPatterns);
        self::assertContains('service-account', $config->protectedReadPatterns);
    }

    public function testFromArrayEmptyDataReturnsDefaults(): void
    {
        $config = SafeGuardConfig::fromArray([]);

        self::assertSame([], $config->allowCommandPatterns);
        self::assertSame([], $config->allowWriteOutsideCwd);
        self::assertNotEmpty($config->protectedReadPatterns);
        self::assertSame('bash', $config->bashToolName);
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

        self::assertSame(['ls -la'], $config->allowCommandPatterns);
        self::assertSame(['/tmp'], $config->allowWriteOutsideCwd);
        self::assertSame(['/safe'], $config->allowDestructiveInPaths);
        self::assertContains('.env.local', $config->protectedReadPatterns);
        self::assertContains('.my-custom', $config->protectedReadPatterns);
        self::assertSame(['risky'], $config->dangerousCommandPatterns);
        self::assertSame('execute', $config->bashToolName);
        self::assertSame('create_file', $config->writeToolName);
        self::assertSame('patch_file', $config->editToolName);
        self::assertSame('view_file', $config->readToolName);
    }

    public function testProtectedReadPatternsAreAdditive(): void
    {
        $config = SafeGuardConfig::fromArray([
            'protected_read_patterns' => ['.extra.secret'],
        ]);

        self::assertContains('.env.local', $config->protectedReadPatterns);
        self::assertContains('.extra.secret', $config->protectedReadPatterns);
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
        self::assertCount(1, $occurrences);
    }

    public function testNonArrayFieldsBecomeEmpty(): void
    {
        $config = SafeGuardConfig::fromArray([
            'allow_command_patterns' => 'not-an-array',
            'dangerous_command_patterns' => null,
        ]);

        self::assertSame([], $config->allowCommandPatterns);
        self::assertSame([], $config->dangerousCommandPatterns);
    }

    public function testEmptyStringsAreFiltered(): void
    {
        $config = SafeGuardConfig::fromArray([
            'allow_command_patterns' => ['valid', '', '  '],
        ]);

        // Empty string '' is filtered, whitespace-only '  ' passes through
        self::assertSame(['valid', '  '], $config->allowCommandPatterns);
    }

    public function testToolNamesDefaultWhenMissing(): void
    {
        $config = SafeGuardConfig::fromArray([
            'tool_names' => ['bash' => 'run'],
        ]);

        self::assertSame('run', $config->bashToolName);
        self::assertSame('write', $config->writeToolName);
        self::assertSame('edit', $config->editToolName);
        self::assertSame('read', $config->readToolName);
    }
}
