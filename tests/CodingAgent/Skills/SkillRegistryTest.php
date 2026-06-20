<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Skills;

use Ineersa\CodingAgent\Skills\SkillDefinition;
use Ineersa\CodingAgent\Skills\SkillRegistry;
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
        $this->tmpDir = sys_get_temp_dir().'/skills_registry_test_'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    public function testGetReturnsSkill(): void
    {
        $skill = new SkillDefinition(
            name: 'castor',
            description: 'Runs Castor tasks',
            skillFile: '/path/to/SKILL.md',
            skillDirectory: '/path/to',
        );

        $registry = new SkillRegistry([$skill]);

        $this->assertSame($skill, $registry->get('castor'));
    }

    public function testGetReturnsNullForUnknown(): void
    {
        $registry = new SkillRegistry([]);

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

        $registry = new SkillRegistry([$enabled, $disabled]);

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

        $registry = new SkillRegistry([$withDesc, $withoutDesc]);

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

        $registry = new SkillRegistry([$skill]);
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

        $registry = new SkillRegistry([$skill]);
        $body = $registry->readBody($skill);

        $this->assertSame('', $body);
    }

    public function testCollisionsReturnsEmptyWhenNoCollisions(): void
    {
        $skill = new SkillDefinition(
            name: 'unique',
            description: 'Unique skill',
            skillFile: '/a/SKILL.md',
            skillDirectory: '/a',
        );

        $registry = new SkillRegistry([$skill]);

        $this->assertSame([], $registry->collisions());
    }

    public function testCollisionsRecordsDiagnostics(): void
    {
        $collisions = [
            ['winner' => '/prio/skill', 'ignored' => '/other/skill', 'name' => 'myskill'],
        ];

        $skill = new SkillDefinition(
            name: 'myskill',
            description: 'Winner skill',
            skillFile: '/prio/skill/SKILL.md',
            skillDirectory: '/prio/skill',
        );

        $registry = new SkillRegistry([$skill], $collisions);

        $this->assertCount(1, $registry->collisions());
        $this->assertSame('myskill', $registry->collisions()[0]['name']);
        $this->assertSame('/prio/skill', $registry->collisions()[0]['winner']);
        $this->assertSame('/other/skill', $registry->collisions()[0]['ignored']);
    }

    public function testAllReturnsAllSkills(): void
    {
        $a = new SkillDefinition(name: 'a', description: '', skillFile: '/a/SKILL.md', skillDirectory: '/a');
        $b = new SkillDefinition(name: 'b', description: '', skillFile: '/b/SKILL.md', skillDirectory: '/b');

        $registry = new SkillRegistry([$a, $b]);

        $all = $registry->all();
        $this->assertCount(2, $all);
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
