<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\PromptTemplateCatalogInterface;
use Ineersa\CodingAgent\Runtime\Contract\PromptTemplateCommand;
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
    private SlashCommandCatalog $commandCatalog;
    /** @var PromptTemplateCatalogInterface&object */
    private PromptTemplateCatalogInterface $catalog;

    protected function setUp(): void
    {
        $this->commandCatalog = new SlashCommandCatalog();
        $this->catalog = $this->createStub(PromptTemplateCatalogInterface::class);
    }

    #[Test]
    public function registersCommandPerTemplate(): void
    {
        $this->catalog->method('allPromptTemplateCommands')->willReturn([
            new PromptTemplateCommand(name: 'review', description: 'Review code changes'),
            new PromptTemplateCommand(name: 'summarize', description: 'Summarize conversation'),
        ]);

        $registrar = new PromptTemplateCommandRegistrar($this->catalog);
        $registrar->registerCatalog($this->commandCatalog);

        $this->assertTrue($this->commandCatalog->has('review'));
        $this->assertTrue($this->commandCatalog->has('summarize'));
        $this->assertNotNull($this->commandCatalog->getMetadata('review'));
        $this->assertNotNull($this->commandCatalog->getMetadata('summarize'));
    }

    #[Test]
    public function metadataHasCorrectFields(): void
    {
        $this->catalog->method('allPromptTemplateCommands')->willReturn([
            new PromptTemplateCommand(name: 'review', description: 'Review code changes'),
        ]);

        $registrar = new PromptTemplateCommandRegistrar($this->catalog);
        $registrar->registerCatalog($this->commandCatalog);

        $meta = $this->commandCatalog->getMetadata('review');
        $this->assertNotNull($meta);
        $this->assertSame('review', $meta->name);
        $this->assertTrue($meta->acceptsArguments);
        $this->assertSame('Review code changes', $meta->description);
        $this->assertSame('/review <args>', $meta->usage);
        $this->assertSame([], $meta->aliases);

        // Metadata appears in allMetadata()
        $all = $this->commandCatalog->allMetadata();
        $names = array_map(static fn ($m) => $m->name, $all);
        $this->assertContains('review', $names);
    }

    #[Test]
    public function handlerReturnsDispatchRuntimeWithOriginalText(): void
    {
        $this->catalog->method('allPromptTemplateCommands')->willReturn([
            new PromptTemplateCommand(name: 'review', description: 'Review code changes'),
        ]);

        $registrar = new PromptTemplateCommandRegistrar($this->catalog);
        $registrar->registerCatalog($this->commandCatalog);

        $result = (new SlashCommandRegistry($this->commandCatalog))->execute(new SlashCommand('review', 'foo bar', '/review foo bar'));
        $this->assertInstanceOf(DispatchRuntime::class, $result);
        $this->assertSame('/review foo bar', $result->payload);
    }

    #[Test]
    public function skipsWhenRealCommandAlreadyRegistered(): void
    {
        // Pre-register a real "review" command, then run the registrar with
        // a template also named "review". The registrar must skip the template
        // because the name is already taken — real command handler and metadata
        // must remain untouched.
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

        $this->catalog->method('allPromptTemplateCommands')->willReturn([
            new PromptTemplateCommand(name: 'review', description: 'Template review'),
        ]);

        $registrar = new PromptTemplateCommandRegistrar($this->catalog);
        $registrar->registerCatalog($this->commandCatalog);

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
        // The built-in registry already has /help. Try to register a template
        // named "help". The registrar should skip it.
        $this->catalog->method('allPromptTemplateCommands')->willReturn([
            new PromptTemplateCommand(name: 'help', description: 'Template help override'),
            new PromptTemplateCommand(name: 'review', description: 'Review'),
        ]);

        $registrar = new PromptTemplateCommandRegistrar($this->catalog);
        $registrar->registerCatalog($this->commandCatalog);

        // /help should still be the built-in help, not a DispatchRuntime
        $result = (new SlashCommandRegistry($this->commandCatalog))->execute(new SlashCommand('help', '', '/help'));
        // Built-in help returns TranscriptMessage, not DispatchRuntime
        $this->assertInstanceOf(\Ineersa\Tui\Command\TranscriptMessage::class, $result);

        // /review should be registered as a template command
        $this->assertTrue($this->commandCatalog->has('review'));
    }

    #[Test]
    public function hyphenatedNameRegisters(): void
    {
        $this->catalog->method('allPromptTemplateCommands')->willReturn([
            new PromptTemplateCommand(name: 'team-review', description: 'Team code review'),
        ]);

        $registrar = new PromptTemplateCommandRegistrar($this->catalog);
        $registrar->registerCatalog($this->commandCatalog);

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
        $this->catalog->method('allPromptTemplateCommands')->willReturn([]);

        $initialCount = $this->commandCatalog->count();

        $registrar = new PromptTemplateCommandRegistrar($this->catalog);
        $registrar->registerCatalog($this->commandCatalog);

        // Only built-in commands should exist
        $this->assertSame($initialCount, $this->commandCatalog->count());
    }

    #[Test]
    public function implementsSlashCommandCatalogRegistrar(): void
    {
        $registrar = new PromptTemplateCommandRegistrar($this->catalog);
        $this->assertInstanceOf(SlashCommandCatalogRegistrar::class, $registrar);
    }

    #[Test]
    public function getPriorityReturnsNegative100(): void
    {
        $this->assertSame(-100, PromptTemplateCommandRegistrar::getPriority());
    }
}
