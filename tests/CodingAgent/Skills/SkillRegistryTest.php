<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Skills;

use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Skills\SkillDefinition;
use Ineersa\CodingAgent\Skills\SkillRegistry;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SkillRegistry.
 *
 * @group skills
 */
final class SkillRegistryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('skills_registry_test');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testGetReturnsSkill(): void
    {
        $skill = new SkillDefinition(
            name: 'castor',
            description: 'Runs Castor tasks',
            skillFile: '/path/to/SKILL.md',
            skillDirectory: '/path/to',
        );

        $registry = new SkillRegistry([$skill], extractor: new MarkdownFrontmatterExtractor());

        $this->assertSame($skill, $registry->get('castor'));
    }

    public function testGetReturnsNullForUnknown(): void
    {
        $registry = new SkillRegistry([], extractor: new MarkdownFrontmatterExtractor());

        $this->assertNull($registry->get('nonexistent'));
    }

    public function testModelInvocableFiltersDisabled(): void
    {
        $enabled = new SkillDefinition(
            name: 'enabled',
            description: 'Enabled skill',
            skillFile: '/a/SKILL.md',
            skillDirectory: '/a',
            modelInvocationEnabled: true,
        );

        $disabled = new SkillDefinition(
            name: 'disabled',
            description: 'Disabled skill',
            skillFile: '/b/SKILL.md',
            skillDirectory: '/b',
            modelInvocationEnabled: false,
        );

        $registry = new SkillRegistry([$enabled, $disabled], extractor: new MarkdownFrontmatterExtractor());

        $invocable = $registry->modelInvocable();

        $this->assertCount(1, $invocable);
        $this->assertSame('enabled', $invocable[0]->name);
    }

    public function testModelInvocableFiltersMissingDescription(): void
    {
        $withDesc = new SkillDefinition(
            name: 'withdesc',
            description: 'Has description',
            skillFile: '/a/SKILL.md',
            skillDirectory: '/a',
        );

        $withoutDesc = new SkillDefinition(
            name: 'withoutdesc',
            description: '',
            skillFile: '/b/SKILL.md',
            skillDirectory: '/b',
        );

        $registry = new SkillRegistry([$withDesc, $withoutDesc], extractor: new MarkdownFrontmatterExtractor());

        $invocable = $registry->modelInvocable();

        $this->assertCount(1, $invocable);
        $this->assertSame('withdesc', $invocable[0]->name);
    }

    public function testReadBodyStripsFrontmatter(): void
    {
        $skillFile = $this->tmpDir.'/SKILL.md';
        file_put_contents($skillFile, "---\nname: test\ndescription: Test\n---\n\n# Skill body\n\nActual content");

        $skill = new SkillDefinition(
            name: 'test',
            description: 'Test',
            skillFile: $skillFile,
            skillDirectory: $this->tmpDir,
        );

        $registry = new SkillRegistry([$skill], extractor: new MarkdownFrontmatterExtractor());
        $body = $registry->readBody($skill);

        $this->assertStringNotContainsString('name: test', $body);
        $this->assertStringContainsString('Skill body', $body);
        $this->assertStringContainsString('Actual content', $body);
    }

    public function testReadBodyReturnsEmptyOnMissingFile(): void
    {
        $skill = new SkillDefinition(
            name: 'missing',
            description: 'Missing file',
            skillFile: '/nonexistent/SKILL.md',
            skillDirectory: '/nonexistent',
        );

        $registry = new SkillRegistry([$skill], extractor: new MarkdownFrontmatterExtractor());
        $body = $registry->readBody($skill);

        $this->assertSame('', $body);
    }
}
