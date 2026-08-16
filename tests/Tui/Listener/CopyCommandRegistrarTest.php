<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\CopyCommandRegistrar;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Tui;

final class CopyCommandRegistrarTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/hatfield-copy-test-'.uniqid('', true);
        mkdir($this->tmpDir.'/.hatfield/sessions', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
        parent::tearDown();
    }

    #[Test]
    public function registersCopyCommandWithMetadataAndAlias(): void
    {
        $catalog = new SlashCommandCatalog();
        $state = new TuiSessionState('test-session');
        $context = $this->buildContext($state, $catalog);

        $registrar = new CopyCommandRegistrar();
        $registrar->registerCatalog($catalog);
        $registrar->register($context);

        // Command is registered
        $this->assertTrue($catalog->has('copy'));
        $this->assertTrue($catalog->has('cp'));

        // Metadata is correct
        $meta = $catalog->getMetadata('copy');
        $this->assertInstanceOf(CommandMetadata::class, $meta);
        $this->assertSame('copy', $meta->name);
        $this->assertContains('cp', $meta->aliases);
        $this->assertSame('Copy the last model output to the clipboard', $meta->description);
        $this->assertSame('/copy', $meta->usage);
    }

    #[Test]
    public function copyCommandAppearsInHelpOutput(): void
    {
        $catalog = new SlashCommandCatalog();
        $state = new TuiSessionState('test-session');
        $context = $this->buildContext($state, $catalog);

        $registrar = new CopyCommandRegistrar();
        $registrar->registerCatalog($catalog);
        $registrar->register($context);

        $result = $context->sessionServices->commandRegistry->execute(new SlashCommand('help', '', '/help'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('/copy', $result->text);
        $this->assertStringContainsString('Copy the last model output to the clipboard', $result->text);
    }

    #[Test]
    public function copyViaAliasDispatchesToHandler(): void
    {
        $catalog = new SlashCommandCatalog();
        $state = new TuiSessionState('test-session');
        $context = $this->buildContext($state, $catalog);

        $registrar = new CopyCommandRegistrar();
        $registrar->registerCatalog($catalog);
        $registrar->register($context);

        // With no assistant message, /cp should show "nothing to copy"
        $result = $context->sessionServices->commandRegistry->execute(new SlashCommand('cp', '', '/cp'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Nothing to copy', $result->text);
        $this->assertSame('muted', $result->style);
    }

    #[Test]
    public function repeatRegistrationBindsFreshHandlerWithoutThrowing(): void
    {
        $catalog = new SlashCommandCatalog();
        $state = new TuiSessionState('test-session');
        $context = $this->buildContext($state, $catalog);

        $registrar = new CopyCommandRegistrar();
        $registrar->registerCatalog($catalog);

        // First session iteration
        $registrar->register($context);
        $this->assertTrue($catalog->has('copy'));

        // Second session iteration — binds a fresh handler without throwing
        $secondContext = $this->buildContext($state, $catalog);
        $registrar->register($secondContext);
        $this->assertTrue($catalog->has('copy'));

        // Verify the command still works after re-binding
        $result = $secondContext->sessionServices->commandRegistry->execute(new SlashCommand('copy', '', '/copy'));
        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Nothing to copy', $result->text);
    }

    private function buildContext(TuiSessionState $state, SlashCommandCatalog $catalog): TuiRuntimeContext
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
