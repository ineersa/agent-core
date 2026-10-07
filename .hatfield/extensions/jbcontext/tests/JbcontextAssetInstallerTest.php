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

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = TestDirectoryIsolation::createOsTempDir('jbcontext-assets-');
        $this->projectDir = $this->root.'/project';
        $this->homeDir = $this->root.'/home';
        TestDirectoryIsolation::ensureDirectory($this->projectDir);
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield/agents');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->root);
        parent::tearDown();
    }

    #[Test]
    public function copiesUserScoutWithCodeSearchWithoutChangingSkillsOrBody(): void
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
skills:
  - existing-skill
---

You are a scout.
MD);
        $original = (string) file_get_contents($userScout);

        $this->installer()->install();

        $projectScout = $this->projectDir.'/.hatfield/agents/scout.md';
        $this->assertFileExists($projectScout);
        $parsed = JbcontextMarkdownFrontmatter::parse((string) file_get_contents($projectScout));
        $this->assertSame('openai-codex/gpt-5.6-luna', $parsed['frontmatter']['model']);
        $this->assertSame('medium', $parsed['frontmatter']['thinking']);
        $this->assertContains('code_search', $parsed['frontmatter']['tools']);
        $this->assertSame(['existing-skill'], $parsed['frontmatter']['skills']);
        $this->assertSame(JbcontextMarkdownFrontmatter::parse($original)['body'], $parsed['body']);
        $this->assertSame($original, file_get_contents($userScout), 'user scout must remain unmodified');
        $this->assertDirectoryDoesNotExist($this->projectDir.'/.hatfield/skills');
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
            $logger ?? new TestLogger(),
            $this->homeDir,
        );
    }
}
