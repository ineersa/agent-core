<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\Jbcontext\Assets\JbcontextAssetInstaller;
use Ineersa\HatfieldExt\Jbcontext\Assets\JbcontextMarkdownFrontmatter;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JbcontextAssetInstallerTest extends TestCase
{
    private string $root;
    private string $projectDir;
    private string $homeDir;
    private string $packageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = TestDirectoryIsolation::createOsTempDir('jbcontext-assets-');
        $this->projectDir = $this->root.'/project';
        $this->homeDir = $this->root.'/home';
        TestDirectoryIsolation::ensureDirectory($this->projectDir);
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield/agents');
        $this->packageRoot = \dirname(__DIR__);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->root);
        parent::tearDown();
    }

    #[Test]
    public function installsBundledSkillWhenAbsent(): void
    {
        $installer = $this->installer();
        $installer->install();

        $skill = $this->projectDir.'/.hatfield/skills/jbcontext-semantic-search/SKILL.md';
        $this->assertFileExists($skill);
        $this->assertSame('1.0.0', JbcontextMarkdownFrontmatter::versionOf((string) file_get_contents($skill)));
        $this->assertStringNotContainsString('managed-by:', (string) file_get_contents($skill));
    }

    #[Test]
    public function reinstallsSkillWhenInstalledVersionMissingOrDifferent(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $dest = $paths->skillDestinationDir.'/SKILL.md';
        mkdir(\dirname($dest), 0o777, true);
        file_put_contents($dest, "---\nname: jbcontext-semantic-search\ndescription: stale\n---\nstale body\n");

        $this->installer()->install();

        $installed = (string) file_get_contents($dest);
        $this->assertSame('1.0.0', JbcontextMarkdownFrontmatter::versionOf($installed));
        $this->assertStringContainsString('Semantic code search', $installed);
    }

    #[Test]
    public function leavesSameVersionSkillUntouched(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $dest = $paths->skillDestinationDir.'/SKILL.md';
        mkdir(\dirname($dest), 0o777, true);
        $custom = "---\nname: jbcontext-semantic-search\ndescription: custom\nversion: 1.0.0\n---\ncustom body\n";
        file_put_contents($dest, $custom);

        $this->installer()->install();

        $this->assertSame($custom, file_get_contents($dest));
    }

    #[Test]
    public function copiesUserScoutOnceWithCodeSearchAndSkill(): void
    {
        $userScout = $this->homeDir.'/.hatfield/agents/scout.md';
        file_put_contents($userScout, <<<'MD'
---
name: scout
description: Fast codebase recon
model: openai-codex/gpt-5.6-luna
thinking: medium
systemPromptMode: append
tools:
  - read
  - bash
---

You are a scout.
MD);

        $this->installer()->install();

        $projectScout = $this->projectDir.'/.hatfield/agents/scout.md';
        $this->assertFileExists($projectScout);
        $parsed = JbcontextMarkdownFrontmatter::parse((string) file_get_contents($projectScout));
        $this->assertSame('openai-codex/gpt-5.6-luna', $parsed['frontmatter']['model']);
        $this->assertSame('medium', $parsed['frontmatter']['thinking']);
        $this->assertContains('code_search', $parsed['frontmatter']['tools']);
        $this->assertContains('jbcontext-semantic-search', $parsed['frontmatter']['skills']);
        $this->assertStringContainsString('You are a scout.', $parsed['body']);
        $this->assertStringContainsString('jbcontext semantic search', $parsed['body']);
        $this->assertSame(
            (string) file_get_contents($userScout),
            (string) file_get_contents($userScout),
            'user scout must remain unmodified',
        );
        $this->assertStringNotContainsString('code_search', (string) file_get_contents($userScout));
    }

    #[Test]
    public function doesNotTouchExistingProjectScout(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        mkdir(\dirname($paths->scoutDestinationPath), 0o777, true);
        $existing = "---\nname: scout\n---\nproject owned\n";
        file_put_contents($paths->scoutDestinationPath, $existing);
        file_put_contents($this->homeDir.'/.hatfield/agents/scout.md', "---\nname: scout\n---\nuser\n");

        $this->installer()->install();

        $this->assertSame($existing, file_get_contents($paths->scoutDestinationPath));
    }

    #[Test]
    public function skipsScoutAndWarnsWhenUserScoutMissing(): void
    {
        $logger = new TestLogger();
        $this->installer($logger)->install();

        $this->assertFileDoesNotExist($this->projectDir.'/.hatfield/agents/scout.md');
        $events = array_map(
            static fn (array $r): string => (string) ($r['context']['event_type'] ?? $r['message']),
            $logger->records,
        );
        $this->assertContains('jbcontext.assets.scout_user_missing', $events);
    }

    private function installer(?TestLogger $logger = null): JbcontextAssetInstaller
    {
        return new JbcontextAssetInstaller(
            JbcontextPaths::fromProjectRoot($this->projectDir),
            $this->packageRoot,
            $logger ?? new TestLogger(),
            $this->homeDir,
        );
    }
}
