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
use Symfony\Component\Finder\Finder;

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

        $this->assertSame($this->treeContents($this->packageRoot.'/skills/task-workflow'), $this->treeContents($this->destinationDir()));
        $this->assertSame($this->bundledSkillVersion(), TaskWorkflowMarkdownFrontmatter::versionOf((string) file_get_contents($this->destinationDir().'/SKILL.md')));
        $this->assertDirectoryDoesNotExist($this->destinationDir().'/references');
    }

    #[Test]
    #[DataProvider('outdatedVersions')]
    public function reinstallsWholeSkillTreeWhenInstalledVersionMissingOrDifferent(string $versionLine): void
    {
        $dest = $this->destinationDir();
        TestDirectoryIsolation::ensureDirectory($dest.'/references');
        $this->assertStringStartsWith(realpath($this->root).'/', realpath($dest).'/');
        file_put_contents($dest.'/SKILL.md', "---\nname: task-workflow\ndescription: stale\n".$versionLine."---\nstale body\n");
        foreach (['board', 'implementation-ownership', 'specification-fidelity', 'task-done', 'task-explain', 'task-review-iterate', 'task-start', 'task-to-pr', 'tui-proof', 'stale'] as $reference) {
            file_put_contents($dest.'/references/'.$reference.'.md', "stale reference\n");
        }

        $this->installer()->install();

        $this->assertSame($this->treeContents($this->packageRoot.'/skills/task-workflow'), $this->treeContents($dest));
        $this->assertSame($this->bundledSkillVersion(), TaskWorkflowMarkdownFrontmatter::versionOf((string) file_get_contents($dest.'/SKILL.md')));
        $this->assertDirectoryDoesNotExist($dest.'/references');
    }

    #[Test]
    public function leavesSameVersionSkillTreeUntouched(): void
    {
        $dest = $this->destinationDir();
        TestDirectoryIsolation::ensureDirectory($dest.'/references');
        $this->assertStringStartsWith(realpath($this->root).'/', realpath($dest).'/');
        $customSkill = "---\nname: task-workflow\ndescription: custom\nversion: ".$this->bundledSkillVersion()."\n---\ncustom body\n";
        file_put_contents($dest.'/SKILL.md', $customSkill);
        file_put_contents($dest.'/references/custom.md', "custom guidance\n");
        $before = $this->treeContents($dest);

        $this->installer()->install();

        $this->assertSame($before, $this->treeContents($dest));
    }

    public static function outdatedVersions(): iterable
    {
        yield 'missing' => [''];
        yield 'different' => ["version: 1.0.0\n"];
        yield 'previous ownership guidance' => ["version: 1.1.5\n"];
        yield 'previous planning guidance' => ["version: 1.1.6\n"];
        yield 'previous multi-file skill' => ["version: 1.1.7\n"];
        yield 'malformed' => ["version: [\n"];
    }

    /** @return array<string, string> */
    private function treeContents(string $directory): array
    {
        $contents = [];
        foreach (Finder::create()->files()->in($directory) as $file) {
            $contents[$file->getRelativePathname()] = $file->getContents();
        }
        ksort($contents);

        return $contents;
    }

    private function installer(): TaskWorkflowSkillInstaller
    {
        return new TaskWorkflowSkillInstaller($this->projectDir, $this->packageRoot, new TestLogger());
    }

    private function destinationDir(): string
    {
        return $this->projectDir.'/.hatfield/skills/'.TaskWorkflowSkillInstaller::SKILL_NAME;
    }

    private function bundledSkillVersion(): string
    {
        $version = TaskWorkflowMarkdownFrontmatter::versionOf((string) file_get_contents($this->packageRoot.'/skills/task-workflow/SKILL.md'));
        $this->assertNotNull($version);

        return $version;
    }
}
