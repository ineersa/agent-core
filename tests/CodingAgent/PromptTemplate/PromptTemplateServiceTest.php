<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\PromptsConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateArgumentParser;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateFrontmatterParser;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateLoader;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateService;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateSubstitutor;
use Ineersa\CodingAgent\Runtime\Contract\PromptTemplateCommand;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

final class PromptTemplateServiceTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private string $cwd;
    private TestLogger $logger;
    private PromptTemplateService $service;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('pt-service');
        $this->homeDir = $this->tmpDir.'/home';
        $this->cwd = $this->tmpDir.'/project';
        mkdir($this->homeDir, 0755, true);
        mkdir($this->cwd, 0755, true);

        $this->logger = new TestLogger();

        $pathResolver = new SettingsPathResolver('/app', $this->homeDir);
        $loader = new PromptTemplateLoader(
            promptsConfig: new PromptsConfig(),
            runtimeConfig: new PromptTemplatesRuntimeConfig(),
            pathResolver: $pathResolver,
            cwd: $this->cwd,
            frontmatterParser: new PromptTemplateFrontmatterParser(new MarkdownFrontmatterExtractor()),
            logger: $this->logger,
        );

        $this->service = new PromptTemplateService(
            $loader,
            new PromptTemplateArgumentParser(),
            new PromptTemplateSubstitutor(),
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    // ─── Catalog ───

    public function testCatalogReturnsNameAndDescription(): void
    {
        $this->writeFile($this->homeDir.'/.hatfield/prompts/review.md', "Review: test\n");
        $this->writeFile($this->homeDir.'/.hatfield/prompts/summarize.md', "Summarize: test\n");

        // Force fresh load
        $this->service = new PromptTemplateService(
            new PromptTemplateLoader(
                promptsConfig: new PromptsConfig(),
                runtimeConfig: new PromptTemplatesRuntimeConfig(),
                pathResolver: new SettingsPathResolver('/app', $this->homeDir),
                cwd: $this->cwd,
                frontmatterParser: new PromptTemplateFrontmatterParser(new MarkdownFrontmatterExtractor()),
                logger: $this->logger,
            ),
            new PromptTemplateArgumentParser(),
            new PromptTemplateSubstitutor(),
        );

        $commands = $this->service->allPromptTemplateCommands();
        $this->assertCount(2, $commands);
        $this->assertContainsOnlyInstancesOf(PromptTemplateCommand::class, $commands);

        $names = array_map(static fn (PromptTemplateCommand $c): string => $c->name, $commands);
        $this->assertSame(['review', 'summarize'], $names);
    }

    public function testServiceCachesLoadResult(): void
    {
        $this->writeFile($this->homeDir.'/.hatfield/prompts/test.md', "Test content.\n");

        $this->service = new PromptTemplateService(
            new PromptTemplateLoader(
                promptsConfig: new PromptsConfig(),
                runtimeConfig: new PromptTemplatesRuntimeConfig(),
                pathResolver: new SettingsPathResolver('/app', $this->homeDir),
                cwd: $this->cwd,
                frontmatterParser: new PromptTemplateFrontmatterParser(new MarkdownFrontmatterExtractor()),
                logger: $this->logger,
            ),
            new PromptTemplateArgumentParser(),
            new PromptTemplateSubstitutor(),
        );

        // Prime the cache via public catalog call — only "test" is loaded.
        $commands = $this->service->allPromptTemplateCommands();
        $this->assertCount(1, $commands);
        $this->assertSame('test', $commands[0]->name);

        // Write a second template AFTER the cache is primed.
        $this->writeFile($this->homeDir.'/.hatfield/prompts/second.md', "Second template.\n");

        // Catalog still returns only the first template (process-lifetime cache).
        $commands2 = $this->service->allPromptTemplateCommands();
        $this->assertCount(1, $commands2);

        // Expanding the second template name passthrough because it was not in cache.
        $this->assertSame('/second', $this->service->expandPromptTemplate('/second'));

        // Expanding the cached template still works.
        $this->assertStringContainsString('Test content', $this->service->expandPromptTemplate('/test'));
    }

    // ─── Expansion ───

    public function testExpandPromptTemplate(): void
    {
        $this->writeFile($this->homeDir.'/.hatfield/prompts/review.md', "Review changes with focus on:\n\$ARGUMENTS");

        $this->service = new PromptTemplateService(
            new PromptTemplateLoader(
                promptsConfig: new PromptsConfig(),
                runtimeConfig: new PromptTemplatesRuntimeConfig(),
                pathResolver: new SettingsPathResolver('/app', $this->homeDir),
                cwd: $this->cwd,
                frontmatterParser: new PromptTemplateFrontmatterParser(new MarkdownFrontmatterExtractor()),
                logger: $this->logger,
            ),
            new PromptTemplateArgumentParser(),
            new PromptTemplateSubstitutor(),
        );

        $expanded = $this->service->expandPromptTemplate('/review security performance');
        $this->assertSame("Review changes with focus on:\nsecurity performance", $expanded);
    }

    public function testNonSlashTextPassthrough(): void
    {
        $this->assertSame('hello world', $this->service->expandPromptTemplate('hello world'));
    }

    public function testNoMatchRegexPassthrough(): void
    {
        $this->assertSame('/', $this->service->expandPromptTemplate('/'));
    }

    public function testNoTemplateFoundPassthrough(): void
    {
        $this->writeFile($this->homeDir.'/.hatfield/prompts/review.md', "Review template\n");

        $this->service = new PromptTemplateService(
            new PromptTemplateLoader(
                promptsConfig: new PromptsConfig(),
                runtimeConfig: new PromptTemplatesRuntimeConfig(),
                pathResolver: new SettingsPathResolver('/app', $this->homeDir),
                cwd: $this->cwd,
                frontmatterParser: new PromptTemplateFrontmatterParser(new MarkdownFrontmatterExtractor()),
                logger: $this->logger,
            ),
            new PromptTemplateArgumentParser(),
            new PromptTemplateSubstitutor(),
        );

        $this->assertSame('/unknown', $this->service->expandPromptTemplate('/unknown'));
    }

    public function testNewlineArgs(): void
    {
        $this->writeFile($this->homeDir.'/.hatfield/prompts/echo.md', '$@');

        $this->service = new PromptTemplateService(
            new PromptTemplateLoader(
                promptsConfig: new PromptsConfig(),
                runtimeConfig: new PromptTemplatesRuntimeConfig(),
                pathResolver: new SettingsPathResolver('/app', $this->homeDir),
                cwd: $this->cwd,
                frontmatterParser: new PromptTemplateFrontmatterParser(new MarkdownFrontmatterExtractor()),
                logger: $this->logger,
            ),
            new PromptTemplateArgumentParser(),
            new PromptTemplateSubstitutor(),
        );

        // Newlines inside quotes are preserved as one argument.
        $expanded = $this->service->expandPromptTemplate("/echo \"line1\nline2\"");
        $this->assertSame("line1\nline2", $expanded);
    }

    public function testSinglePassNoRecursiveExpansion(): void
    {
        $this->writeFile($this->homeDir.'/.hatfield/prompts/first.md', '/second arg');
        $this->writeFile($this->homeDir.'/.hatfield/prompts/second.md', 'expanded: $@');

        $this->service = new PromptTemplateService(
            new PromptTemplateLoader(
                promptsConfig: new PromptsConfig(),
                runtimeConfig: new PromptTemplatesRuntimeConfig(),
                pathResolver: new SettingsPathResolver('/app', $this->homeDir),
                cwd: $this->cwd,
                frontmatterParser: new PromptTemplateFrontmatterParser(new MarkdownFrontmatterExtractor()),
                logger: $this->logger,
            ),
            new PromptTemplateArgumentParser(),
            new PromptTemplateSubstitutor(),
        );

        // first expands to "/second arg" — NOT expanded further to "expanded: arg".
        $result = $this->service->expandPromptTemplate('/first');
        $this->assertSame('/second arg', $result);
    }

    public function testExpandWithNoArgs(): void
    {
        $this->writeFile($this->homeDir.'/.hatfield/prompts/ping.md', 'pong');

        $this->service = new PromptTemplateService(
            new PromptTemplateLoader(
                promptsConfig: new PromptsConfig(),
                runtimeConfig: new PromptTemplatesRuntimeConfig(),
                pathResolver: new SettingsPathResolver('/app', $this->homeDir),
                cwd: $this->cwd,
                frontmatterParser: new PromptTemplateFrontmatterParser(new MarkdownFrontmatterExtractor()),
                logger: $this->logger,
            ),
            new PromptTemplateArgumentParser(),
            new PromptTemplateSubstitutor(),
        );

        $this->assertSame('pong', $this->service->expandPromptTemplate('/ping'));
    }

    public function testExpandPreservesArgsWithSpaces(): void
    {
        $this->writeFile($this->homeDir.'/.hatfield/prompts/greet.md', 'Hello $1!');

        $this->service = new PromptTemplateService(
            new PromptTemplateLoader(
                promptsConfig: new PromptsConfig(),
                runtimeConfig: new PromptTemplatesRuntimeConfig(),
                pathResolver: new SettingsPathResolver('/app', $this->homeDir),
                cwd: $this->cwd,
                frontmatterParser: new PromptTemplateFrontmatterParser(new MarkdownFrontmatterExtractor()),
                logger: $this->logger,
            ),
            new PromptTemplateArgumentParser(),
            new PromptTemplateSubstitutor(),
        );

        $this->assertSame('Hello World User!', $this->service->expandPromptTemplate('/greet "World User"'));
    }

    private function writeFile(string $path, string $content): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }
}
