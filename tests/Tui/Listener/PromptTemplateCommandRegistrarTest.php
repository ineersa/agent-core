<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

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
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Command\DispatchRuntime;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Listener\PromptTemplateCommandRegistrar;
use Ineersa\Tui\Listener\SlashCommandCatalogRegistrar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PromptTemplateCommandRegistrarTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private string $cwd;
    private SlashCommandCatalog $commandCatalog;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('prompt_template_command_registrar');
        $this->homeDir = $this->tmpDir.'/home';
        $this->cwd = $this->tmpDir.'/project';
        mkdir($this->homeDir, 0777, true);
        mkdir($this->cwd, 0777, true);
        $this->commandCatalog = new SlashCommandCatalog();
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    #[Test]
    public function registersCommandPerTemplate(): void
    {
        $this->writeTemplate('review', "Review: test\n");
        $this->writeTemplate('summarize', "Summarize: test\n");

        $this->createRegistrar()->registerCatalog($this->commandCatalog);

        $this->assertTrue($this->commandCatalog->has('review'));
        $this->assertTrue($this->commandCatalog->has('summarize'));
        $this->assertNotNull($this->commandCatalog->getMetadata('review'));
        $this->assertNotNull($this->commandCatalog->getMetadata('summarize'));
    }

    #[Test]
    public function metadataHasCorrectFields(): void
    {
        $this->writeTemplate('review', "---\ndescription: Review code changes\n---\nBody\n");

        $this->createRegistrar()->registerCatalog($this->commandCatalog);

        $meta = $this->commandCatalog->getMetadata('review');
        $this->assertNotNull($meta);
        $this->assertSame('review', $meta->name);
        $this->assertTrue($meta->acceptsArguments);
        $this->assertSame('Review code changes', $meta->description);
        $this->assertSame('/review <args>', $meta->usage);
        $this->assertSame([], $meta->aliases);

        $all = $this->commandCatalog->allMetadata();
        $names = array_map(static fn ($m) => $m->name, $all);
        $this->assertContains('review', $names);
    }

    #[Test]
    public function handlerReturnsDispatchRuntimeWithOriginalText(): void
    {
        $this->writeTemplate('review', "Review: test\n");

        $this->createRegistrar()->registerCatalog($this->commandCatalog);

        $result = (new SlashCommandRegistry($this->commandCatalog))->execute(new SlashCommand('review', 'foo bar', '/review foo bar'));
        $this->assertInstanceOf(DispatchRuntime::class, $result);
        $this->assertSame('/review foo bar', $result->payload);
    }

    #[Test]
    public function skipsWhenRealCommandAlreadyRegistered(): void
    {
        $realHandler = new class implements SlashCommandHandler {
            public function handle(SlashCommand $command): DispatchRuntime
            {
                return new DispatchRuntime('from-real-handler');
            }
        };

        $this->commandCatalog = new SlashCommandCatalog();
        $this->commandCatalog->register(
            new \Ineersa\Tui\Command\CommandMetadata(
                name: 'review',
                description: 'Real review command',
                usage: '/review',
            ),
            $realHandler,
        );

        $this->writeTemplate('review', "---\ndescription: Template review\n---\nBody\n");
        $this->createRegistrar()->registerCatalog($this->commandCatalog);

        $result = (new SlashCommandRegistry($this->commandCatalog))->execute(new SlashCommand('review', '', '/review'));
        $this->assertInstanceOf(DispatchRuntime::class, $result);
        $this->assertSame('from-real-handler', $result->payload, 'Real handler should still execute');

        $meta = $this->commandCatalog->getMetadata('review');
        $this->assertNotNull($meta);
        $this->assertSame('Real review command', $meta->description);
    }

    #[Test]
    public function skipsTemplateWhenNameCollidesWithBuiltinHelp(): void
    {
        $this->writeTemplate('help', "---\ndescription: Template help override\n---\nBody\n");
        $this->writeTemplate('review', "---\ndescription: Review\n---\nBody\n");

        $this->createRegistrar()->registerCatalog($this->commandCatalog);

        $result = (new SlashCommandRegistry($this->commandCatalog))->execute(new SlashCommand('help', '', '/help'));
        $this->assertInstanceOf(\Ineersa\Tui\Command\TranscriptMessage::class, $result);

        $this->assertTrue($this->commandCatalog->has('review'));
    }

    #[Test]
    public function hyphenatedNameRegisters(): void
    {
        $this->writeTemplate('team-review', "---\ndescription: Team code review\n---\nBody\n");

        $this->createRegistrar()->registerCatalog($this->commandCatalog);

        $this->assertTrue($this->commandCatalog->has('team-review'));
        $meta = $this->commandCatalog->getMetadata('team-review');
        $this->assertNotNull($meta);
        $this->assertSame('team-review', $meta->name);

        $result = (new SlashCommandRegistry($this->commandCatalog))->execute(new SlashCommand('team-review', 'pr #42', '/team-review pr #42'));
        $this->assertInstanceOf(DispatchRuntime::class, $result);
        $this->assertSame('/team-review pr #42', $result->payload);
    }

    #[Test]
    public function noTemplatesProducesNoCommands(): void
    {
        $initialCount = \count($this->commandCatalog->allMetadata());

        $this->createRegistrar()->registerCatalog($this->commandCatalog);

        $this->assertSame($initialCount, \count($this->commandCatalog->allMetadata()));
    }

    #[Test]
    public function implementsSlashCommandCatalogRegistrar(): void
    {
        $registrar = $this->createRegistrar();
        $this->assertInstanceOf(SlashCommandCatalogRegistrar::class, $registrar);
    }

    #[Test]
