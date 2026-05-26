<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\SystemPrompt;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextDiscovery;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AgentsContextDiscovery.
 *
 * Covers:
 * - Single AGENTS.md discovery in cwd
 * - Filename precedence (AGENTS.md over AGENTS.MD)
 * - Global ~/.hatfield/AGENTS.md
 * - Global before project ordering
 * - Ancestor walking (nearest first)
 * - Deduplication by realpath
 * - No CLAUDE.md support
 * - No files returns empty
 * - Empty CWD throws
 *
 * @group system-prompt
 */
final class AgentsContextDiscoveryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/agents_context_test_'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir.'/.hatfield', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    /* ───────── Basic discovery ───────── */

    public function testDiscoversProjectAgentsMd(): void
    {
        file_put_contents($this->tmpDir.'/AGENTS.md', 'project context');

        $discovery = $this->createDiscovery($this->tmpDir);

        $results = $discovery->discover();

        $this->assertCount(1, $results);
        $this->assertStringContainsString('AGENTS.md', $results[0]['path']);
        $this->assertSame('project context', $results[0]['content']);
    }

    public function testFilenamePrecedenceAgentsMdOverAgentsMdUpper(): void
    {
        // Create both files — AGENTS.md should win.
        file_put_contents($this->tmpDir.'/AGENTS.md', 'lowercase wins');
        file_put_contents($this->tmpDir.'/AGENTS.MD', 'uppercase loses');

        $discovery = $this->createDiscovery($this->tmpDir);

        $results = $discovery->discover();

        $this->assertCount(1, $results);
        $this->assertStringContainsString('AGENTS.md', $results[0]['path']);
        $this->assertStringNotContainsString('AGENTS.MD', $results[0]['path']);
        $this->assertSame('lowercase wins', $results[0]['content']);
    }

    /* ───────── Global file ───────── */

    public function testGlobalHatfieldAgentsMd(): void
    {
        $homeDir = $this->tmpDir.'/home';
        mkdir($homeDir.'/.hatfield', 0777, true);
        file_put_contents($homeDir.'/.hatfield/AGENTS.md', 'global context');

        $discovery = $this->createDiscovery($this->tmpDir, $homeDir);

        $results = $discovery->discover();

        $this->assertCount(1, $results);
        $this->assertStringContainsString('global context', $results[0]['content']);
    }

    public function testGlobalComesBeforeProject(): void
    {
        $homeDir = $this->tmpDir.'/home';
        mkdir($homeDir.'/.hatfield', 0777, true);
        file_put_contents($homeDir.'/.hatfield/AGENTS.md', 'global context');

        file_put_contents($this->tmpDir.'/AGENTS.md', 'project context');

        $discovery = $this->createDiscovery($this->tmpDir, $homeDir);

        $results = $discovery->discover();

        $this->assertCount(2, $results);
        // Global first
        $this->assertStringContainsString('global context', $results[0]['content']);
        // Project second
        $this->assertStringContainsString('project context', $results[1]['content']);
    }

    /* ───────── Ancestor walking ───────── */

    public function testAncestorWalkingNearestFirst(): void
    {
        // Create nested dirs: tmpDir/parent/child
        mkdir($this->tmpDir.'/parent', 0777, true);
        mkdir($this->tmpDir.'/parent/child', 0777, true);

        file_put_contents($this->tmpDir.'/AGENTS.md', 'grandparent context');
        file_put_contents($this->tmpDir.'/parent/AGENTS.md', 'parent context');

        $discovery = $this->createDiscovery($this->tmpDir.'/parent/child');

        $results = $discovery->discover();

        $this->assertCount(2, $results);
        // Nearest ancestor first (parent)
        $this->assertStringContainsString('parent context', $results[0]['content']);
        // Farther ancestor second (grandparent/tmpDir root)
        $this->assertStringContainsString('grandparent context', $results[1]['content']);
    }

    /* ───────── Deduplication ───────── */

    public function testDeduplicationByRealpath(): void
    {
        $homeDir = $this->tmpDir.'/home';
        mkdir($homeDir.'/.hatfield', 0777, true);

        // Create AGENTS.md and symlink it into .hatfield/
        file_put_contents($this->tmpDir.'/AGENTS.md', 'shared context');
        symlink($this->tmpDir.'/AGENTS.md', $homeDir.'/.hatfield/AGENTS.md');

        // CWD is tmpDir so ancestor walk finds tmpDir/AGENTS.md first.
        // Global check also finds the same file via symlink.
        // After realpath resolution, they should be the same.
        $discovery = $this->createDiscovery($this->tmpDir, $homeDir);

        $results = $discovery->discover();

        // Should be only 1 entry since both paths resolve to same realpath.
        $this->assertCount(1, $results);
        $this->assertSame('shared context', $results[0]['content']);
    }

    /* ───────── Negative cases ───────── */

    public function testNoClaudeMd(): void
    {
        file_put_contents($this->tmpDir.'/CLAUDE.md', 'claude context');

        $discovery = $this->createDiscovery($this->tmpDir);

        $results = $discovery->discover();

        $this->assertCount(0, $results);
    }

    public function testNoFilesReturnsEmpty(): void
    {
        $discovery = $this->createDiscovery($this->tmpDir);

        $results = $discovery->discover();

        $this->assertCount(0, $results);
    }

    /* ───────── Edge cases ───────── */

    public function testDiscoversAgentsMdUppercaseWhenOnlyThatExists(): void
    {
        // Only AGENTS.MD (uppercase) exists — should still be discovered.
        file_put_contents($this->tmpDir.'/AGENTS.MD', 'uppercase only');

        $discovery = $this->createDiscovery($this->tmpDir);

        $results = $discovery->discover();

        $this->assertCount(1, $results);
        $this->assertStringEndsWith('AGENTS.MD', $results[0]['path']);
        $this->assertSame('uppercase only', $results[0]['content']);
    }

    public function testAncestorWalkTerminatesAtFilesystemRoot(): void
    {
        // CWD is a known shallow dir (/tmp). The walk upward should
        // terminate at filesystem root without infinite looping.
        $discovery = $this->createDiscovery(cwd: '/tmp');

        $results = $discovery->discover();

        // Should return without hanging or throwing.
        $this->assertIsArray($results);
    }

    /* ───────── Error cases ───────── */

    public function testEmptyCwdThrows(): void
    {
        $bogusDir = $this->tmpDir.'/bogus';
        mkdir($bogusDir, 0777, true);

        $discovery = $this->createDiscovery(cwd: '', appRoot: $bogusDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CWD is not configured');

        $discovery->discover();
    }

    /* ───────── Private helpers ───────── */

    private function createDiscovery(
        ?string $cwd = null,
        ?string $homeDir = null,
        ?string $appRoot = null,
    ): AgentsContextDiscovery {
        $projectDir = $appRoot ?? $this->tmpDir;

        return new AgentsContextDiscovery(
            pathResolver: new SettingsPathResolver($projectDir, $homeDir),
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'test'),
                logging: new LoggingConfig(),
                cwd: $cwd ?? $this->tmpDir,
            ),
        );
    }

    private function rmdirRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry->isDir()) {
                @rmdir((string) $entry);
            } else {
                @unlink((string) $entry);
            }
        }

        @rmdir($path);
    }
}
