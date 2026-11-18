<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\TaskWorkflow\Claude\HatfieldSettingsReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HatfieldSettingsReaderTest extends TestCase
{
    public function testProjectSettingsOverrideUserSettingsLikeHatfield(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('settings-reader');
        try {
            $this->assertSame($directory, realpath($directory));
            mkdir($directory.'/home/.hatfield', 0o750, true);
            mkdir($directory.'/project/.hatfield', 0o750, true);
            file_put_contents($directory.'/home/.hatfield/settings.yaml', "extensions:\n    settings:\n        task_workflow:\n            repository: acme/user\n            remote: upstream\n            hooks: {setup: {script: user.sh, timeout_seconds: 10}}\n        other: {enabled: true, paths: [a, b, c]}\n");
            file_put_contents($directory.'/project/.hatfield/settings.yaml', "extensions:\n    settings:\n        task_workflow:\n            repository: acme/project\n        other: {paths: [z]}\n");

            $settings = (new HatfieldSettingsReader($directory.'/home'))->extensionSettings($directory.'/project');

            $this->assertSame(['repository' => 'acme/project', 'remote' => 'upstream', 'hooks' => ['setup' => ['script' => 'user.sh', 'timeout_seconds' => 10]]], $settings['task_workflow']);
            // A higher layer's list replaces the lower list whole, as in Hatfield.
            $this->assertSame(['enabled' => true, 'paths' => ['z']], $settings['other']);
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function projectResets(): iterable
    {
        yield 'empty map' => ['{}'];
        yield 'null' => ['null'];
    }

    #[DataProvider('projectResets')]
    public function testProjectResetOfTheSettingsNodeDropsInheritedSettings(string $reset): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('settings-reader');
        try {
            mkdir($directory.'/home/.hatfield', 0o750, true);
            mkdir($directory.'/project/.hatfield', 0o750, true);
            file_put_contents($directory.'/home/.hatfield/settings.yaml', "extensions:\n    settings:\n        task_workflow: {repository: acme/user}\n");
            file_put_contents($directory.'/project/.hatfield/settings.yaml', "extensions:\n    settings: {$reset}\n");

            $this->assertSame([], (new HatfieldSettingsReader($directory.'/home'))->extensionSettings($directory.'/project'));
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testMissingFilesYieldNoSettings(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('settings-reader');
        try {
            $this->assertSame([], (new HatfieldSettingsReader(null))->extensionSettings($directory));
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }
}
