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
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Tui;

final class SessionCommandRegistrarTest extends TestCase
{
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

    private function buildContextAndPicker(TuiSessionState $state): array
    {
        $registry = new SlashCommandRegistry();

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->tmpDir,
        );
        $sessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );
        $switch = $this->createStub(\Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface::class);
        $picker = new SessionPickerController($sessionStore, $switch);

        return [$registry, $picker, $sessionStore, $switch];
    }

    #[Test]
    public function testRegistersNewCommandWithCorrectMetadata(): void
    {
        [$registry, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker);

        $registrar = new SessionCommandRegistrar($registry, $picker);
        $registrar->register($context);

        self::assertTrue($registry->has('new'));

        $meta = $registry->getMetadata('new');
        self::assertInstanceOf(CommandMetadata::class, $meta);
        self::assertSame('new', $meta->name);
        self::assertFalse($meta->acceptsArguments);
        self::assertSame('/new', $meta->usage);
        self::assertNotEmpty($meta->description);
    }

    #[Test]
    public function testRegistersResumeCommandWithCorrectMetadata(): void
    {
        [$registry, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker);

        $registrar = new SessionCommandRegistrar($registry, $picker);
        $registrar->register($context);

        self::assertTrue($registry->has('resume'));
        self::assertTrue($registry->has('r'), 'Alias r should resolve to resume');

        $meta = $registry->getMetadata('resume');
        self::assertInstanceOf(CommandMetadata::class, $meta);
        self::assertSame('resume', $meta->name);
        self::assertContains('r', $meta->aliases);
        self::assertTrue($meta->acceptsArguments);
        self::assertSame('/resume [session id]', $meta->usage);
        self::assertNotEmpty($meta->description);
    }

    #[Test]
    public function testCommandsAppearInHelpOutput(): void
    {
        [$registry, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker);

        $registrar = new SessionCommandRegistrar($registry, $picker);
        $registrar->register($context);

        $result = $registry->execute(new SlashCommand('help', '', '/help'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('/new', $result->text);
        self::assertStringContainsString('/resume', $result->text);
    }

    #[Test]
    public function testNewCommandHandlerReturnsNoOp(): void
    {
        [$registry, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker);

        $registrar = new SessionCommandRegistrar($registry, $picker);
        $registrar->register($context);

        $result = $registry->execute(new SlashCommand('new', '', '/new'));

        self::assertInstanceOf(NoOp::class, $result);
    }

    #[Test]
    public function testIdempotentRegistrationDoesNotThrow(): void
    {
        [$registry, $picker] = $this->buildContextAndPicker(new TuiSessionState('test-session'));
        $context = $this->buildContext(new TuiSessionState('test-session'), $picker);

        $registrar = new SessionCommandRegistrar($registry, $picker);

        // First registration
        $registrar->register($context);
        self::assertTrue($registry->has('new'));
        self::assertTrue($registry->has('resume'));

        // Second registration — should replace handlers without throwing
        $registrar->register($context);
        self::assertTrue($registry->has('new'));
        self::assertTrue($registry->has('resume'));

        // Verify commands still work after re-registration
        $result = $registry->execute(new SlashCommand('new', '', '/new'));
        self::assertInstanceOf(NoOp::class, $result);
    }

    private function buildContext(TuiSessionState $state, SessionPickerController $picker): TuiRuntimeContext
    {
        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $promptEditor = new PromptEditor();
        $screen = new ChatScreen($theme, $state->sessionId, $promptEditor);

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->tmpDir,
        );
        $sessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );

        return new TuiRuntimeContext(
            tui: $tui,
            client: $this->createStub(\Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient::class),
            state: $state,
            screen: $screen,
            sessionStore: $sessionStore,
            ticks: new \Ineersa\Tui\Runtime\TuiTickDispatcher(),
            switch: $this->createStub(\Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface::class),
            lifecycle: new \Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher(),
        );
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
