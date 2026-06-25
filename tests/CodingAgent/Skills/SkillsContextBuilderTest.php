<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Skills;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Skills\SkillContextRenderer;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SkillsContextBuilder.
 *
 * @group skills
 */
final class SkillsContextBuilderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/skills_builder_test_'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    public function testBuildReturnsSkillsInstructionsWhenSkillsFound(): void
    {
        // Create a skill
        $skillDir = $this->tmpDir.'/.hatfield/skills/castor';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: castor\ndescription: Runs Castor tasks\n---\n\n# Castor skill");

        $builder = $this->createBuilder(cwd: $this->tmpDir);
        $output = $builder->build();

        $this->assertStringContainsString('<skills_instructions>', $output);
        $this->assertStringContainsString('<available_skills>', $output);
        $this->assertStringContainsString('<name>castor</name>', $output);
        $this->assertStringContainsString('<description>Runs Castor tasks</description>', $output);
    }

    public function testBuildReturnsEmptyWhenNoSkills(): void
    {
        $builder = $this->createBuilder(cwd: $this->tmpDir);
        $output = $builder->build();

        $this->assertSame('', $output);
    }

    public function testBuildIncludesPreloadedSkills(): void
    {
        // Create a skill
        $skillDir = $this->tmpDir.'/.hatfield/skills/castor';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: castor\ndescription: Runs Castor tasks\n---\n\n# Castor body\n\nActual content");

        $config = new SkillsConfig(
            noSkills: false,
            skillsPaths: [],
            preloadSkills: ['castor'],
        );

        $builder = $this->createBuilder(cwd: $this->tmpDir, config: $config);
        $output = $builder->build();

        // Should have both available skills and the preloaded skill body
        $this->assertStringContainsString('<available_skills>', $output);
        $this->assertStringContainsString('<skill name="castor"', $output);
        $this->assertStringContainsString('Actual content', $output);
    }

    public function testBuildPreloadOrderMatchesCliOrder(): void
    {
        mkdir($this->tmpDir.'/.hatfield/skills/first', 0777, true);
        file_put_contents($this->tmpDir.'/.hatfield/skills/first/SKILL.md', "---\nname: first\ndescription: First skill\n---\n\nFirst body");

        mkdir($this->tmpDir.'/.hatfield/skills/second', 0777, true);
        file_put_contents($this->tmpDir.'/.hatfield/skills/second/SKILL.md', "---\nname: second\ndescription: Second skill\n---\n\nSecond body");

        $config = new SkillsConfig(
            noSkills: false,
            skillsPaths: [],
            preloadSkills: ['first', 'second'],
        );

        $builder = $this->createBuilder(cwd: $this->tmpDir, config: $config);
        $output = $builder->build();

        // Both skill blocks should appear
        $this->assertSame(2, substr_count($output, '<skill name='));

        // Verify ordering: first preload appears before second preload
        $posFirst = strpos($output, 'First body');
        $posSecond = strpos($output, 'Second body');
        $this->assertNotFalse($posFirst);
        $this->assertNotFalse($posSecond);
        $this->assertLessThan($posSecond, $posFirst);
    }

    public function testBuildPreloadSkipsUnknown(): void
    {
        $config = new SkillsConfig(
            noSkills: true,
            skillsPaths: [],
            preloadSkills: ['nonexistent'],
        );

        $builder = $this->createBuilder(cwd: $this->tmpDir, config: $config);
        $output = $builder->build();

        // Should be empty since no skills exist
        $this->assertSame('', $output);
    }

    public function testPreloadDisabledSkill(): void
    {
        // A skill with disable-model-invocation: true can still be explicitly preloaded.
        $skillDir = $this->tmpDir.'/.hatfield/skills/noinvoke';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: noinvoke\ndescription: A skill disabled for model invocation\ndisable-model-invocation: true\n---\n\n# Noinvoke body\n\nThis skill cannot be auto-invoked but can be preloaded.");

        $config = new SkillsConfig(
            noSkills: false,
            skillsPaths: [],
            preloadSkills: ['noinvoke'],
        );

        $builder = $this->createBuilder(cwd: $this->tmpDir, config: $config);
        $output = $builder->build();

        // Should NOT have <available_skills> (skill is model-invocation disabled)
        $this->assertStringNotContainsString('<available_skills>', $output);

        // Should have the preloaded <skill> block with body content
        $this->assertStringContainsString('<skill name="noinvoke"', $output);
        $this->assertStringContainsString('Noinvoke body', $output);
        $this->assertStringContainsString('This skill cannot be auto-invoked', $output);
    }

    /* ───────── Private helpers ───────── */


    public function testBuildForRendersNamedSkillBodies(): void
    {
        $skillDir = $this->tmpDir.'/.hatfield/skills/arch';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---
name: arch
description: Arch skill
---

ARCH_BODY_UNIQUE");

        $builder = $this->createBuilder(cwd: $this->tmpDir);
        $output = $builder->buildFor(['arch']);

        $this->assertStringContainsString('<skill name="arch"', $output);
        $this->assertStringContainsString('ARCH_BODY_UNIQUE', $output);
        $this->assertStringNotContainsString('<available_skills>', $output);
    }

    public function testBuildForReturnsEmptyForEmptyNames(): void
    {
        $builder = $this->createBuilder(cwd: $this->tmpDir);
        $this->assertSame('', $builder->buildFor([]));
    }
    private function createBuilder(
        ?string $cwd = null,
        ?SkillsConfig $config = null,
    ): SkillsContextBuilder {
        $projectDir = $this->tmpDir;
        $homeDir = $this->tmpDir.'/home';
        if (!is_dir($homeDir)) {
            mkdir($homeDir, 0777, true);
        }
        $skillsConfig = $config ?? new SkillsConfig();

        $discovery = new SkillDiscovery(
            config: $skillsConfig,
            pathResolver: new SettingsPathResolver($projectDir, $homeDir),
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'test'),
                logging: new LoggingConfig(),
                cwd: $cwd ?? $this->tmpDir,
            ),
            extractor: new MarkdownFrontmatterExtractor(),
        );

        return new SkillsContextBuilder(
            discovery: $discovery,
            config: $skillsConfig,
            renderer: new SkillContextRenderer(),
            extractor: new MarkdownFrontmatterExtractor(),
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
