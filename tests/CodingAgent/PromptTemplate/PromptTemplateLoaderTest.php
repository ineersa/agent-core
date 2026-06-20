<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\PromptTemplate\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\PromptsConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateFrontmatterParser;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateLoader;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

final class PromptTemplateLoaderTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private string $cwd;
    private SettingsPathResolver $pathResolver;
    private TestLogger $logger;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('pt-loader');
        $this->homeDir = $this->tmpDir.'/home';
        $this->cwd = $this->tmpDir.'/project';
        mkdir($this->homeDir, 0755, true);
        mkdir($this->cwd, 0755, true);

        $this->pathResolver = new SettingsPathResolver('/app', $this->homeDir);
        $this->logger = new TestLogger();
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    private function createLoader(?PromptsConfig $promptsConfig = null, ?PromptTemplatesRuntimeConfig $runtimeConfig = null): PromptTemplateLoader
    {
        return new PromptTemplateLoader(
            promptsConfig: $promptsConfig ?? new PromptsConfig(),
            runtimeConfig: $runtimeConfig ?? new PromptTemplatesRuntimeConfig(),
            pathResolver: $this->pathResolver,
            cwd: $this->cwd,
            frontmatterParser: new PromptTemplateFrontmatterParser(),
            logger: $this->logger,
        );
    }

    private function writeFile(string $path, string $content): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }

    // ─── Auto-discovery directories ───

    public function testAutoGlobalDirectory(): void
    {
        $this->writeFile($this->homeDir.'/.hatfield/prompts/review.md', "Review the code.\n");
        $loader = $this->createLoader();
        $result = $loader->load();

        self::assertCount(1, $result->templates);
        self::assertSame('review', $result->templates[0]->name);
        self::assertSame("Review the code.\n", $result->templates[0]->content);
        self::assertEmpty($result->diagnostics);
    }

    public function testAutoProjectDirectory(): void
    {
        $this->writeFile($this->cwd.'/.hatfield/prompts/summarize.md', "Summarize this.\n");
        $loader = $this->createLoader();
        $result = $loader->load();

        self::assertCount(1, $result->templates);
        self::assertSame('summarize', $result->templates[0]->name);
    }

    public function testAutoDiscoveryOrderGlobalFirst(): void
    {
        // Same name in both global and project — global wins (loaded first).
        $this->writeFile($this->homeDir.'/.hatfield/prompts/review.md', "Global review.\n");
        $this->writeFile($this->cwd.'/.hatfield/prompts/review.md', "Project review.\n");
        $loader = $this->createLoader();
        $result = $loader->load();

        self::assertCount(1, $result->templates);
        self::assertSame("Global review.\n", $result->templates[0]->content);
        self::assertCount(1, $result->diagnostics);
        self::assertSame('collision', $result->diagnostics[0]->type);
    }

    // ─── Settings explicit paths ───

    public function testSettingsExplicitFile(): void
    {
        $filePath = $this->tmpDir.'/custom.md';
        $this->writeFile($filePath, "Custom template body.\n");
        $loader = $this->createLoader(new PromptsConfig([$filePath]));
        $result = $loader->load();

        self::assertCount(1, $result->templates);
        self::assertSame('custom', $result->templates[0]->name);
    }

    public function testSettingsExplicitDirectory(): void
    {
        $dir = $this->tmpDir.'/team-prompts';
        $this->writeFile($dir.'/alpha.md', "Alpha template.\n");
        $this->writeFile($dir.'/beta.md', "Beta template.\n");
        $loader = $this->createLoader(new PromptsConfig([$dir]));
        $result = $loader->load();

        self::assertCount(2, $result->templates);
        $names = array_map(fn ($t) => $t->name, $result->templates);
        // Sorted lexically.
        self::assertSame(['alpha', 'beta'], $names);
    }

    // ─── CLI explicit paths ───

    public function testCliExplicitFile(): void
    {
        $filePath = $this->tmpDir.'/cli-template.md';
        $this->writeFile($filePath, "CLI template.\n");
        $runtimeConfig = new PromptTemplatesRuntimeConfig();
        $runtimeConfig->promptTemplatePaths = [$filePath];
        $loader = $this->createLoader(runtimeConfig: $runtimeConfig);
        $result = $loader->load();

        self::assertCount(1, $result->templates);
        self::assertSame('cli-template', $result->templates[0]->name);
    }

    public function testCliExplicitDirectory(): void
    {
        $dir = $this->tmpDir.'/cli-prompts';
        $this->writeFile($dir.'/quick.md', "Quick prompt.\n");
        $runtimeConfig = new PromptTemplatesRuntimeConfig();
        $runtimeConfig->promptTemplatePaths = [$dir];
        $loader = $this->createLoader(runtimeConfig: $runtimeConfig);
        $result = $loader->load();

        self::assertCount(1, $result->templates);
        self::assertSame('quick', $result->templates[0]->name);
    }

    // ─── noPromptTemplates ───

    public function testNoPromptTemplatesSkipsAutoAndSettings(): void
    {
        $this->writeFile($this->homeDir.'/.hatfield/prompts/home.md', "Home.\n");
        $this->writeFile($this->cwd.'/.hatfield/prompts/proj.md', "Project.\n");
        $settingsFile = $this->tmpDir.'/settings.md';
        $this->writeFile($settingsFile, "Settings.\n");

        $runtimeConfig = new PromptTemplatesRuntimeConfig();
        $runtimeConfig->noPromptTemplates = true;
        $loader = $this->createLoader(
            new PromptsConfig([$settingsFile]),
            $runtimeConfig,
        );
        $result = $loader->load();

        // Auto and settings paths are skipped.
        self::assertEmpty($result->templates);
    }

    public function testNoPromptTemplatesStillLoadsCliPaths(): void
    {
        $cliFile = $this->tmpDir.'/cli.md';
        $this->writeFile($cliFile, "CLI.\n");

        $runtimeConfig = new PromptTemplatesRuntimeConfig();
        $runtimeConfig->noPromptTemplates = true;
        $runtimeConfig->promptTemplatePaths = [$cliFile];
        $loader = $this->createLoader(runtimeConfig: $runtimeConfig);
        $result = $loader->load();

        self::assertCount(1, $result->templates);
        self::assertSame('cli', $result->templates[0]->name);
    }

    // ─── Non-recursive scanning ───

    public function testNonRecursiveScanning(): void
    {
        $dir = $this->tmpDir.'/prompts';
        $this->writeFile($dir.'/top.md', "Top.\n");
        $this->writeFile($dir.'/sub/nested.md', "Nested.\n");
        $loader = $this->createLoader(new PromptsConfig([$dir]));
        $result = $loader->load();

        self::assertCount(1, $result->templates);
        self::assertSame('top', $result->templates[0]->name);
    }

    // ─── Exact .md suffix only ───

    public function testOnlyExactMdSuffix(): void
    {
        $dir = $this->tmpDir.'/prompts';
        $this->writeFile($dir.'/valid.md', "Valid.\n");
        $this->writeFile($dir.'/not-md.txt', "Not MD.\n");
        $this->writeFile($dir.'/noextension', "No ext.\n");
        $loader = $this->createLoader(new PromptsConfig([$dir]));
        $result = $loader->load();

        self::assertCount(1, $result->templates);
        self::assertSame('valid', $result->templates[0]->name);
    }

    // ─── Missing dirs/paths ───

    public function testMissingAutoDirsAreQuiet(): void
    {
        // No .hatfield/prompts directories exist — no diagnostics.
        $loader = $this->createLoader();
        $result = $loader->load();

        self::assertEmpty($result->templates);
        self::assertEmpty($result->diagnostics);
    }

    public function testMissingExplicitPathProducesDiagnostic(): void
    {
        $loader = $this->createLoader(new PromptsConfig(['/nonexistent/path.md']));
        $result = $loader->load();

        self::assertEmpty($result->templates);
        self::assertCount(1, $result->diagnostics);
        self::assertSame('invalid_path', $result->diagnostics[0]->type);
        self::assertSame('/nonexistent/path.md', $result->diagnostics[0]->path);
    }

    // ─── Description fallback ───

    public function testDescriptionFromFrontmatter(): void
    {
        $filePath = $this->tmpDir.'/with-desc.md';
        $this->writeFile($filePath, "---\ndescription: My description\n---\n\nBody text.\n");
        $loader = $this->createLoader(new PromptsConfig([$filePath]));
        $result = $loader->load();

        self::assertSame('My description', $result->templates[0]->description);
    }

    public function testDescriptionFallbackFirstNonEmptyLine(): void
    {
        $filePath = $this->tmpDir.'/no-desc.md';
        $this->writeFile($filePath, "\n\nReview the staged changes carefully.\nSecond line.\n");
        $loader = $this->createLoader(new PromptsConfig([$filePath]));
        $result = $loader->load();

        self::assertSame('Review the staged changes carefully.', $result->templates[0]->description);
    }

    public function testDescriptionTruncatedAtSixtyChars(): void
    {
        $filePath = $this->tmpDir.'/long-line.md';
        $this->writeFile($filePath, str_repeat('x', 80)."\n");
        $loader = $this->createLoader(new PromptsConfig([$filePath]));
        $result = $loader->load();

        self::assertSame(63, \mb_strlen($result->templates[0]->description));
        self::assertStringEndsWith('...', $result->templates[0]->description);
    }

    // ─── Lowercase canonicalization ───

    public function testFilenameLowercaseCanonicalization(): void
    {
        $filePath = $this->tmpDir.'/MyTemplate.md';
        $this->writeFile($filePath, "Mixed case filename.\n");
        $loader = $this->createLoader(new PromptsConfig([$filePath]));
        $result = $loader->load();

        self::assertCount(1, $result->templates);
        self::assertSame('mytemplate', $result->templates[0]->name);
    }

    public function testMixedCaseCollision(): void
    {
        // Review.md and review.md produce the same lowercase name 'review'.
        $file1 = $this->tmpDir.'/Review.md';
        $file2 = $this->tmpDir.'/review.md';
        $this->writeFile($file1, "First.\n");
        $this->writeFile($file2, "Second.\n");
        $loader = $this->createLoader(new PromptsConfig([$file1, $file2]));
        $result = $loader->load();

        self::assertCount(1, $result->templates);
        self::assertSame('review', $result->templates[0]->name);
        self::assertCount(1, $result->diagnostics);
        self::assertSame('collision', $result->diagnostics[0]->type);
        self::assertSame($file1, $result->diagnostics[0]->winnerPath);
        self::assertSame($file2, $result->diagnostics[0]->loserPath);
    }

    // ─── Unknown frontmatter keys ignored ───

    public function testUnknownFrontmatterKeysIgnored(): void
    {
        $filePath = $this->tmpDir.'/extra.md';
        $this->writeFile($filePath, "---\ndescription: Good\nextra: ignored\n---\n\nBody.\n");
        $loader = $this->createLoader(new PromptsConfig([$filePath]));
        $result = $loader->load();

        self::assertSame('Good', $result->templates[0]->description);
        self::assertEmpty($result->diagnostics);
    }

    // ─── No raw content in logs/diagnostics ───

    public function testNoRawContentInCollisionDiagnostics(): void
    {
        $file1 = $this->tmpDir.'/a.md';
        $file2 = $this->tmpDir.'/b/A.md'; // same lowercase name 'a'
        $this->writeFile($file1, "Secret content one.\n");
        $this->writeFile($file2, "Secret content two.\n");
        $loader = $this->createLoader(new PromptsConfig([$file1, $file2]));
        $result = $loader->load();

        // Diagnostic should have paths but no content.
        $diag = $result->diagnostics[0];
        self::assertSame('collision', $diag->type);
        self::assertSame($file1, $diag->winnerPath);
        self::assertSame($file2, $diag->loserPath);
        // Message should not contain template content.
        self::assertStringNotContainsString('Secret', $diag->message);

        // Log should not contain template content.
        foreach ($this->logger->records as $record) {
            $msg = $record['message'];
            self::assertStringNotContainsString('Secret', $msg);
            if (isset($record['context'])) {
                $ctxStr = json_encode($record['context'], JSON_THROW_ON_ERROR);
                self::assertStringNotContainsString('Secret', $ctxStr);
            }
        }
    }
}
