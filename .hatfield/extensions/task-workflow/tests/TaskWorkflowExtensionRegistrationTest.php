<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\HatfieldExt\TaskWorkflow\Assets\TaskWorkflowSkillInstaller;
use Ineersa\HatfieldExt\TaskWorkflow\TaskWorkflowExtension;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\TestExtensionApi;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\TransitionFixture;
use PHPUnit\Framework\TestCase;

final class TaskWorkflowExtensionRegistrationTest extends TestCase
{
    public function testRegistersGithubContractsAndConciseSharedSkillWithoutBoardDiscovery(): void
    {
        $fixture = new TransitionFixture();
        try {
            $api = new TestExtensionApi($fixture->workspace->root, ['task_workflow' => ['repository' => 'acme/project']], $fixture);
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
            $this->assertCount(0, $api->skills);
        } finally {
            $fixture->workspace->close();
        }
    }

    public function testUnconfiguredExtensionDoesNotDiscoverOrCreateLocalBoard(): void
    {
        $api = new TestExtensionApi('/not-used');
        (new TaskWorkflowExtension())->register($api);
        $this->assertSame([], $api->tools);
    }
}
