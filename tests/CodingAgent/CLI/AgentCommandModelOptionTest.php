<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI;

use Ineersa\CodingAgent\CLI\AgentCommand;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Migrations\StartupDatabaseMigrator;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Application\InteractiveMode;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Theme\ThemeRegistry;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\InvokableCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @covers \Ineersa\CodingAgent\CLI\AgentCommand
 *
 * Public startup contract for --resume/--model/--reasoning:
 *  - prompt-bearing launches still ride StartRunRequest,
 *  - prompt-less --resume with explicit options updates session selection
 *    through the real container AgentCommand before InteractiveMode::run,
 *  - omitted resume options preserve the existing session selection,
 *  - invalid --reasoning fails before any model write.
 */
final class AgentCommandModelOptionTest extends IsolatedKernelTestCase
{
    private string $homeDir = '';
    private ?string $previousHome = null;
    private HatfieldSessionStore $sessionStore;
    private string $sessionId = '';

    /** @var array{async: bool, sigterm: callable|int|string|null, sigint: callable|int|string|null, themes: array<string, ThemePalette>, migratorRan: ?bool}|null */
    private ?array $armedCleanup = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->homeDir = TestDirectoryIsolation::createProjectTempDir('hatfield-agent-model-home', 0o750);
        TestDirectoryIsolation::createHatfieldTree($this->homeDir, withSessions: false);
        TestDirectoryIsolation::createHatfieldTree($this->isolatedCwd(), withSessions: true);

        $previousHome = $_SERVER['HOME'] ?? getenv('HOME') ?: null;
        $this->previousHome = \is_string($previousHome) ? $previousHome : null;
        $_SERVER['HOME'] = $this->homeDir;
        $_ENV['HOME'] = $this->homeDir;
        putenv('HOME='.$this->homeDir);

        /** @var HatfieldSessionStore $sessionStore */
        $sessionStore = self::getContainer()->get(HatfieldSessionStore::class);
        $this->sessionStore = $sessionStore;

        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $entity = new HatfieldSession();
        $entity->cwd = $this->isolatedCwd();
        $entity->model = 'llama_cpp_test/test';
        $entity->modelProvider = 'llama_cpp_test';
        $entity->modelName = 'test';
        $entity->reasoning = 'medium';
        $entityManager->persist($entity);
        $entityManager->flush();
        $this->sessionId = (string) $entity->id;
    }

    protected function tearDown(): void
    {
        $this->restoreArmedSharedState();

        if (null !== $this->previousHome) {
            $_SERVER['HOME'] = $this->previousHome;
            $_ENV['HOME'] = $this->previousHome;
            putenv('HOME='.$this->previousHome);
        } else {
            unset($_SERVER['HOME'], $_ENV['HOME']);
            putenv('HOME');
        }

        if ('' !== $this->homeDir) {
            TestDirectoryIsolation::removeDirectory($this->homeDir);
        }

        parent::tearDown();
    }

    #[Test]
    public function promptWithModelBuildsFullRequest(): void
    {
        $request = $this->buildInitialRequest('hello', 'llama_cpp_test/test', '');

        $this->assertNotNull($request);
        $this->assertSame('hello', $request->prompt);
        $this->assertSame('llama_cpp_test/test', $request->model);
        $this->assertNull($request->reasoning);
    }

    #[Test]
    public function promptWithoutOptionsBuildsPlainRequest(): void
    {
        $request = $this->buildInitialRequest('hello', '', '');

        $this->assertNotNull($request);
        $this->assertSame('hello', $request->prompt);
        $this->assertNull($request->model);
        $this->assertNull($request->reasoning);
    }

    #[Test]
    public function noPromptBuildsNoRequest(): void
    {
        $this->assertNull($this->buildInitialRequest('', 'llama_cpp_test/test', 'high'));
        $this->assertNull($this->buildInitialRequest('', '', ''));
    }

    #[Test]
    public function publicStartupResumeAppliesExplicitOverridesBeforeInteractiveMode(): void
    {
        $this->armInteractiveModeAbortBeforeSessionLoop();

        try {
            $this->invokePublicAgentCommand([
                'command' => 'agent',
                '--resume' => $this->sessionId,
                '--model' => 'llama_cpp_test/alt',
                '--reasoning' => 'high',
                '--transport' => 'in-process',
            ]);
            $this->fail('Armed InteractiveMode abort must surface after resume overrides apply');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Theme "', $e->getMessage());
            $this->assertStringContainsString('is not registered', $e->getMessage());
        } finally {
            $this->restoreArmedSharedState();
        }

        $session = $this->sessionStore->findSession($this->sessionId);
        $this->assertNotNull($session);
        $this->assertSame('llama_cpp_test/alt', $session->model);
        $this->assertSame('llama_cpp_test', $session->modelProvider);
        $this->assertSame('alt', $session->modelName);
        $this->assertSame('high', $session->reasoning);
        $this->assertNull($this->buildInitialRequest('', 'llama_cpp_test/alt', 'high'));
    }

    #[Test]
    public function publicStartupResumePreservesSessionWhenOptionsOmitted(): void
    {
        $this->armInteractiveModeAbortBeforeSessionLoop();

        try {
            $this->invokePublicAgentCommand([
                'command' => 'agent',
                '--resume' => $this->sessionId,
                '--transport' => 'in-process',
            ]);
            $this->fail('Armed InteractiveMode abort must surface after public resume startup');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Theme "', $e->getMessage());
            $this->assertStringContainsString('is not registered', $e->getMessage());
        } finally {
            $this->restoreArmedSharedState();
        }

        $session = $this->sessionStore->findSession($this->sessionId);
        $this->assertNotNull($session);
        $this->assertSame('llama_cpp_test/test', $session->model);
        $this->assertSame('medium', $session->reasoning);
    }

    #[Test]
    public function publicStartupResumeWithInvalidReasoningDoesNotChangeModel(): void
    {
        $this->armInteractiveModeAbortBeforeSessionLoop();

        try {
            $this->invokePublicAgentCommand([
                'command' => 'agent',
                '--resume' => $this->sessionId,
                '--model' => 'llama_cpp_test/test',
                '--reasoning' => 'super-genius',
                '--transport' => 'in-process',
            ]);
            $this->fail('Invalid --reasoning must fail before InteractiveMode starts');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid reasoning level "super-genius"', $e->getMessage());
        } finally {
            $this->restoreArmedSharedState();
        }

        $session = $this->sessionStore->findSession($this->sessionId);
        $this->assertNotNull($session);
        $this->assertSame('llama_cpp_test/test', $session->model);
        $this->assertSame('medium', $session->reasoning);
    }

    protected static function configureIsolatedProjectBeforeKernelBoot(string $classCwd): void
    {
        // Seed a second available model before AppConfig boots so public resume
        // can prove a real --model override without depending on host ~/.hatfield.
        file_put_contents($classCwd.'/.hatfield/settings.yaml', <<<'YAML'
ai:
    default_model: llama_cpp_test/test
    default_reasoning: medium
    providers:
        llama_cpp_test:
            type: generic
            enabled: true
            base_url: 'http://127.0.0.1:9052/v1'
            api: openai-completions
            api_key: dummy
            completions_path: /chat/completions
            supports_completions: true
            models:
                test:
                    id: test
                    name: test
                    context_window: 8192
                    max_tokens: 1024
                    input: [text]
                    tool_calling: true
                    reasoning: true
                    reasoning_levels: [off, low, medium, high]
                alt:
                    id: alt
                    name: alt
                    context_window: 8192
                    max_tokens: 1024
                    input: [text]
                    tool_calling: true
                    reasoning: true
                    reasoning_levels: [off, low, medium, high]
YAML);
    }

    /**
     * @param array<string, scalar> $input
     */
    private function invokePublicAgentCommand(array $input): int
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        return $application->run(new ArrayInput($input), new NullOutput());
    }

    /**
     * Force InteractiveMode::run to throw at theme resolution, after AgentCommand
     * has already applied resume overrides and before the TUI session loop starts.
     *
     * Snapshots shared signal / theme / migrator state so later cases and other
     * suites do not inherit InteractiveMode's process-wide handlers.
     */
    private function armInteractiveModeAbortBeforeSessionLoop(): void
    {
        $this->restoreArmedSharedState();

        $agentCommand = $this->resolveContainerAgentCommand();
        $migratorProperty = new \ReflectionProperty(AgentCommand::class, 'startupDatabaseMigrator');
        /** @var StartupDatabaseMigrator|null $migrator */
        $migrator = $migratorProperty->getValue($agentCommand);
        $migratorRan = null;
        if (null !== $migrator) {
            $ranProperty = new \ReflectionProperty($migrator, 'ran');
            $migratorRan = (bool) $ranProperty->getValue($migrator);
            // IsolatedKernelTestCase already migrated the test DB. Mark the shared
            // StartupDatabaseMigrator as ran so AgentCommand skips WAL re-entry
            // under DAMA before the resume override path under test.
            $ranProperty->setValue($migrator, true);
        }

        $interactiveProperty = new \ReflectionProperty(AgentCommand::class, 'interactiveMode');
        /** @var InteractiveMode $interactiveMode */
        $interactiveMode = $interactiveProperty->getValue($agentCommand);

        $themeRegistryProperty = new \ReflectionProperty(InteractiveMode::class, 'themeRegistry');
        /** @var ThemeRegistry $themeRegistry */
        $themeRegistry = $themeRegistryProperty->getValue($interactiveMode);

        $themesProperty = new \ReflectionProperty(ThemeRegistry::class, 'themes');
        /** @var array<string, ThemePalette> $themes */
        $themes = $themesProperty->getValue($themeRegistry);

        $async = false;
        $sigterm = null;
        $sigint = null;
        if (\function_exists('pcntl_async_signals') && \function_exists('pcntl_signal') && \function_exists('pcntl_signal_get_handler')) {
            // Snapshot before InteractiveMode::run installs exit(0) handlers.
            $async = (bool) pcntl_async_signals();
            $sigterm = pcntl_signal_get_handler(\SIGTERM);
            $sigint = pcntl_signal_get_handler(\SIGINT);
        }

        $this->armedCleanup = [
            'async' => $async,
            'sigterm' => $sigterm,
            'sigint' => $sigint,
            'themes' => $themes,
            'migratorRan' => $migratorRan,
        ];

        // Empty the registry so InteractiveMode aborts at getOrThrow after
        // AgentCommand has already applied resume overrides.
        $themesProperty->setValue($themeRegistry, []);
    }

    private function restoreArmedSharedState(): void
    {
        if (null === $this->armedCleanup) {
            return;
        }

        $cleanup = $this->armedCleanup;
        $this->armedCleanup = null;

        $agentCommand = $this->resolveContainerAgentCommand();

        $migratorProperty = new \ReflectionProperty(AgentCommand::class, 'startupDatabaseMigrator');
        /** @var StartupDatabaseMigrator|null $migrator */
        $migrator = $migratorProperty->getValue($agentCommand);
        if (null !== $migrator && null !== $cleanup['migratorRan']) {
            $ranProperty = new \ReflectionProperty($migrator, 'ran');
            $ranProperty->setValue($migrator, $cleanup['migratorRan']);
        }

        $interactiveProperty = new \ReflectionProperty(AgentCommand::class, 'interactiveMode');
        /** @var InteractiveMode $interactiveMode */
        $interactiveMode = $interactiveProperty->getValue($agentCommand);
        $themeRegistryProperty = new \ReflectionProperty(InteractiveMode::class, 'themeRegistry');
        /** @var ThemeRegistry $themeRegistry */
        $themeRegistry = $themeRegistryProperty->getValue($interactiveMode);
        $themesProperty = new \ReflectionProperty(ThemeRegistry::class, 'themes');
        $themesProperty->setValue($themeRegistry, $cleanup['themes']);

        if (\function_exists('pcntl_async_signals') && \function_exists('pcntl_signal')) {
            // Restore the prior async flag exactly; never force false.
            pcntl_async_signals($cleanup['async']);
            if (null !== $cleanup['sigterm']) {
                pcntl_signal(\SIGTERM, $cleanup['sigterm']);
            }
            if (null !== $cleanup['sigint']) {
                pcntl_signal(\SIGINT, $cleanup['sigint']);
            }
        }
    }

    private function resolveContainerAgentCommand(): AgentCommand
    {
        $application = new Application(self::$kernel);
        $command = $application->find('agent')->getCommand();

        $codeProperty = new \ReflectionProperty(Command::class, 'code');
        /** @var InvokableCommand $invokable */
        $invokable = $codeProperty->getValue($command);

        $invokableCodeProperty = new \ReflectionProperty(InvokableCommand::class, 'code');
        /** @var \Closure $closure */
        $closure = $invokableCodeProperty->getValue($invokable);
        $agentCommand = (new \ReflectionFunction($closure))->getClosureThis();
        $this->assertInstanceOf(AgentCommand::class, $agentCommand);

        return $agentCommand;
    }

    private function buildInitialRequest(string $prompt, string $model, string $reasoning): ?StartRunRequest
    {
        $method = new \ReflectionMethod(AgentCommand::class, 'buildInitialRequest');

        return $method->invoke(null, $prompt, $model, $reasoning);
    }
}
