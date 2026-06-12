<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\PromptTemplateCatalogInterface;
use Ineersa\CodingAgent\Runtime\Contract\PromptTemplateCommand;
use Ineersa\Tui\Command\DispatchRuntime;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\PromptTemplateCommandRegistrar;
use Ineersa\Tui\Listener\TuiListenerRegistrar;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Tui;

final class PromptTemplateCommandRegistrarTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private SlashCommandRegistry $registry;
    /** @var PromptTemplateCatalogInterface&object */
    private PromptTemplateCatalogInterface $catalog;
    private Tui $tui;
    private TuiSessionState $state;
    private ChatScreen $screen;

    protected function setUp(): void
    {
        $this->registry = new SlashCommandRegistry();
        $this->catalog = $this->createStub(PromptTemplateCatalogInterface::class);
        $this->tui = new Tui();
        $this->state = new TuiSessionState('test-session');
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $this->screen = new ChatScreen($theme, 'test-session', $promptEditor);
    }

    private function buildContext(): TuiRuntimeContext
    {
        return $this->buildTuiContext()
            ->withTui($this->tui)
            ->withState($this->state)
            ->withScreen($this->screen)
            ->build();
    }

    #[Test]
    public function registersCommandPerTemplate(): void
    {
        $this->catalog->method('allPromptTemplateCommands')->willReturn([
            new PromptTemplateCommand(name: 'review', description: 'Review code changes'),
            new PromptTemplateCommand(name: 'summarize', description: 'Summarize conversation'),
        ]);

        $registrar = new PromptTemplateCommandRegistrar($this->registry, $this->catalog);
        $registrar->register($this->buildContext());

        self::assertTrue($this->registry->has('review'));
        self::assertTrue($this->registry->has('summarize'));
        self::assertNotNull($this->registry->getMetadata('review'));
        self::assertNotNull($this->registry->getMetadata('summarize'));
    }

    #[Test]
    public function metadataHasCorrectFields(): void
    {
        $this->catalog->method('allPromptTemplateCommands')->willReturn([
            new PromptTemplateCommand(name: 'review', description: 'Review code changes'),
        ]);

        $registrar = new PromptTemplateCommandRegistrar($this->registry, $this->catalog);
        $registrar->register($this->buildContext());

        $meta = $this->registry->getMetadata('review');
        self::assertNotNull($meta);
        self::assertSame('review', $meta->name);
        self::assertTrue($meta->acceptsArguments);
        self::assertSame('Review code changes', $meta->description);
        self::assertSame('/review <args>', $meta->usage);
        self::assertSame([], $meta->aliases);

        // Metadata appears in allMetadata()
        $all = $this->registry->allMetadata();
        $names = array_map(fn ($m) => $m->name, $all);
        self::assertContains('review', $names);
    }

    #[Test]
    public function handlerReturnsDispatchRuntimeWithOriginalText(): void
    {
        $this->catalog->method('allPromptTemplateCommands')->willReturn([
            new PromptTemplateCommand(name: 'review', description: 'Review code changes'),
        ]);

        $registrar = new PromptTemplateCommandRegistrar($this->registry, $this->catalog);
        $registrar->register($this->buildContext());

        $result = $this->registry->execute(new SlashCommand('review', 'foo bar', '/review foo bar'));
        self::assertInstanceOf(DispatchRuntime::class, $result);
        self::assertSame('/review foo bar', $result->payload);
    }

    #[Test]
    public function skipsWhenRealCommandAlreadyRegistered(): void
    {
        // Pre-register a real "review" command in the registry
        $realHandler = new class implements SlashCommandHandler {
            public function handle(SlashCommand $command): DispatchRuntime
            {
                // Real commands return something distinct from template
                return new DispatchRuntime('from-real-handler');
            }
        };
        // Use registerMetadataAndHandler approach — registry doesn't expose
        // raw access to handlers/metadata directly for arbitrary insert,
        // but we can register a synthetic command via the public register().
        // We need to bypass collision guard, so we call register() first.
        // Since the registrar checks has() before registering, we just need
        // the name to be taken.

        // Register a synthetic command via the public API.
        // But register() throws if already registered, so we must register
        // it BEFORE the registrar runs.
        $this->registry = new SlashCommandRegistry();
        $this->registry->execute(new SlashCommand('help', '', '/help')); // just to warm up

        // We register a fake by going through register() which will throw
        // if already there, so we ensure it's first.
        $this->catalog->method('allPromptTemplateCommands')->willReturn([
            new PromptTemplateCommand(name: 'review', description: 'Template review'),
        ]);

        // First, register a real command
        // SlashCommandRegistry::register() throws \InvalidArgumentException if name taken.
        // So register a real command before the registrar runs.
        // But the built-in registry already has help, clear, exit.
        // We need a different approach: register a command, then run registrar,
        // and verify the original handler is still active.
        // Let's register a fake "review" first, then run the registrar.
        $this->registry->register(
            new \Ineersa\Tui\Command\CommandMetadata(
                name: 'review',
                description: 'Real review command',
                usage: '/review',
            ),
            $realHandler,
        );

        $registrar = new PromptTemplateCommandRegistrar($this->registry, $this->catalog);
        $registrar->register($this->buildContext());

        // The real handler should still be in place
        $result = $this->registry->execute(new SlashCommand('review', '', '/review'));
        self::assertInstanceOf(DispatchRuntime::class, $result);
        self::assertSame('from-real-handler', $result->payload, 'Real handler should still execute');

        // Metadata should still be from the real command
        $meta = $this->registry->getMetadata('review');
        self::assertNotNull($meta);
        self::assertSame('Real review command', $meta->description);
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

        $registrar = new PromptTemplateCommandRegistrar($this->registry, $this->catalog);
        $registrar->register($this->buildContext());

        // /help should still be the built-in help, not a DispatchRuntime
        $result = $this->registry->execute(new SlashCommand('help', '', '/help'));
        // Built-in help returns TranscriptMessage, not DispatchRuntime
        self::assertInstanceOf(\Ineersa\Tui\Command\TranscriptMessage::class, $result);

        // /review should be registered as a template command
        self::assertTrue($this->registry->has('review'));
    }

    #[Test]
    public function hyphenatedNameRegisters(): void
    {
        $this->catalog->method('allPromptTemplateCommands')->willReturn([
            new PromptTemplateCommand(name: 'team-review', description: 'Team code review'),
        ]);

        $registrar = new PromptTemplateCommandRegistrar($this->registry, $this->catalog);
        $registrar->register($this->buildContext());

        self::assertTrue($this->registry->has('team-review'));
        $meta = $this->registry->getMetadata('team-review');
        self::assertNotNull($meta);
        self::assertSame('team-review', $meta->name);

        $result = $this->registry->execute(new SlashCommand('team-review', 'pr #42', '/team-review pr #42'));
        self::assertInstanceOf(DispatchRuntime::class, $result);
        self::assertSame('/team-review pr #42', $result->payload);
    }

    #[Test]
    public function noTemplatesProducesNoCommands(): void
    {
        $this->catalog->method('allPromptTemplateCommands')->willReturn([]);

        $initialCount = $this->registry->count();

        $registrar = new PromptTemplateCommandRegistrar($this->registry, $this->catalog);
        $registrar->register($this->buildContext());

        // Only built-in commands should exist
        self::assertSame($initialCount, $this->registry->count());
    }

    #[Test]
    public function implementsTuiListenerRegistrar(): void
    {
        $registrar = new PromptTemplateCommandRegistrar($this->registry, $this->catalog);
        self::assertInstanceOf(TuiListenerRegistrar::class, $registrar);
    }

    #[Test]
    public function getPriorityReturnsNegative100(): void
    {
        self::assertSame(-100, PromptTemplateCommandRegistrar::getPriority());
    }
}
