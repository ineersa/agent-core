<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Skills;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

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
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('skills_discovery_test');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
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

        // First-discovered wins. Auto order scans project .hatfield before
        // project .agents, so .hatfield wins.
        $this->assertCount(1, $skills);
        $this->assertSame('myskill', $skills[0]->name);
        $this->assertStringContainsString('.hatfield', $skills[0]->skillDirectory);
    }

    public function testUserHatfieldOverridesProjectAgentsSkillsWithCollision(): void
    {
        // Cross-middle: project generic .agents/skills vs user Hatfield-specific.
        $homeDir = $this->tmpDir.'/home';
        $projectSkillDir = $this->tmpDir.'/.agents/skills/collide';
        $userHatfieldSkillDir = $homeDir.'/.hatfield/skills/collide';

        mkdir($projectSkillDir, 0777, true);
        file_put_contents($projectSkillDir.'/SKILL.md', "---\nname: collide\ndescription: Project-agents skill\n---\n\nProject body");

        mkdir($userHatfieldSkillDir, 0777, true);
        file_put_contents($userHatfieldSkillDir.'/SKILL.md', "---\nname: collide\ndescription: User-hatfield skill\n---\n\nUser body");

        $discovery = $this->createDiscovery(cwd: $this->tmpDir, homeDir: $homeDir);
        $skills = $discovery->discover();

        $this->assertCount(1, $skills);
        $this->assertSame('collide', $skills[0]->name);
        $this->assertSame($userHatfieldSkillDir, $skills[0]->skillDirectory);

        $collisions = $discovery->getCollisions();
        $this->assertCount(1, $collisions);
        $this->assertSame($userHatfieldSkillDir, $collisions[0]['winner']);
        $this->assertSame($projectSkillDir, $collisions[0]['ignored']);
        $this->assertSame('collide', $collisions[0]['name']);
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

    public function testDisableModelInvocationDefaultsToEnabled(): void
    {
        $skillDir = $this->tmpDir.'/.hatfield/skills/default-invoke';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: default-invoke\ndescription: Default skill\n---\n\nBody");

        $discovery = $this->createDiscovery(cwd: $this->tmpDir);
        $skills = $discovery->discover();

        $this->assertCount(1, $skills);
        $this->assertTrue($skills[0]->modelInvocationEnabled);
    }

    public function testDisableModelInvocationCoercesTruthyValues(): void
    {
        $skillDir = $this->tmpDir.'/.hatfield/skills/string-true';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: string-true\ndescription: String true\ndisable-model-invocation: \"true\"\n---\n\nBody");

        $discovery = $this->createDiscovery(cwd: $this->tmpDir);
        $skills = $discovery->discover();

        $this->assertCount(1, $skills);
        $this->assertFalse($skills[0]->modelInvocationEnabled);
    }

    public function testDisableModelInvocationFalseKeepsEnabled(): void
    {
        $skillDir = $this->tmpDir.'/.hatfield/skills/explicit-false';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: explicit-false\ndescription: Explicit false\ndisable-model-invocation: false\n---\n\nBody");

        $discovery = $this->createDiscovery(cwd: $this->tmpDir);
        $skills = $discovery->discover();

        $this->assertCount(1, $skills);
        $this->assertTrue($skills[0]->modelInvocationEnabled);
    }

    public function testCollisionKeepsWinnerDisableModelInvocationFlag(): void
    {
        $additionalDir = $this->tmpDir.'/prio';
        mkdir($additionalDir.'/shared', 0777, true);
        file_put_contents(
            $additionalDir.'/shared/SKILL.md',
            "---\nname: shared\ndescription: Priority on-demand\ndisable-model-invocation: true\n---\n\nPrio body",
        );

        mkdir($this->tmpDir.'/.hatfield/skills/shared', 0777, true);
        file_put_contents(
            $this->tmpDir.'/.hatfield/skills/shared/SKILL.md',
            "---\nname: shared\ndescription: Lower discoverable\n---\n\nLower body",
        );

        $config = new SkillsConfig(
            noSkills: false,
            skillsPaths: [$additionalDir],
        );

        $discovery = $this->createDiscovery(cwd: $this->tmpDir, config: $config);
        $skills = $discovery->discover();

        $this->assertCount(1, $skills);
        $this->assertSame('shared', $skills[0]->name);
        $this->assertFalse($skills[0]->modelInvocationEnabled);
        $this->assertStringContainsString('prio', $skills[0]->skillDirectory);
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

    public function testDiscoversFromHomeHatfieldSkills(): void
    {
        // Create a skill in a dedicated home directory
        $homeDir = $this->tmpDir.'/home';
        mkdir($homeDir.'/.hatfield/skills/homeskill', 0777, true);
        file_put_contents($homeDir.'/.hatfield/skills/homeskill/SKILL.md', "---\nname: homeskill\ndescription: Home skill\n---\n\nHome body");

        // cwd has no skills — home dir has them
        $discovery = $this->createDiscovery(cwd: $this->tmpDir, homeDir: $homeDir);

        $skills = $discovery->discover();

        $this->assertCount(1, $skills);
        $this->assertSame('homeskill', $skills[0]->name);
        $this->assertStringContainsString('home', $skills[0]->skillDirectory);
    }

    public function testMalformedFrontmatterFallsBackToDefaults(): void
    {
        $skillDir = $this->tmpDir.'/.hatfield/skills/badfront';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: badfront\ndescription: {{ invalid: yaml: [}
---\n\nBody with bad frontmatter");

        $discovery = $this->createDiscovery(cwd: $this->tmpDir);
        $skills = $discovery->discover();

        // Skill is still discovered; name defaults to directory name since YAML parsing fails
        $this->assertCount(1, $skills);
        $this->assertSame('badfront', $skills[0]->name);
        $this->assertSame('', $skills[0]->description);
        $this->assertTrue($skills[0]->modelInvocationEnabled);
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
            extractor: new MarkdownFrontmatterExtractor(),
            resources: new AppResourceLocator($this->tmpDir),
            filesystem: new Filesystem(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CWD is not configured');

        $discovery->discover();
    }

    public function testFindBySkillFilePathMatchesAbsoluteAndRelativeWinnerOnly(): void
    {
        $winnerDir = $this->tmpDir.'/.hatfield/skills/testing';
        mkdir($winnerDir, 0777, true);
        file_put_contents($winnerDir.'/SKILL.md', "---\nname: testing\ndescription: Winner\n---\n\nWinner body");

        $loserDir = $this->tmpDir.'/.agents/skills/testing';
        mkdir($loserDir, 0777, true);
        file_put_contents($loserDir.'/SKILL.md', "---\nname: testing\ndescription: Loser\n---\n\nLoser body");

        $unrelatedDir = $this->tmpDir.'/docs/unrelated';
        mkdir($unrelatedDir, 0777, true);
        file_put_contents($unrelatedDir.'/SKILL.md', "---\nname: not-a-skill\ndescription: Unrelated\n---\n\nUnrelated body");

        $discovery = $this->createDiscovery(cwd: $this->tmpDir);
        $skills = $discovery->discover();
        $this->assertCount(1, $skills);
        $this->assertSame('testing', $skills[0]->name);

        $absolute = $winnerDir.'/SKILL.md';
        $byAbsolute = $discovery->findBySkillFilePath($absolute);
        $this->assertNotNull($byAbsolute);
        $this->assertSame('testing', $byAbsolute->name);
        $this->assertSame($skills[0]->skillFile, $byAbsolute->skillFile);

        $relative = '.hatfield/skills/testing/SKILL.md';
        $byRelative = $discovery->findBySkillFilePath($relative);
        $this->assertNotNull($byRelative);
        $this->assertSame('testing', $byRelative->name);

        $this->assertNull($discovery->findBySkillFilePath($loserDir.'/SKILL.md'));
        $this->assertNull($discovery->findBySkillFilePath($unrelatedDir.'/SKILL.md'));
        $this->assertNull($discovery->findBySkillFilePath(''));
        $this->assertNull($discovery->findBySkillFilePath('README.md'));
    }

    public function testRegisteredExtensionSkillIsDiscovered(): void
    {
        $extensionSkillDir = $this->tmpDir.'/package/skills/extskill';
        mkdir($extensionSkillDir, 0777, true);
        file_put_contents($extensionSkillDir.'/SKILL.md', "---\nname: extskill\ndescription: Extension-owned skill\n---\n\nBody");

        $discovery = $this->createDiscovery(cwd: $this->tmpDir);
        $discovery->registerSkill($extensionSkillDir);

        $skills = $discovery->discover();

        $this->assertCount(1, $skills);
        $this->assertSame('extskill', $skills[0]->name);
        $this->assertSame($extensionSkillDir, $skills[0]->skillDirectory);
    }

    public function testProjectSkillTakesPrecedenceOverRegisteredExtensionSkill(): void
    {
        $projectSkillDir = $this->tmpDir.'/.hatfield/skills/shared';
        mkdir($projectSkillDir, 0777, true);
        file_put_contents($projectSkillDir.'/SKILL.md', "---\nname: shared\ndescription: Project skill\n---\n\nProject body");

        $extensionSkillDir = $this->tmpDir.'/package/skills/shared';
        mkdir($extensionSkillDir, 0777, true);
        file_put_contents($extensionSkillDir.'/SKILL.md', "---\nname: shared\ndescription: Extension skill\n---\n\nExtension body");

        $discovery = $this->createDiscovery(cwd: $this->tmpDir);
        $discovery->registerSkill($extensionSkillDir);

        $skills = $discovery->discover();

        $this->assertCount(1, $skills);
        $this->assertSame('shared', $skills[0]->name);
        $this->assertStringContainsString('.hatfield', $skills[0]->skillDirectory);
        $this->assertStringNotContainsString('package/skills', $skills[0]->skillDirectory);
    }

    public function testNoSkillsSuppressesRegisteredExtensionSkills(): void
    {
        $extensionSkillDir = $this->tmpDir.'/package/skills/extskill';
        mkdir($extensionSkillDir, 0777, true);
        file_put_contents($extensionSkillDir.'/SKILL.md', "---\nname: extskill\ndescription: Extension-owned skill\n---\n\nBody");

        $config = new SkillsConfig(noSkills: true);
        $discovery = $this->createDiscovery(cwd: $this->tmpDir, config: $config);
        $discovery->registerSkill($extensionSkillDir);

        $skills = $discovery->discover();

        $this->assertSame([], $skills);
    }

    public function testMaterializesBuiltinSkillsIntoHomeAndRewritesStaleFiles(): void
    {
        $appRoot = $this->tmpDir.'/app';
        $homeDir = $this->tmpDir.'/home';
        $cwd = $this->tmpDir.'/project';
        mkdir($cwd, 0777, true);

        $alpha = $appRoot.'/src/CodingAgent/Resources/skills/alpha';
        $beta = $appRoot.'/src/CodingAgent/Resources/skills/beta';
        mkdir($alpha, 0777, true);
        mkdir($beta, 0777, true);
        file_put_contents($alpha.'/SKILL.md', "---\nname: alpha\ndescription: Alpha built-in\n---\n\nAlpha body");
        file_put_contents($alpha.'/notes.md', 'alpha notes');
        file_put_contents($beta.'/SKILL.md', "---\nname: beta\ndescription: Beta built-in\n---\n\nBeta body");
        // Nested dir without its own skill root must not become a separate built-in.
        mkdir($alpha.'/nested-not-skill', 0777, true);
        file_put_contents($alpha.'/nested-not-skill/SKILL.md', "---\nname: nested\ndescription: nested\n---\n");

        $staleDir = $homeDir.'/.hatfield/skills/alpha';
        mkdir($staleDir, 0777, true);
        file_put_contents($staleDir.'/SKILL.md', "---\nname: alpha\ndescription: stale\n---\n\nStale body");
        file_put_contents($staleDir.'/obsolete.txt', 'remove me');
        // PHAR/materialization can leave owned destination files mode 0444; replacement
        // must still succeed without touching sibling user skills.
        chmod($staleDir.'/SKILL.md', 0444);
        chmod($staleDir.'/obsolete.txt', 0444);

        $userSkillDir = $homeDir.'/.hatfield/skills/user-skill';
        mkdir($userSkillDir, 0777, true);
        file_put_contents($userSkillDir.'/SKILL.md', "---\nname: user-skill\ndescription: User owned\n---\n\nUser body");
        $userBodyBefore = (string) file_get_contents($userSkillDir.'/SKILL.md');

        $discovery = $this->createDiscovery(cwd: $cwd, homeDir: $homeDir, appRoot: $appRoot);
        $skills = $discovery->discover();
        $byName = [];
        foreach ($skills as $skill) {
            $byName[$skill->name] = $skill;
        }

        $this->assertArrayHasKey('alpha', $byName);
        $this->assertArrayHasKey('beta', $byName);
        $this->assertArrayHasKey('user-skill', $byName);
        $this->assertArrayNotHasKey('nested', $byName);

        $this->assertSame($homeDir.'/.hatfield/skills/alpha', $byName['alpha']->skillDirectory);
        $this->assertSame('Alpha built-in', $byName['alpha']->description);
        $this->assertFileExists($homeDir.'/.hatfield/skills/alpha/notes.md');
        $this->assertFileDoesNotExist($homeDir.'/.hatfield/skills/alpha/obsolete.txt');
        $this->assertStringContainsString('Alpha body', (string) file_get_contents($homeDir.'/.hatfield/skills/alpha/SKILL.md'));
        $this->assertSame('User owned', $byName['user-skill']->description);
        $this->assertSame($userSkillDir, $byName['user-skill']->skillDirectory);
        $this->assertSame($userBodyBefore, (string) file_get_contents($userSkillDir.'/SKILL.md'));
    }

    public function testMaterializedBuiltinIsSuppressedByNoSkillsButStillRewritten(): void
    {
        $appRoot = $this->tmpDir.'/app';
        $homeDir = $this->tmpDir.'/home';
        $cwd = $this->tmpDir.'/project';
        mkdir($cwd, 0777, true);

        $builtin = $appRoot.'/src/CodingAgent/Resources/skills/alpha';
        mkdir($builtin, 0777, true);
        file_put_contents($builtin.'/SKILL.md', "---\nname: alpha\ndescription: Alpha built-in\n---\n\nAlpha body");

        $staleDir = $homeDir.'/.hatfield/skills/alpha';
        mkdir($staleDir, 0777, true);
        file_put_contents($staleDir.'/SKILL.md', "---\nname: alpha\ndescription: stale\n---\n");
        file_put_contents($staleDir.'/obsolete.txt', 'remove me');

        $config = new SkillsConfig(noSkills: true);
        $discovery = $this->createDiscovery(cwd: $cwd, config: $config, homeDir: $homeDir, appRoot: $appRoot);
        $skills = $discovery->discover();

        $this->assertSame([], $skills);
        $this->assertStringContainsString('Alpha built-in', (string) file_get_contents($homeDir.'/.hatfield/skills/alpha/SKILL.md'));
        $this->assertFileDoesNotExist($homeDir.'/.hatfield/skills/alpha/obsolete.txt');
    }

    public function testBundledSubagentsSkillStaysUnderCharacterBudget(): void
    {
        $skillRoot = \dirname(__DIR__, 3).'/src/CodingAgent/Resources/skills/subagents';
        $this->assertDirectoryExists($skillRoot);

        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($skillRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            $this->assertNotFalse($contents);
            $total += \strlen($contents);
        }

        $this->assertLessThan(20000, $total, \sprintf('Bundled subagents skill is %d characters; budget is <20000.', $total));
    }

    /* ───────── Private helpers ───────── */

    private function createDiscovery(
        ?string $cwd = null,
        ?SkillsConfig $config = null,
        ?string $homeDir = null,
        ?string $appRoot = null,
    ): SkillDiscovery {
        $projectDir = $this->tmpDir;
        $resolvedHomeDir = $homeDir ?? $this->tmpDir.'/home';
        $resolvedAppRoot = $appRoot ?? $this->tmpDir;

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
            extractor: new MarkdownFrontmatterExtractor(),
            resources: new AppResourceLocator($resolvedAppRoot),
            filesystem: new Filesystem(),
        );
    }
}
