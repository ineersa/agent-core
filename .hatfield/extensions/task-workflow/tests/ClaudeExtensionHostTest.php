<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;
use Ineersa\HatfieldExt\TaskWorkflow\Claude\ClaudeExtensionHost;
use Ineersa\HatfieldExt\TaskWorkflow\Exception\UnsupportedHostCapabilityException;
use PHPUnit\Framework\TestCase;

final class ClaudeExtensionHostTest extends TestCase
{
    public function testExposesSettingsAndJoinsPromptContributions(): void
    {
        $host = new ClaudeExtensionHost('/project', ['task_workflow' => ['repository' => 'acme/project']], new StubExec());
        foreach (["\n\n## First\n", 'Second  '] as $text) {
            $host->registerPromptContributor(new class($text) implements PromptContributorInterface {
                public function __construct(private string $text)
                {
                }

                public function contribute(): string
                {
                    return $this->text;
                }
            });
        }

        $this->assertSame(['repository' => 'acme/project'], $host->getSettings('task_workflow'));
        $this->assertSame([], $host->getSettings('missing'));
        $this->assertSame('/project', $host->getCwd());
        $this->assertSame("## First\n\nSecond", $host->instructions());
    }

    public function testRefusesCapabilitiesItDoesNotProvide(): void
    {
        $host = new ClaudeExtensionHost('/project', [], new StubExec());

        $this->expectException(UnsupportedHostCapabilityException::class);
        $this->expectExceptionMessage('"registerSkill"');
        $host->registerSkill('/skills/task-workflow');
    }
}
