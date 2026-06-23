<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\SystemPrompt;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextDiscovery;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextRenderer;
use Ineersa\CodingAgent\SystemPrompt\LlmProxyDeterministicPromptMode;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\TemplateRenderer\StringTemplateRenderer;

/**
 * @group system-prompt
 */
final class LlmProxyDeterministicPromptContractTest extends TestCase
{
    private string $tmpDir;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = ProjectDir::get();
        $this->tmpDir = sys_get_temp_dir().'/llm_proxy_prompt_'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir.'/.hatfield', 0777, true);
        file_put_contents($this->tmpDir.'/.hatfield/APPEND_SYSTEM.md', 'LOCAL_APPEND_SHOULD_NOT_APPEAR');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    public function testDeterministicModeOmitsDateAndAppendButKeepsRealCwd(): void
    {
        $realCwd = $this->tmpDir.'/isolated-run';
        mkdir($realCwd.'/.hatfield', 0777, true);

        putenv('HATFIELD_LLM_PROXY_DETERMINISTIC=1');
        putenv('LLAMA_CPP_SMOKE_TEST=');
        try {
            $mode = new LlmProxyDeterministicPromptMode();
            $builder = new SystemPromptBuilder(
                toolRegistry: new ToolRegistry(),
                pathResolver: new SettingsPathResolver($this->projectDir),
                templateRenderer: new StringTemplateRenderer(),
                appConfig: new AppConfig(
                    tui: new TuiConfig(theme: 'test'),
                    logging: new LoggingConfig(),
                    cwd: $realCwd,
                ),
                projectDir: $this->projectDir,
                llmProxyDeterministicPromptMode: $mode,
            );

            $prompt = $builder->build();
            $this->assertStringNotContainsString(date('Y-m-d'), $prompt);
            $this->assertMatchesRegularExpression('/Current date:\s*\n/', $prompt);
            $this->assertStringContainsString($realCwd, $prompt);
            $this->assertStringNotContainsString('LOCAL_APPEND_SHOULD_NOT_APPEAR', $prompt);
            $this->assertStringNotContainsString(LlmProxyDeterministicPromptMode::FIXED_CWD, $prompt);
        } finally {
            putenv('HATFIELD_LLM_PROXY_DETERMINISTIC');
        }
    }

    public function testDeterministicAgentsContextUsesStableDisplayPath(): void
    {
        putenv('HATFIELD_LLM_PROXY_DETERMINISTIC=1');
        try {
            $mode = new LlmProxyDeterministicPromptMode();
            $discovery = new AgentsContextDiscovery(
                pathResolver: new SettingsPathResolver($this->projectDir),
                appConfig: new AppConfig(
                    tui: new TuiConfig(theme: 'test'),
                    logging: new LoggingConfig(),
                    cwd: $this->tmpDir,
                ),
                llmProxyDeterministicPromptMode: $mode,
                projectDir: $this->projectDir,
            );
            $found = $discovery->discover();
            if ([] === $found) {
                $this->markTestSkipped('No repo AGENTS.md at project root for deterministic fixture.');

                return;
            }
            $rendered = (new AgentsContextRenderer())->render($found);
            $this->assertStringContainsString(LlmProxyDeterministicPromptMode::FIXED_CWD, $rendered);
            $this->assertStringNotContainsString($this->tmpDir, $rendered);
        } finally {
            putenv('HATFIELD_LLM_PROXY_DETERMINISTIC');
        }
    }

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($path);
    }
}
