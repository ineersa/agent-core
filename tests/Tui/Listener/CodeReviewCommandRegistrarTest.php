<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Skills\SkillContextRenderer;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\DispatchRuntime;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Listener\CodeReviewCommandRegistrar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CodeReviewCommandRegistrarTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('code-review-command');
        TestDirectoryIsolation::ensureDirectory($this->tmpDir.'/home');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    #[Test]
    public function dispatchesBundledSkillAndPreservesFreeFormRequest(): void
    {
        $catalog = new SlashCommandCatalog();
        $this->createRegistrar()->registerCatalog($catalog);
        $metadata = $catalog->getMetadata('code-review');
        $this->assertNotNull($metadata);
        $this->assertTrue($metadata->acceptsArguments);
        $this->assertFileExists($this->tmpDir.'/home/.hatfield/skills/code-review/SKILL.md');

        $request = 'validate PR 657; focus on "cancellation" and $ARGUMENTS';
        $command = (new CommandParser())->parse('/code-review '.$request);
        $this->assertInstanceOf(SlashCommand::class, $command);
        $result = (new SlashCommandRegistry($catalog))->execute($command);

        $this->assertInstanceOf(DispatchRuntime::class, $result);
        $this->assertStringContainsString('<skill name="code-review"', $result->payload);
        $this->assertStringContainsString('## Fresh verifier', $result->payload);
        $this->assertStringEndsWith($request, $result->payload);
    }

    #[Test]
    public function bareCommandStillLoadsTheWorkflow(): void
    {
        $catalog = new SlashCommandCatalog();
        $this->createRegistrar()->registerCatalog($catalog);
        $command = (new CommandParser())->parse('/CODE-REVIEW');
        $this->assertInstanceOf(SlashCommand::class, $command);
        $result = (new SlashCommandRegistry($catalog))->execute($command);

        $this->assertInstanceOf(DispatchRuntime::class, $result);
        $this->assertStringContainsString('<skill name="code-review"', $result->payload);
        $this->assertStringContainsString('empty means the current change', $result->payload);
    }

    #[Test]
    public function disabledSkillsDoNotAdvertiseAnUnavailableWorkflow(): void
    {
        $catalog = new SlashCommandCatalog();
        $this->createRegistrar(noSkills: true)->registerCatalog($catalog);

        $this->assertFalse($catalog->has('code-review'));
    }

    private function createRegistrar(bool $noSkills = false): CodeReviewCommandRegistrar
    {
        $extractor = new MarkdownFrontmatterExtractor();
        $config = new SkillsConfig(noSkills: $noSkills);
        $discovery = new SkillDiscovery(
            config: $config,
            pathResolver: new SettingsPathResolver($this->tmpDir, $this->tmpDir.'/home'),
            appConfig: new AppConfig(tui: new TuiConfig(theme: 'test'), logging: new LoggingConfig(), cwd: $this->tmpDir),
            extractor: $extractor,
            resources: new AppResourceLocator(\dirname(__DIR__, 3)),
            filesystem: new Filesystem(),
            denormalizer: AttributeSerializerValidatorTestFactory::denormalizer(),
            validator: AttributeSerializerValidatorTestFactory::create()[1],
        );

        return new CodeReviewCommandRegistrar($discovery, new SkillsContextBuilder(
            discovery: $discovery,
            config: $config,
            renderer: new SkillContextRenderer(),
            extractor: $extractor,
        ));
    }
}
