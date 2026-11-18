<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Claude\ClaudeOmSettingsProvider;
use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

final class ClaudeOmSettingsProviderTest extends OmTestCase
{
    public function testReadsTheHatfieldLibrarianSettingsWithProjectOverridingUser(): void
    {
        $this->settingsFile('home', "observational_memory:\n            librarian: {url: 'http://user.test/_mcp', timeout_seconds: 9}");
        $this->settingsFile('project', "observational_memory:\n            librarian: {url: 'http://project.test/_mcp'}");

        $settings = (new ClaudeOmSettingsProvider($this->directory.'/home'))->settings($this->directory.'/project');

        $this->assertNotNull($settings);
        $this->assertSame('http://project.test/_mcp', $settings->librarian->url);
        $this->assertSame(9, $settings->librarian->timeoutSeconds);
    }

    public function testUnconfiguredLibrarianIsNull(): void
    {
        $this->settingsFile('project', 'task_workflow: {repository: acme/project}');

        $this->assertNull((new ClaudeOmSettingsProvider(null))->settings($this->directory.'/project'));
    }

    public function testInvalidLibrarianSettingsAreRefused(): void
    {
        $this->settingsFile('project', "observational_memory:\n            librarian: {url: 'ftp://librarian.test'}");

        $this->expectException(InvalidConfigurationException::class);
        (new ClaudeOmSettingsProvider(null))->settings($this->directory.'/project');
    }

    private function settingsFile(string $base, string $extensionSettings): void
    {
        mkdir($this->directory.'/'.$base.'/.hatfield', 0o750, true);
        file_put_contents($this->directory.'/'.$base.'/.hatfield/settings.yaml', "extensions:\n    settings:\n        ".$extensionSettings."\n");
    }
}
