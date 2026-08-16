<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\SessionCommandRegistrar;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Tui;

final class SessionCommandRegistrarTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/hatfield-sesscmd-test-'.uniqid('', true);
        mkdir($this->tmpDir.'/.hatfield/sessions', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    #[Test]
    public function testRegistersNewCommandWithCorrectMetadata(): void
    {
        [$catalog, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker, $catalog);

        $registrar = new SessionCommandRegistrar();
        $registrar->registerCatalog($catalog);
        $registrar->register($context);

        $this->assertTrue($catalog->has('new'));

        $meta = $catalog->getMetadata('new');
        $this->assertInstanceOf(CommandMetadata::class, $meta);
        $this->assertSame('new', $meta->name);
        $this->assertFalse($meta->acceptsArguments);
        $this->assertSame('/new', $meta->usage);
        $this->assertNotEmpty($meta->description);
    }

    #[Test]
    public function testRegistersRenameCommandWithCorrectMetadata(): void
    {
        [$catalog, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker, $catalog);

        $registrar = new SessionCommandRegistrar();
        $registrar->registerCatalog($catalog);
        $registrar->register($context);

        $this->assertTrue($catalog->has('rename'));

        $meta = $catalog->getMetadata('rename');
        $this->assertInstanceOf(CommandMetadata::class, $meta);
        $this->assertSame('rename', $meta->name);
        $this->assertTrue($meta->acceptsArguments);
        $this->assertSame('/rename [session id] [new name]', $meta->usage);
        $this->assertNotEmpty($meta->description);
    }

    #[Test]
    public function testRegistersResumeCommandWithCorrectMetadata(): void
    {
        [$catalog, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker, $catalog);

        $registrar = new SessionCommandRegistrar();
        $registrar->registerCatalog($catalog);
        $registrar->register($context);

        $this->assertTrue($catalog->has('resume'));
        $this->assertTrue($catalog->has('r'), 'Alias r should resolve to resume');

        $meta = $catalog->getMetadata('resume');
        $this->assertInstanceOf(CommandMetadata::class, $meta);
        $this->assertSame('resume', $meta->name);
        $this->assertContains('r', $meta->aliases);
        $this->assertTrue($meta->acceptsArguments);
        $this->assertSame('/resume [session id]', $meta->usage);
        $this->assertNotEmpty($meta->description);
    }

    #[Test]
    public function testCommandsAppearInHelpOutput(): void
    {
        [$catalog, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker, $catalog);

        $registrar = new SessionCommandRegistrar();
        $registrar->registerCatalog($catalog);
        $registrar->register($context);

        $result = $context->sessionServices->commandRegistry->execute(new SlashCommand('help', '', '/help'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('/new', $result->text);
        $this->assertStringContainsString('/resume', $result->text);
        $this->assertStringContainsString('/rename', $result->text);
    }

    #[Test]
    public function testNewCommandHandlerReturnsNoOp(): void
    {
        [$catalog, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker, $catalog);

        $registrar = new SessionCommandRegistrar();
        $registrar->registerCatalog($catalog);
        $registrar->register($context);

        $result = $context->sessionServices->commandRegistry->execute(new SlashCommand('new', '', '/new'));

        $this->assertInstanceOf(NoOp::class, $result);
    }

    #[Test]
    public function testRepeatRegistrationBindsHandlersWithoutThrowing(): void
    {
        [$catalog, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker, $catalog);

        $registrar = new SessionCommandRegistrar();
        $registrar->registerCatalog($catalog);

        // First session iteration
        $registrar->register($context);
        $this->assertTrue($catalog->has('new'));
        $this->assertTrue($catalog->has('resume'));
        $this->assertTrue($catalog->has('rename'));

        // Second session iteration — binds fresh handlers without throwing
        $secondContext = $this->buildContext(new TuiSessionState('test-session'), $picker, $catalog);
        $registrar->register($secondContext);
        $this->assertTrue($catalog->has('new'));

        // Verify commands still work after re-binding
        $result = $secondContext->sessionServices->commandRegistry->execute(new SlashCommand('new', '', '/new'));
        $this->assertInstanceOf(NoOp::class, $result);
    }

    private function buildContextAndPicker(TuiSessionState $state): array
    {
        return [new SlashCommandCatalog(), $this->createSessionServices(state: $state)->sessionPicker];
    }

    private function buildContext(TuiSessionState $state, $picker, SlashCommandCatalog $catalog): TuiRuntimeContext
    {
        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $state->sessionId, $promptEditor);

        return $this->buildTuiContext()
            ->withTui($tui)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($this->createSessionServices(catalog: $catalog))
            ->build();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
