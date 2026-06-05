<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardPolicyWriter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SafeGuardPolicyWriter — persistence of "Always allow" patterns.
 *
 * All tests use a temporary file that is cleaned up after each test.
 */
final class SafeGuardPolicyWriterTest extends TestCase
{
    private string $tmpDir;
    private string $settingsPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/sg_writer_test_'.uniqid();
        mkdir($this->tmpDir, 0o755, true);
        $this->settingsPath = $this->tmpDir.'/settings.yaml';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->settingsPath)) {
            unlink($this->settingsPath);
        }
        // Clean up any temp files from atomic writes
        foreach (glob($this->settingsPath.'.tmp.*') as $tmp) {
            @unlink($tmp);
        }
        @rmdir($this->tmpDir);
    }

    public function testAddAllowPatternCreatesNewFile(): void
    {
        $writer = new SafeGuardPolicyWriter($this->settingsPath);
        $writer->addAllowPattern('destructive', 'rm -rf /tmp/build');

        $this->assertFileExists($this->settingsPath);
        $content = file_get_contents($this->settingsPath);
        $this->assertStringContainsString('rm -rf /tmp/build', $content);
        $this->assertStringContainsString('allow_command_patterns', $content);
    }

    public function testAddAllowPatternIsIdempotent(): void
    {
        $writer = new SafeGuardPolicyWriter($this->settingsPath);
        $writer->addAllowPattern('destructive', 'rm -rf /tmp');
        $writer->addAllowPattern('destructive', 'rm -rf /tmp');

        $content = file_get_contents($this->settingsPath);

        // Count occurrences of the pattern — should be exactly 1
        $this->assertSame(1, substr_count($content, 'rm -rf /tmp'));
    }

    public function testAddAllowPatternAppendsToExisting(): void
    {
        file_put_contents(
            $this->settingsPath,
            "extensions:\n    settings:\n        safe_guard:\n            allow_command_patterns: ['git push']\n",
        );

        $writer = new SafeGuardPolicyWriter($this->settingsPath);
        $writer->addAllowPattern('destructive', 'rm -rf /tmp');

        $content = file_get_contents($this->settingsPath);
        $this->assertStringContainsString('git push', $content);
        $this->assertStringContainsString('rm -rf /tmp', $content);
    }

    public function testAddAllowPatternForWriteOutsideCwd(): void
    {
        $writer = new SafeGuardPolicyWriter($this->settingsPath);
        $writer->addAllowPattern('write_outside_cwd', '/etc/hosts');

        $content = file_get_contents($this->settingsPath);
        $this->assertStringContainsString('allow_write_outside_cwd', $content);
        $this->assertStringContainsString('/etc/hosts', $content);
    }

    public function testDoesNotOverwriteUnparseableFile(): void
    {
        file_put_contents(
            $this->settingsPath,
            "valid_key: value\n  bad_indent: [\n",
        );

        $writer = new SafeGuardPolicyWriter($this->settingsPath);
        $writer->addAllowPattern('destructive', 'rm -rf /tmp');

        // Unparseable YAML is treated as empty; the pattern is added
        // and the file is written with the new settings.
        $content = file_get_contents($this->settingsPath);
        $this->assertStringContainsString('rm -rf /tmp', $content);
    }

    public function testPreservesOtherSettings(): void
    {
        file_put_contents(
            $this->settingsPath,
            "ai:\n    provider: openai\nextensions:\n    settings:\n        safe_guard:\n            allow_command_patterns: ['ls']\n",
        );

        $writer = new SafeGuardPolicyWriter($this->settingsPath);
        $writer->addAllowPattern('destructive', 'rm -rf /tmp');

        $content = file_get_contents($this->settingsPath);
        $this->assertStringContainsString('openai', $content);
        $this->assertStringContainsString('rm -rf /tmp', $content);
    }

    public function testUnknownCategoryIsNoop(): void
    {
        file_put_contents($this->settingsPath, "key: value\n");

        $writer = new SafeGuardPolicyWriter($this->settingsPath);
        $writer->addAllowPattern('unknown_category', 'pattern');

        // File unchanged
        $content = file_get_contents($this->settingsPath);
        $this->assertSame("key: value\n", $content);
    }

    public function testThrowsOnWriteFailure(): void
    {
        // Use a path where the parent directory doesn't exist
        $writer = new SafeGuardPolicyWriter('/nonexistent/path/settings.yaml');

        // The mkdir failure emits a PHP warning, which is expected.
        set_error_handler(static function (int $severity, string $message): bool {
            return str_contains($message, 'mkdir');
        }, \E_WARNING);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create directory');
        $writer->addAllowPattern('destructive', 'rm -rf /tmp');

        restore_error_handler();
    }
}
