<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI;

use Ineersa\CodingAgent\CLI\ProjectGitignoreInitializer;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectGitignoreInitializerTest extends TestCase
{
    #[Test]
    public function freshProjectGetsRuntimeIgnoreRules(): void
    {
        $cwd = TestDirectoryIsolation::createProjectTempDir('project-gitignore');

        try {
            (new ProjectGitignoreInitializer())($cwd);

            $rules = file_get_contents($cwd.'/.hatfield/.gitignore');
            $this->assertIsString($rules);
            $this->assertStringContainsString("sessions/\n", $rules);
            $this->assertStringContainsString("tmp/\n", $rules);
            $this->assertStringContainsString("cache-*/\n", $rules);
            $this->assertStringContainsString("state.sqlite*\n", $rules);
            $this->assertStringNotContainsString("\nsettings.yaml\n", $rules);
        } finally {
            TestDirectoryIsolation::removeDirectory($cwd);
        }
    }

    #[Test]
    public function existingProjectIgnoreRulesAreNotReplaced(): void
    {
        $cwd = TestDirectoryIsolation::createProjectTempDir('project-gitignore');

        try {
            TestDirectoryIsolation::createHatfieldTree($cwd);
            $path = $cwd.'/.hatfield/.gitignore';
            file_put_contents($path, "my-private-file\n");

            (new ProjectGitignoreInitializer())($cwd);

            $this->assertSame("my-private-file\n", file_get_contents($path));
        } finally {
            TestDirectoryIsolation::removeDirectory($cwd);
        }
    }
}
