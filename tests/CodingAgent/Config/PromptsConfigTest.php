<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Tests;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\PromptsConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

final class PromptsConfigTest extends TestCase
{
    public function testDefaultIsEmpty(): void
    {
        $config = new PromptsConfig();
        $this->assertSame([], $config->paths);
    }

    public function testFromRawWithNull(): void
    {
        $config = PromptsConfig::fromRaw(null);
        $this->assertSame([], $config->paths);
    }

    public function testFromRawWithNonArray(): void
    {
        $config = PromptsConfig::fromRaw('not-an-array');
        $this->assertSame([], $config->paths);
    }

    public function testFromRawWithValidPaths(): void
    {
        $config = PromptsConfig::fromRaw(['path/to/a.md', '/absolute/b.md']);
        $this->assertSame(['path/to/a.md', '/absolute/b.md'], $config->paths);
    }

    public function testFromRawFiltersNonStringEntries(): void
    {
        $config = PromptsConfig::fromRaw(['valid.md', 42, null, ['nested'], 'also-valid.md']);
        $this->assertSame(['valid.md', 'also-valid.md'], $config->paths);
    }

    public function testFromRawFiltersBlankEntries(): void
    {
        $config = PromptsConfig::fromRaw(['  ', '', "\t", 'valid.md']);
        $this->assertSame(['valid.md'], $config->paths);
    }

    public function testFromAppConfig(): void
    {
        $promptsConfig = new PromptsConfig(['a.md', 'b.md']);
        $appConfig = new AppConfig(
            tui: new \Ineersa\CodingAgent\Config\TuiConfig('cyberpunk', []),
            logging: new \Ineersa\CodingAgent\Config\LoggingConfig('.hatfield/logs', 'info', 14),
            tools: new \Ineersa\CodingAgent\Config\ToolsConfig(),
            prompts: $promptsConfig,
        );

        $derived = PromptsConfig::fromAppConfig($appConfig);
        $this->assertSame(['a.md', 'b.md'], $derived->paths);
    }

    public function testPathResolutionViaAppConfigLoader(): void
    {
        // Prove that the PATH_CONFIG entry resolves prompt paths through
        // AppConfigLoader. We need to create a temporary layout with a
        // settings file that has prompts paths with placeholders.
        $tmpDir = TestDirectoryIsolation::createProjectTempDir('pt-config');

        try {
            $cwd = $tmpDir.'/project';
            mkdir($cwd, 0755, true);

            // Create home dir with prompts path using tilde.
            $homeDir = $tmpDir.'/home';
            mkdir($homeDir, 0755, true);
            mkdir($homeDir.'/.hatfield', 0755, true);
            file_put_contents($homeDir.'/.hatfield/settings.yaml', "prompts:\n  - '~/my-prompts'\n");

            // Defaults file with empty prompts.
            $defaultsDir = $tmpDir.'/defaults';
            mkdir($defaultsDir, 0755, true);
            file_put_contents($defaultsDir.'/hatfield.defaults.yaml', "prompts: []\n");

            $pathResolver = new SettingsPathResolver('/app', $homeDir);
            $resolver = new AppConfigLoader($pathResolver);
            $resolution = $resolver->load($defaultsDir.'/hatfield.defaults.yaml', $cwd);
            $data = $resolution->effective;

            $this->assertArrayHasKey('prompts', $data);
            $prompts = $data['prompts'];
            $this->assertCount(1, $prompts);
            // The tilde should be expanded to the home directory.
            $this->assertSame($homeDir.'/my-prompts', $prompts[0]);
        } finally {
            TestDirectoryIsolation::removeDirectory($tmpDir);
        }
    }

    public function testProjectListReplacesHomeList(): void
    {
        // Hatfield list semantics: project list replaces home list entirely.
        $tmpDir = TestDirectoryIsolation::createProjectTempDir('pt-overlay');

        try {
            $cwd = $tmpDir.'/project';
            mkdir($cwd, 0755, true);

            // Home settings with one prompt path.
            $homeDir = $tmpDir.'/home';
            mkdir($homeDir, 0755, true);
            mkdir($homeDir.'/.hatfield', 0755, true);
            file_put_contents($homeDir.'/.hatfield/settings.yaml', "prompts:\n  - '~/home-prompts'\n");

            // Project settings with a different prompt path — replaces, not merges.
            mkdir($cwd.'/.hatfield', 0755, true);
            file_put_contents($cwd.'/.hatfield/settings.yaml', "prompts:\n  - '~/proj-prompts'\n");

            $defaultsDir = $tmpDir.'/defaults';
            mkdir($defaultsDir, 0755, true);
            file_put_contents($defaultsDir.'/hatfield.defaults.yaml', "prompts: []\n");

            $pathResolver = new SettingsPathResolver('/app', $homeDir);
            $resolver = new AppConfigLoader($pathResolver);
            $resolution = $resolver->load($defaultsDir.'/hatfield.defaults.yaml', $cwd);
            $data = $resolution->effective;

            // Project list replaces home list — only proj-prompts should be present.
            $this->assertArrayHasKey('prompts', $data);
            $prompts = $data['prompts'];
            $this->assertCount(1, $prompts);
            $this->assertStringContainsString('proj-prompts', $prompts[0]);
        } finally {
            TestDirectoryIsolation::removeDirectory($tmpDir);
        }
    }
}
