<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\TaskWorkflow\Assets\TaskWorkflowMarkdownFrontmatter;
use Ineersa\HatfieldExt\TaskWorkflow\Assets\TaskWorkflowSkillInstaller;
use Ineersa\HatfieldExt\TaskWorkflow\TaskWorkflowExtension;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\TestExtensionApi;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaskWorkflowExtensionRegistrationTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('task-workflow-reg-');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function registerInstallsProjectSkillWithoutDuplicateRegistration(): void
    {
        $api = new TestExtensionApi($this->projectDir, [
            'task_workflow' => [
                'task_root' => $this->projectDir.'/tasks',
            ],
        ]);

        (new TaskWorkflowExtension())->register($api);

        $installed = $this->projectDir.'/.hatfield/skills/'.TaskWorkflowSkillInstaller::SKILL_NAME.'/SKILL.md';
        $this->assertFileExists($installed);
        $this->assertNotNull(TaskWorkflowMarkdownFrontmatter::versionOf((string) file_get_contents($installed)));
        $this->assertFileExists($this->projectDir.'/.hatfield/skills/'.TaskWorkflowSkillInstaller::SKILL_NAME.'/references/implementation-ownership.md');

        $this->assertCount(0, $api->skills);
        $this->assertGreaterThanOrEqual(4, \count($api->tools));
        $this->assertCount(1, $api->prompts);
        $this->assertGreaterThanOrEqual(5, \count($api->commands));
    }
}
