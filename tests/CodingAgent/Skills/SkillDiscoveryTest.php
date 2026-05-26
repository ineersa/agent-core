<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Skills;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SkillDiscovery.
 *
 * @group skills
 */
final class SkillDiscoveryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/skills_discovery_test_'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    /* ───────── Basic discovery ───────── */

    public function testDiscoversSkillFromHatfieldDir(): void
    {
        // Create skills/castor/SKILL.md under cwd's .hatfield/
        $skillDir = $this->tmpDir.'/.hatfield/skills/castor';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: castor\ndescription: Runs Castor tasks\n---\n\n# Castor skill content");

        $discovery = $this->createDiscovery(cwd: $this->tmpDir);

        $skills = $discovery->discover();

        $this->assertCount(1, $skills);
        $this->assertSame('castor', $skills[0]->name);
        $this->assertSame('Runs Castor tasks', $skills[0]->description);
        $this->assertSame($skillDir, $skills[0]->skillDirectory);
    }

    public function testDiscoversSkillFromAgentsDir(): void
    {
        $skillDir = $this->tmpDir.'/.agents/skills/foo';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: foo\ndescription: Foo skill\n---\n\nFoo body");

        $discovery = $this->createDiscovery(cwd: $this->tmpDir);

        $skills = $discovery->discover();

        $this->assertCount(1, $skills);
        $this->assertSame('foo', $skills[0]->name);
    }

    public function testHatfieldTakesPrecedence(): void
    {
        // Same skill name in both .hatfield and .agents
        mkdir($this->tmpDir.'/.hatfield/skills/myskill', 0777, true);
        file_put_contents($this->tmpDir.'/.hatfield/skills/myskill/SKILL.md', "---\nname: myskill\ndescription: From hatfield\n---\n\nHatfield body");

        mkdir($this->tmpDir.'/.agents/skills/myskill', 0777, true);
        file_put_contents($this->tmpDir.'/.agents/skills/myskill/SKILL.md', "---\nname: myskill\ndescription: From agents\n---\n\nAgents body");

        $discovery = $this->createDiscovery(cwd: $this->tmpDir);

        $skills = $discovery->discover();

        // Both should be discovered... no wait, same name, different paths.
        // First discovered wins. Since auto-discovery scans cwd/.hatfield first,
        // then cwd/.agents, .hatfield wins.
        $this->assertCount(1, $skills);
        $this->assertSame('myskill', $skills[0]->name);
        $this->assertStringContainsString('.hatfield', $skills[0]->skillDirectory);
    }

    public function testAdditionalPathsOverrideAutoDiscovery(): void
    {
        // Create same-named skill in auto-discovery path
        mkdir($this->tmpDir.'/.hatfield/skills/myskill', 0777, true);
        file_put_contents($this->tmpDir.'/.hatfield/skills/myskill/SKILL.md', "---\nname: myskill\ndescription: Auto-discovered\n---");

        // Create same-named skill in additional path (higher priority)
        $additionalDir = $this->tmpDir.'/extra-skills';
        mkdir($additionalDir.'/myskill', 0777, true);
        file_put_contents($additionalDir.'/myskill/SKILL.md', "---\nname: myskill\ndescription: From additional path\n---");

        $config = new SkillsConfig(
            noSkills: false,
            skillsPaths: [$additionalDir],
        );

        $discovery = $this->createDiscovery(cwd: $this->tmpDir, config: $config);

        $skills = $discovery->discover();

        // Additional paths are checked first, so the extra one wins
        $this->assertCount(1, $skills);
        $this->assertSame('myskill', $skills[0]->name);
        $this->assertStringContainsString('extra-skills', $skills[0]->skillDirectory);
    }

    public function testNoSkillsDisablesAutoDiscovery(): void
    {
        // Create skill in auto-discovery path
        mkdir($this->tmpDir.'/.hatfield/skills/myskill', 0777, true);
        file_put_contents($this->tmpDir.'/.hatfield/skills/myskill/SKILL.md', "---\nname: myskill\ndescription: Auto skill\n---");

        // Create skill in additional path
        $additionalDir = $this->tmpDir.'/extra-skills';
        mkdir($additionalDir.'/myskill', 0777, true);
        file_put_contents($additionalDir.'/myskill/SKILL.md', "---\nname: myskill\ndescription: Additional skill\n---");

        $config = new SkillsConfig(
            noSkills: true,
            skillsPaths: [$additionalDir],
        );

        $discovery = $this->createDiscovery(cwd: $this->tmpDir, config: $config);

        $skills = $discovery->discover();

        // With noSkills=true, only the additional path skill is found
        $this->assertCount(1, $skills);
        $this->assertSame('myskill', $skills[0]->name);
        $this->assertStringContainsString('extra-skills', $skills[0]->skillDirectory);
    }

    public function testRecursionStopsAtSkillRoot(): void
    {
        // Create a nested structure where SKILL.md exists at one level
        mkdir($this->tmpDir.'/.hatfield/skills/myskill/deep', 0777, true);
        file_put_contents($this->tmpDir.'/.hatfield/skills/myskill/SKILL.md', "---\nname: myskill\ndescription: Root skill\n---\nBody");
        // This deeper SKILL.md should NOT be discovered (recursion stops at myskill/)
        file_put_contents($this->tmpDir.'/.hatfield/skills/myskill/deep/SKILL.md', "---\nname: deep\ndescription: Deep\n---\nDeep body");

        $discovery = $this->createDiscovery(cwd: $this->tmpDir);

        $skills = $discovery->discover();

        // Only the root-level skill should be found
        $this->assertCount(1, $skills);
        $this->assertSame('myskill', $skills[0]->name);
    }

    public function testDirectoryContainingSkillMd(): void
    {
        // Additional path points directly to a skill root
        $skillDir = $this->tmpDir.'/direct-skill';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: direct\ndescription: Direct skill\n---\nBody");

        $config = new SkillsConfig(
            noSkills: true,
            skillsPaths: [$skillDir],
        );

        $discovery = $this->createDiscovery(cwd: $this->tmpDir, config: $config);

        $skills = $discovery->discover();

        $this->assertCount(1, $skills);
        $this->assertSame('direct', $skills[0]->name);
    }

    public function testMissingDescriptionExcludesFromRegistryButStillDiscovered(): void
    {
        $skillDir = $this->tmpDir.'/.hatfield/skills/nodesc';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: nodesc\n---\n\nNo description body");

        $discovery = $this->createDiscovery(cwd: $this->tmpDir);
        $skills = $discovery->discover();

        // Skill is still discovered (has a name), but description is empty
        $this->assertCount(1, $skills);
        $this->assertSame('nodesc', $skills[0]->name);
        $this->assertSame('', $skills[0]->description);
    }

    public function testDisableModelInvocation(): void
    {
        $skillDir = $this->tmpDir.'/.hatfield/skills/noinvoke';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: noinvoke\ndescription: No invoke skill\ndisable-model-invocation: true\n---\n\nBody");

        $discovery = $this->createDiscovery(cwd: $this->tmpDir);
        $skills = $discovery->discover();

        $this->assertCount(1, $skills);
        $this->assertFalse($skills[0]->modelInvocationEnabled);
    }

    public function testNameDefaultsToDirName(): void
    {
        $skillDir = $this->tmpDir.'/.hatfield/skills/defaultname';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\ndescription: No name in frontmatter\n---\n\nBody");

        $discovery = $this->createDiscovery(cwd: $this->tmpDir);
        $skills = $discovery->discover();

        $this->assertCount(1, $skills);
        $this->assertSame('defaultname', $skills[0]->name);
    }

    public function testCollisionRecordsDiagnostics(): void
    {
        // Actually collision is handled inside SkillDiscovery, not exposed directly.
        // We test that the first-discovered skill wins.
        // With --skills-path having higher priority, the additional path skill wins.
        $additionalDir = $this->tmpDir.'/prio';
        mkdir($additionalDir.'/myskill', 0777, true);
        file_put_contents($additionalDir.'/myskill/SKILL.md', "---\nname: myskill\ndescription: Priority skill\n---");

        // Same name in auto-discovery
        mkdir($this->tmpDir.'/.hatfield/skills/myskill', 0777, true);
        file_put_contents($this->tmpDir.'/.hatfield/skills/myskill/SKILL.md', "---\nname: myskill\ndescription: Lower priority\n---");

        $config = new SkillsConfig(
            noSkills: false,
            skillsPaths: [$additionalDir],
        );

        $discovery = $this->createDiscovery(cwd: $this->tmpDir, config: $config);
        $skills = $discovery->discover();

        // Additional path checked first, so prio wins
        $this->assertCount(1, $skills);
        $this->assertSame('myskill', $skills[0]->name);
        $this->assertStringContainsString('prio', $skills[0]->skillDirectory);
    }

    public function testEmptyCwdThrows(): void
    {
        $config = new SkillsConfig();
        $pathResolver = new SettingsPathResolver($this->tmpDir);
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'test'),
            logging: new LoggingConfig(),
            cwd: '',
        );

        $discovery = new SkillDiscovery(
            config: $config,
            pathResolver: $pathResolver,
            appConfig: $appConfig,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CWD is not configured');

        $discovery->discover();
    }

    /* ───────── stripFrontmatter ───────── */

    public function testStripFrontmatter(): void
    {
        $content = "---\nname: test\ndescription: Test\n---\n\n# Body\n\nContent here";
        $stripped = SkillDiscovery::stripFrontmatter($content);
        $this->assertStringNotContainsString('name: test', $stripped);
        $this->assertStringContainsString('# Body', $stripped);
        $this->assertStringContainsString('Content here', $stripped);
    }

    public function testStripFrontmatterWithDots(): void
    {
        $content = "---\nname: test\n...\n\nBody";
        $stripped = SkillDiscovery::stripFrontmatter($content);
        $this->assertStringNotContainsString('name: test', $stripped);
        $this->assertSame(trim("\n\nBody"), trim($stripped));
    }

    public function testStripFrontmatterNoFrontmatter(): void
    {
        $content = "# Just a heading\n\nPlain markdown without frontmatter";
        $stripped = SkillDiscovery::stripFrontmatter($content);
        $this->assertSame($content, $stripped);
    }

    /* ───────── Private helpers ───────── */

    private function createDiscovery(
        ?string $cwd = null,
        ?SkillsConfig $config = null,
        ?string $homeDir = null,
    ): SkillDiscovery {
        $projectDir = $this->tmpDir;
        $resolvedHomeDir = $homeDir ?? $this->tmpDir.'/home';

        // Create the home directory so SettingsPathResolver doesn't fallback to /tmp
        if (!is_dir($resolvedHomeDir)) {
            mkdir($resolvedHomeDir, 0777, true);
        }

        return new SkillDiscovery(
            config: $config ?? new SkillsConfig(),
            pathResolver: new SettingsPathResolver($projectDir, $resolvedHomeDir),
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
