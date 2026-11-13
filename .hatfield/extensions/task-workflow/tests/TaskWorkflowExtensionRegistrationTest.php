<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\HatfieldExt\TaskWorkflow\Assets\TaskWorkflowSkillInstaller;
use Ineersa\HatfieldExt\TaskWorkflow\TaskWorkflowExtension;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\TestExtensionApi;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\TransitionFixture;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class TaskWorkflowExtensionRegistrationTest extends TestCase
{
    public function testRegistersGithubContractsAndConciseSharedSkillWithoutBoardDiscovery(): void
    {
        $fixture = new TransitionFixture();
        try {
            $linked = $this->linkedWorktree($fixture->workspace);
            $api = new TestExtensionApi($linked, ['task_workflow' => ['repository' => 'acme/project']], $fixture);
            (new TaskWorkflowExtension())->register($api);
            $this->assertSame(['task_list', 'create_task', 'read_task', 'update_task', 'move_task'], array_column($api->tools, 'name'));
            foreach ($api->tools as $tool) {
                $properties = $tool->parametersJsonSchema['properties'];
                foreach (['forkRun', 'summary', 'validation', 'workLog', 'prStatus', 'task_root', 'include_archive', 'cleanupWorktree'] as $removed) {
                    $this->assertArrayNotHasKey($removed, $properties);
                }
            }
            $create = $api->tools[1]->parametersJsonSchema;
            $this->assertSame(['title', 'body'], $create['required']);
            $move = $api->tools[4]->parametersJsonSchema;
            $this->assertNotContains('ARCHIVE', $move['properties']['to']['enum']);
            $this->assertContains('tasks-init', array_column($api->commands, 'name'));
            $this->assertStringContainsString('acme/project', $api->prompts[0]->contribute());
            $this->assertStringNotContainsString('external task board', $api->prompts[0]->contribute());
            $skill = $fixture->workspace->root.'/.hatfield/skills/'.TaskWorkflowSkillInstaller::SKILL_NAME.'/SKILL.md';
            $this->assertSame(file_get_contents(\dirname(__DIR__).'/skills/task-workflow/SKILL.md'), file_get_contents($skill));
            $this->assertStringNotContainsString($fixture->workspace->root, file_get_contents($skill));
            $this->assertSame([\dirname($skill)], $api->skills);
            $this->assertDirectoryDoesNotExist($linked.'/.hatfield/skills/task-workflow');
        } finally {
            $fixture->workspace->close();
        }
    }

    public function testUnconfiguredExtensionInstallsSetupGuidanceWithoutTaskTools(): void
    {
        $workspace = new WorkspaceFixture();
        try {
            // Installer writes must stay inside the owned nonsymlink fixture tree.
            $this->assertSame($workspace->root, realpath($workspace->root));
            $this->assertStringStartsWith(realpath($workspace->directory).'/', $workspace->root);
            $this->assertFalse(is_link($workspace->root.'/.hatfield'));
            $linked = $this->linkedWorktree($workspace);
            $api = new TestExtensionApi($linked, exec: $workspace);
            (new TaskWorkflowExtension())->register($api);
            $skill = $workspace->root.'/.hatfield/skills/'.TaskWorkflowSkillInstaller::SKILL_NAME.'/SKILL.md';
            $this->assertFileExists($skill);
            $content = file_get_contents($skill);
            $this->assertStringContainsString('Set up this project', $content);
            $this->assertStringContainsString('set up task workflow', $content);
            $this->assertSame([], $api->tools);
            $this->assertSame([], $api->commands);
            $this->assertSame([], $api->prompts);
            $this->assertSame([\dirname($skill)], $api->skills);
            $localSkill = $linked.'/.hatfield/skills/task-workflow';
            $this->assertDirectoryDoesNotExist($localSkill);

            // An older managed auto-discovery copy outranks the registered path.
            (new Filesystem())->mkdir($localSkill);
            $this->assertSame($localSkill, realpath($localSkill));
            $this->assertStringStartsWith(realpath($workspace->directory).'/', realpath($localSkill).'/');
            file_put_contents($localSkill.'/SKILL.md', "---\nname: task-workflow\ndescription: stale\nversion: 2.2.0\n---\nstale\n");
            (new TaskWorkflowExtension())->register(new TestExtensionApi($linked, exec: $workspace));
            $this->assertSame($content, file_get_contents($localSkill.'/SKILL.md'));
            $this->assertSame(file_get_contents(\dirname($skill).'/README.md'), file_get_contents($localSkill.'/README.md'));
        } finally {
            $workspace->close();
        }
    }

    public function testRefreshesOlderManagedSkillAtNestedWorktreeCwd(): void
    {
        $workspace = new WorkspaceFixture();
        try {
            $linked = $this->linkedWorktree($workspace);
            $cwd = $linked.'/src';
            $localSkill = $cwd.'/.hatfield/skills/task-workflow';
            (new Filesystem())->mkdir($localSkill);
            $this->assertSame($localSkill, realpath($localSkill));
            $this->assertStringStartsWith(realpath($workspace->directory).'/', realpath($localSkill).'/');
            file_put_contents($localSkill.'/SKILL.md', "---\nname: task-workflow\ndescription: stale\nversion: 2.2.0\n---\nstale\n");

            $api = new TestExtensionApi($cwd, exec: $workspace);
            (new TaskWorkflowExtension())->register($api);

            $this->assertSame(file_get_contents(\dirname(__DIR__).'/skills/task-workflow/SKILL.md'), file_get_contents($localSkill.'/SKILL.md'));
            $this->assertFileExists($localSkill.'/README.md');
            $this->assertSame([$workspace->root.'/.hatfield/skills/task-workflow'], $api->skills);
            $this->assertDirectoryDoesNotExist($linked.'/.hatfield/skills/task-workflow');
        } finally {
            $workspace->close();
        }
    }

    private function linkedWorktree(WorkspaceFixture $workspace): string
    {
        $this->assertSame($workspace->directory, realpath($workspace->directory));
        $this->assertSame($workspace->root, realpath($workspace->root));
        $linked = $workspace->directory.'/linked';
        $workspace->git->gitOk(['worktree', 'add', '-b', 'linked', $linked], $workspace->root);
        $this->assertSame($linked, realpath($linked));

        return $linked;
    }
}
