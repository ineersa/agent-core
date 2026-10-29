<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\TaskWorkflow\Assets\TaskWorkflowMarkdownFrontmatter;
use Ineersa\HatfieldExt\TaskWorkflow\Assets\TaskWorkflowSkillInstaller;
use Ineersa\HatfieldExt\TaskWorkflow\TaskWorkflowExtension;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\TestExtensionApi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TaskWorkflowExtensionRegistrationTest extends TestCase
{
    private string $projectDir;
    private string|false $previousTaskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTaskRoot = getenv('HATFIELD_TASK_WORKFLOW_ROOT');
        putenv('HATFIELD_TASK_WORKFLOW_ROOT');
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('task-workflow-reg-');
    }

    protected function tearDown(): void
    {
        putenv(false === $this->previousTaskRoot ? 'HATFIELD_TASK_WORKFLOW_ROOT' : 'HATFIELD_TASK_WORKFLOW_ROOT='.$this->previousTaskRoot);
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

    #[Test]
    #[DataProvider('boardSources')]
    public function promptUsesActiveCheckoutAndResolvedBoardWithoutBakingPaths(string $source): void
    {
        $installedGuidance = null;
        foreach (['finance', 'other-project'] as $project) {
            $cwd = $this->projectDir.'/'.$project;
            TestDirectoryIsolation::ensureDirectory($cwd);
            $configured = $this->projectDir.'/'.$project.'-configured-board';
            $board = match ($source) {
                'environment' => $this->projectDir.'/'.$project.'-env-board',
                'sibling' => $cwd.'-tasks',
                default => $configured,
            };
            TestDirectoryIsolation::ensureDirectory($board.'/TODO');
            putenv('environment' === $source ? 'HATFIELD_TASK_WORKFLOW_ROOT='.$board : 'HATFIELD_TASK_WORKFLOW_ROOT');
            $api = new TestExtensionApi($cwd, [
                'task_workflow' => 'sibling' === $source ? [] : ['task_root' => $configured],
            ]);

            (new TaskWorkflowExtension())->register($api);

            $prompt = $api->prompts[0]->contribute();
            $this->assertStringContainsString('Code checkout (current working directory): `'.$cwd.'`.', $prompt);
            $this->assertStringContainsString('Resolved external task board: `'.$board.'`.', $prompt);
            if ('environment' === $source) {
                $this->assertStringNotContainsString($configured, $prompt);
            }
            $guidance = (string) file_get_contents($cwd.'/.hatfield/skills/task-workflow/references/board.md');
            $this->assertStringNotContainsString($this->projectDir, $guidance);
            $this->assertStringNotContainsString('/home/ineersa/projects/agent-core', $guidance);
            $this->assertSame($installedGuidance ?? $guidance, $guidance);
            $installedGuidance = $guidance;
        }
    }

    #[Test]
    public function changedBoardSettingsRefreshPromptWithoutChangingInstalledGuidance(): void
    {
        $guidancePath = $this->projectDir.'/.hatfield/skills/task-workflow/references/board.md';
        $guidance = null;
        foreach (['first-board', 'replacement-board'] as $name) {
            $board = $this->projectDir.'/'.$name;
            $api = new TestExtensionApi($this->projectDir, ['task_workflow' => ['task_root' => $board]]);

            (new TaskWorkflowExtension())->register($api);

            $this->assertStringContainsString('Resolved external task board: `'.$board.'`.', $api->prompts[0]->contribute());
            $currentGuidance = (string) file_get_contents($guidancePath);
            $this->assertSame($guidance ?? $currentGuidance, $currentGuidance);
            $this->assertStringNotContainsString($board, $currentGuidance);
            $guidance = $currentGuidance;
        }
    }

    public static function boardSources(): iterable
    {
        yield 'settings' => ['settings'];
        yield 'environment overrides settings' => ['environment'];
        yield 'sibling discovery' => ['sibling'];
    }
}
