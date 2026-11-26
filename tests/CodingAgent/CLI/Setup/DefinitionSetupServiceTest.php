<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Setup;

use Ineersa\CodingAgent\CLI\Setup\DefinitionSetupService;
use Ineersa\CodingAgent\CLI\Setup\Exception\DefinitionSetupRefusalException;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class DefinitionSetupServiceTest extends PerMethodIsolatedKernelTestCase
{
    protected const bool ISOLATE_HOME = true;

    public function testSkillEditPreservesBodyAndMetadataAndDeleteRevealsInherited(): void
    {
        $fs = new Filesystem();
        $project = $this->fixture($this->isolatedCwd(), '.agents/skills/shared/SKILL.md');
        $user = $this->fixture($this->isolatedHome(), '.agents/skills/shared/SKILL.md');
        $raw = "---\r\nname: shared\r\ndescription: Original\r\nmetadata: {retained: true}\r\n---\r\n\r\n  Body\r\n\r\n";
        $fs->dumpFile($project, $raw);
        $fs->dumpFile($user, $raw);
        $asset = \dirname($project).'/assets/resource.txt';
        $fs->dumpFile($asset, 'asset');
        $fs->symlink(\dirname($user), \dirname($project).'/linked-user');
        $service = self::getContainer()->get(DefinitionSetupService::class);
        $scope = SettingsLayerEnum::Project;
        $entries = $service->entries(true, $scope);
        $this->assertSame($user, $entries[$project]['reveals']);
        $this->assertSame($project, $entries[$user]['shadowedBy']);
        $this->assertFalse($entries[$user]['editable']);
        $after = $service->save(true, $scope, $project, $raw, 'description', 'Changed');
        $this->assertStringEndsWith("\r\n  Body\r\n\r\n", $after);
        $this->assertSame(['retained' => true], $service->fields($after)['metadata']);
        $this->assertSame('Changed', $service->fields($after)['description']);
        $service->delete(true, $scope, $project, $after);
        $this->assertDirectoryDoesNotExist(\dirname($project));
        $this->assertSame($raw, $fs->readFile($user));
        $this->assertNull($service->entries(true, $scope)[$user]['shadowedBy']);
    }

    public function testAgentOnlyChangesDefinitionAndRefusesWrongScope(): void
    {
        $path = $this->fixture($this->isolatedHome(), '.hatfield/agents/sample.md');
        $fs = new Filesystem();
        $raw = "---\nname: sample\ndescription: Fixture\n---\nBody\n";
        $fs->dumpFile($path, $raw);
        $service = self::getContainer()->get(DefinitionSetupService::class);
        $after = $service->save(false, SettingsLayerEnum::User, $path, $raw, 'inheritProjectContext', 'false');
        $this->assertFalse($service->fields($after)['inheritProjectContext']);
        $this->expectException(DefinitionSetupRefusalException::class);
        $service->delete(false, SettingsLayerEnum::Project, $path, $after);
    }

    public function testBuiltInSkillCanOnlyBeEditedInOwningScopeAndCannotBeDeleted(): void
    {
        $path = $this->fixture($this->isolatedHome(), '.hatfield/skills/subagents/SKILL.md');
        $raw = "---\nname: subagents\ndescription: Fixture\n---\nBody\n";
        (new Filesystem())->dumpFile($path, $raw);
        $service = self::getContainer()->get(DefinitionSetupService::class);
        $user = SettingsLayerEnum::User;
        $project = SettingsLayerEnum::Project;
        $this->assertTrue($service->entries(true, $user)[$path]['builtIn']);
        $this->assertTrue($service->entries(true, $user)[$path]['editable']);
        try {
            $service->save(true, $project, $path, $raw, 'description', 'No');
            $this->fail('Project cannot write a user skill.');
        } catch (DefinitionSetupRefusalException $error) {
            $this->assertStringContainsString('not editable in this scope', $error->getMessage());
        }
        $this->assertSame($raw, $service->read($path));
        $after = $service->save(true, $user, $path, $raw, 'description', 'Changed');
        $this->assertSame($after, $service->read($path));
        $this->expectException(DefinitionSetupRefusalException::class);
        $this->expectExceptionMessage('Built-in definitions cannot be deleted');
        $service->delete(true, $user, $path, $after);
    }

    private function fixture(string $base, string $relative): string
    {
        $this->assertSame($base, realpath($base));
        $path = $base;
        foreach (explode('/', $relative) as $segment) {
            $path .= '/'.$segment;
            $this->assertFalse(is_link($path));
            $this->assertTrue(!file_exists($path) || realpath($path) === $path);
        }

        return $path;
    }
}
