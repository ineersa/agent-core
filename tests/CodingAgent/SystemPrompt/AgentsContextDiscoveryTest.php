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
        file_put_contents($this->tmpDir.'/.hatfield/AGENTS.md', 'project context');

        $discovery = $this->createDiscovery($this->tmpDir);

        $results = $discovery->discover();

        self::assertCount(1, $results);
        self::assertStringContainsString('AGENTS.md', $results[0]['path']);
        self::assertSame('project context', $results[0]['content']);
    }

    public function testFilenamePrecedenceAgentsMdOverAgentsMdUpper(): void
    {
        // Create both files in .hatfield/ — AGENTS.md should win.
        file_put_contents($this->tmpDir.'/.hatfield/AGENTS.md', 'lowercase wins');
        file_put_contents($this->tmpDir.'/.hatfield/AGENTS.MD', 'uppercase loses');

        $discovery = $this->createDiscovery($this->tmpDir);

        $results = $discovery->discover();

        self::assertCount(1, $results);
        self::assertStringContainsString('AGENTS.md', $results[0]['path']);
        self::assertStringNotContainsString('AGENTS.MD', $results[0]['path']);
        self::assertSame('lowercase wins', $results[0]['content']);
    }

    /* ───────── Global file ───────── */

    public function testGlobalHatfieldAgentsMd(): void
    {
        $homeDir = $this->tmpDir.'/home';
        mkdir($homeDir.'/.hatfield', 0777, true);
        file_put_contents($homeDir.'/.hatfield/AGENTS.md', 'global context');

        $discovery = $this->createDiscovery($this->tmpDir, $homeDir);

        $results = $discovery->discover();

        self::assertCount(1, $results);
        self::assertStringContainsString('global context', $results[0]['content']);
    }

    public function testGlobalComesBeforeProject(): void
    {
        $homeDir = $this->tmpDir.'/home';
        mkdir($homeDir.'/.hatfield', 0777, true);
        file_put_contents($homeDir.'/.hatfield/AGENTS.md', 'global context');

        file_put_contents($this->tmpDir.'/.hatfield/AGENTS.md', 'project context');

        $discovery = $this->createDiscovery($this->tmpDir, $homeDir);

        $results = $discovery->discover();

        self::assertCount(2, $results);
        // Global first
        self::assertStringContainsString('global context', $results[0]['content']);
        // Project second
        self::assertStringContainsString('project context', $results[1]['content']);
    }

    /* ───────── Ancestor walking ───────── */

    public function testAncestorWalkingNearestFirst(): void
    {
        // Create nested dirs: tmpDir/parent/child
        mkdir($this->tmpDir.'/parent', 0777, true);
        mkdir($this->tmpDir.'/parent/child', 0777, true);
        mkdir($this->tmpDir.'/parent/.hatfield', 0777, true);

        file_put_contents($this->tmpDir.'/.hatfield/AGENTS.md', 'grandparent context');
        file_put_contents($this->tmpDir.'/parent/.hatfield/AGENTS.md', 'parent context');

        $discovery = $this->createDiscovery($this->tmpDir.'/parent/child');

        $results = $discovery->discover();

        self::assertCount(2, $results);
        // Nearest ancestor first (parent)
        self::assertStringContainsString('parent context', $results[0]['content']);
        // Farther ancestor second (grandparent/tmpDir root)
        self::assertStringContainsString('grandparent context', $results[1]['content']);
    }

    /* ───────── Deduplication ───────── */

    public function testDeduplicationByRealpath(): void
    {
        $homeDir = $this->tmpDir.'/home';
        mkdir($homeDir.'/.hatfield', 0777, true);

        // Create AGENTS.md and symlink it into home's .hatfield/
        file_put_contents($this->tmpDir.'/.hatfield/AGENTS.md', 'shared context');
        symlink($this->tmpDir.'/.hatfield/AGENTS.md', $homeDir.'/.hatfield/AGENTS.md');

        // CWD is tmpDir so ancestor walk finds tmpDir/.hatfield/AGENTS.md.
        // Global check also finds the same file via symlink in home/.hatfield/.
        // After realpath resolution, they should be the same.
        $discovery = $this->createDiscovery($this->tmpDir, $homeDir);

        $results = $discovery->discover();

        // Should be only 1 entry since both paths resolve to same realpath.
        self::assertCount(1, $results);
        self::assertSame('shared context', $results[0]['content']);
    }

    /* ───────── Negative cases ───────── */

    public function testNoClaudeMd(): void
    {
        // CLAUDE.md in .hatfield/ or .agents/ should NOT be discovered.
        file_put_contents($this->tmpDir.'/.hatfield/CLAUDE.md', 'claude context');

        $discovery = $this->createDiscovery($this->tmpDir);

        $results = $discovery->discover();

        self::assertCount(0, $results);
    }

    public function testNoFilesReturnsEmpty(): void
    {
        $discovery = $this->createDiscovery($this->tmpDir);

        $results = $discovery->discover();

        self::assertCount(0, $results);
    }

    /* ───────── .agents/ folder support ───────── */

    public function testGlobalAgentsFolderDiscovery(): void
    {
        $homeDir = $this->tmpDir.'/home';
        mkdir($homeDir.'/.agents', 0777, true);
        file_put_contents($homeDir.'/.agents/AGENTS.md', 'agents global context');

        // No .hatfield/ AGENTS.md — should fall through to .agents/
        $discovery = $this->createDiscovery($this->tmpDir, $homeDir);

        $results = $discovery->discover();

        self::assertCount(1, $results);
        self::assertStringContainsString('agents global context', $results[0]['content']);
        self::assertStringContainsString('.agents/AGENTS.md', $results[0]['path']);
    }

    public function testGlobalHatfieldTakesPrecedenceOverAgentsFolder(): void
    {
        $homeDir = $this->tmpDir.'/home';
        mkdir($homeDir.'/.hatfield', 0777, true);
        mkdir($homeDir.'/.agents', 0777, true);
        file_put_contents($homeDir.'/.hatfield/AGENTS.md', 'hatfield global');
        file_put_contents($homeDir.'/.agents/AGENTS.md', 'agents global');

        // .hatfield/ is checked first, so it should win
        $discovery = $this->createDiscovery($this->tmpDir, $homeDir);

        $results = $discovery->discover();

        self::assertCount(1, $results);
        self::assertStringContainsString('hatfield global', $results[0]['content']);
        self::assertStringContainsString('.hatfield/AGENTS.md', $results[0]['path']);
    }

    public function testProjectAgentsFolderDiscovery(): void
    {
        mkdir($this->tmpDir.'/.agents', 0777, true);
        file_put_contents($this->tmpDir.'/.agents/AGENTS.md', 'project agents context');

        // No .hatfield/ AGENTS.md — should fall through to .agents/
        $discovery = $this->createDiscovery($this->tmpDir);

        $results = $discovery->discover();

        self::assertCount(1, $results);
        self::assertStringContainsString('project agents context', $results[0]['content']);
        self::assertStringContainsString('.agents/AGENTS.md', $results[0]['path']);
    }

    public function testProjectHatfieldTakesPrecedenceOverAgentsFolder(): void
    {
        mkdir($this->tmpDir.'/.agents', 0777, true);
        file_put_contents($this->tmpDir.'/.hatfield/AGENTS.md', 'hatfield project');
        file_put_contents($this->tmpDir.'/.agents/AGENTS.md', 'agents project');

        // .hatfield/ is checked first, so it should win
        $discovery = $this->createDiscovery($this->tmpDir);

        $results = $discovery->discover();

        self::assertCount(1, $results);
        self::assertStringContainsString('hatfield project', $results[0]['content']);
        self::assertStringContainsString('.hatfield/AGENTS.md', $results[0]['path']);
    }

    /* ───────── Edge cases ───────── */

    public function testDiscoversAgentsMdUppercaseWhenOnlyThatExists(): void
    {
        // Only AGENTS.MD (uppercase) exists in .hatfield/ — should still be discovered.
        file_put_contents($this->tmpDir.'/.hatfield/AGENTS.MD', 'uppercase only');

        $discovery = $this->createDiscovery($this->tmpDir);

        $results = $discovery->discover();

        self::assertCount(1, $results);
        self::assertStringEndsWith('AGENTS.MD', $results[0]['path']);
        self::assertSame('uppercase only', $results[0]['content']);
    }

    public function testAncestorWalkTerminatesAtFilesystemRoot(): void
    {
        // CWD is a known shallow dir (/tmp). The walk upward should
        // terminate at filesystem root without infinite looping.
        $discovery = $this->createDiscovery(cwd: '/tmp');

        $results = $discovery->discover();

        // Should return without hanging or throwing.
        self::assertIsArray($results);
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
