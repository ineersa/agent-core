<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\TaskWorkflow\Assets\TaskWorkflowMarkdownFrontmatter;
use Ineersa\HatfieldExt\TaskWorkflow\Assets\TaskWorkflowSkillInstaller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaskWorkflowSkillInstallerTest extends TestCase
{
    private string $root;
    private string $projectDir;
    private string $packageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = TestDirectoryIsolation::createOsTempDir('task-workflow-assets-');
        $this->projectDir = $this->root.'/project';
        TestDirectoryIsolation::ensureDirectory($this->projectDir);
        $this->packageRoot = \dirname(__DIR__);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->root);
        parent::tearDown();
    }

    #[Test]
    public function installsBundledSkillTreeWhenAbsent(): void
    {
        $this->installer()->install();

        $skill = $this->destinationDir().'/SKILL.md';
        $this->assertFileExists($skill);
        $this->assertSame($this->bundledSkillVersion(), TaskWorkflowMarkdownFrontmatter::versionOf((string) file_get_contents($skill)));
        $this->assertFileExists($this->destinationDir().'/references/implementation-ownership.md');
        $this->assertStringContainsString('Tracked-work fork resume', (string) file_get_contents($skill));
    }

    #[Test]
    #[DataProvider('outdatedVersions')]
    public function reinstallsWholeSkillTreeWhenInstalledVersionMissingOrDifferent(string $versionLine): void
    {
        $dest = $this->destinationDir();
        mkdir($dest.'/references', 0o777, true);
        file_put_contents($dest.'/SKILL.md', "---\nname: task-workflow\ndescription: stale\n".$versionLine."---\nstale body\n");
        file_put_contents($dest.'/references/stale.md', "stale reference\n");
        file_put_contents($dest.'/references/implementation-ownership.md', "old ownership\n");

        $this->installer()->install();

        $installed = (string) file_get_contents($dest.'/SKILL.md');
        $this->assertSame($this->bundledSkillVersion(), TaskWorkflowMarkdownFrontmatter::versionOf($installed));
        $this->assertStringContainsString('Tracked-work fork resume', $installed);
        $this->assertFileExists($dest.'/references/implementation-ownership.md');
        $this->assertStringContainsString('Resuming a tracked fork', (string) file_get_contents($dest.'/references/implementation-ownership.md'));
        $this->assertFileDoesNotExist($dest.'/references/stale.md');
    }

    #[Test]
    public function leavesSameVersionSkillTreeUntouched(): void
    {
        $dest = $this->destinationDir();
        mkdir($dest.'/references', 0o777, true);
        $customSkill = "---\nname: task-workflow\ndescription: custom\nversion: ".$this->bundledSkillVersion()."\n---\ncustom body\n";
        $customReference = "custom ownership\n";
        file_put_contents($dest.'/SKILL.md', $customSkill);
        file_put_contents($dest.'/references/implementation-ownership.md', $customReference);

        $this->installer()->install();

        $this->assertSame($customSkill, file_get_contents($dest.'/SKILL.md'));
        $this->assertSame($customReference, file_get_contents($dest.'/references/implementation-ownership.md'));
    }

    public static function outdatedVersions(): iterable
    {
        yield 'missing' => [''];
        yield 'different' => ["version: 1.0.0\n"];
        yield 'malformed' => ["version: [\n"];
    }

    private function installer(?TestLogger $logger = null): TaskWorkflowSkillInstaller
    {
        return new TaskWorkflowSkillInstaller(
            $this->projectDir,
            $this->packageRoot,
            $logger ?? new TestLogger(),
        );
    }

    private function destinationDir(): string
    {
        return $this->projectDir.'/.hatfield/skills/'.TaskWorkflowSkillInstaller::SKILL_NAME;
    }

    private function bundledSkillVersion(): string
    {
        $bundled = (string) file_get_contents($this->packageRoot.'/skills/task-workflow/SKILL.md');
        $version = TaskWorkflowMarkdownFrontmatter::versionOf($bundled);
        $this->assertNotNull($version);

        return $version;
    }
}
