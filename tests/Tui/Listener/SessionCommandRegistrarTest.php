<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\SessionCommandRegistrar;
use Ineersa\Tui\Picker\SessionPickerController;
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
        [$registry, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker);

        $registrar = new SessionCommandRegistrar($registry, $picker);
        $registrar->register($context);

        $this->assertTrue($registry->has('new'));

        $meta = $registry->getMetadata('new');
        $this->assertInstanceOf(CommandMetadata::class, $meta);
        $this->assertSame('new', $meta->name);
        $this->assertFalse($meta->acceptsArguments);
        $this->assertSame('/new', $meta->usage);
        $this->assertNotEmpty($meta->description);
    }

    #[Test]
    public function testRegistersRenameCommandWithCorrectMetadata(): void
    {
        [$registry, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker);

        $registrar = new SessionCommandRegistrar($registry, $picker);
        $registrar->register($context);

        $this->assertTrue($registry->has('rename'));

        $meta = $registry->getMetadata('rename');
        $this->assertInstanceOf(CommandMetadata::class, $meta);
        $this->assertSame('rename', $meta->name);
        $this->assertTrue($meta->acceptsArguments);
        $this->assertSame('/rename [session id] [new name]', $meta->usage);
        $this->assertNotEmpty($meta->description);
    }

    #[Test]
    public function testRegistersResumeCommandWithCorrectMetadata(): void
    {
        [$registry, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker);

        $registrar = new SessionCommandRegistrar($registry, $picker);
        $registrar->register($context);

        $this->assertTrue($registry->has('resume'));
        $this->assertTrue($registry->has('r'), 'Alias r should resolve to resume');

        $meta = $registry->getMetadata('resume');
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
        [$registry, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker);

        $registrar = new SessionCommandRegistrar($registry, $picker);
        $registrar->register($context);

        $result = $registry->execute(new SlashCommand('help', '', '/help'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('/new', $result->text);
        $this->assertStringContainsString('/resume', $result->text);
        $this->assertStringContainsString('/rename', $result->text);
    }

    #[Test]
    public function testNewCommandHandlerReturnsNoOp(): void
    {
        [$registry, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker);

        $registrar = new SessionCommandRegistrar($registry, $picker);
        $registrar->register($context);

        $result = $registry->execute(new SlashCommand('new', '', '/new'));

        $this->assertInstanceOf(NoOp::class, $result);
    }

    #[Test]
    public function testIdempotentRegistrationDoesNotThrow(): void
    {
        [$registry, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker);

        $registrar = new SessionCommandRegistrar($registry, $picker);

        // First registration
        $registrar->register($context);
        $this->assertTrue($registry->has('new'));
        $this->assertTrue($registry->has('resume'));
        $this->assertTrue($registry->has('rename'));

        // Second registration — should replace handlers without throwing
        $registrar->register($context);
        $this->assertTrue($registry->has('new'));
        $this->assertTrue($registry->has('resume'));
        $this->assertTrue($registry->has('rename'));

        // Verify commands still work after re-registration
        $result = $registry->execute(new SlashCommand('new', '', '/new'));
        $this->assertInstanceOf(NoOp::class, $result);
    }

    private function buildContextAndPicker(TuiSessionState $state): array
    {
        $registry = new SlashCommandRegistry();

        $sessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tmpDir,
            ),
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );
        $picker = new SessionPickerController(
            $sessionStore,
            $this->createStub(\Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface::class),
        );

        return [$registry, $picker];
    }

    private function buildContext(TuiSessionState $state, SessionPickerController $picker): TuiRuntimeContext
    {
        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $state->sessionId, $promptEditor);

        return $this->buildTuiContext()
            ->withTui($tui)
            ->withState($state)
            ->withScreen($screen)
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
