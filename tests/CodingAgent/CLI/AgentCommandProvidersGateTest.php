<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI;

use Ineersa\CodingAgent\CLI\AgentCommand;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Process\JsonlProcessAgentSessionClient;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use Ineersa\CodingAgent\Tool\ToolFilterRuntimeConfig;
use Ineersa\Tui\Application\InteractiveMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Thesis: agent startup fails fast with a providers:setup hint when no
 * AI providers are enabled, and proceeds past the gate when one is enabled.
 *
 * Layer: in-process AgentCommand invoke (no TUI boot). Disabled path asserts
 * FAILURE + message. Enabled path uses --controller with a null controller so
 * the gate-pass is proven by the next known failure ('Controller service not available').
 */
#[CoversClass(AgentCommand::class)]
final class AgentCommandProvidersGateTest extends TestCase
{
    #[Test]
    public function zeroEnabledProvidersFailsWithSetupHint(): void
    {
        $command = $this->createCommand(enabled: false);
        $output = new BufferedOutput();

        $status = $command(
            headless: false,
            controller: false,
            transport: 'process',
            prompt: '',
            resume: '',
            model: '',
            reasoning: '',
            cwd: '',
            noSkills: false,
            skillsPath: [],
            skills: [],
            tools: '',
            toolsExcluded: '',
            promptTemplate: [],
            noPromptTemplates: false,
            output: $output,
        );

        $this->assertSame(Command::FAILURE, $status);
        $printed = $output->fetch();
        $this->assertStringContainsString('No AI providers configured', $printed);
        $this->assertStringContainsString('providers:setup', $printed);
    }

    #[Test]
    public function oneEnabledProviderPassesGate(): void
    {
        $command = $this->createCommand(enabled: true);
        $output = new BufferedOutput();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Controller service not available');

        $command(
            headless: false,
            controller: true,
            transport: 'process',
            prompt: '',
            resume: '',
            model: '',
            reasoning: '',
            cwd: '',
            noSkills: false,
            skillsPath: [],
            skills: [],
            tools: '',
            toolsExcluded: '',
            promptTemplate: [],
            noPromptTemplates: false,
            output: $output,
        );
    }

    private function createCommand(bool $enabled): AgentCommand
    {
        $provider = new AiProviderConfig(
            id: 'zai',
            enabled: $enabled,
            baseUrl: 'https://example.test',
            models: [
                'glm-5.3' => new AiModelDefinition(id: 'glm-5.3', name: 'GLM 5.3'),
            ],
        );

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(logDir: '/tmp'),
            ai: new AiConfig(providers: ['zai' => $provider]),
            cwd: '/tmp',
        );

        return new AgentCommand(
            inProcessClient: $this->uninitialized(InProcessAgentSessionClient::class),
            processClient: $this->uninitialized(JsonlProcessAgentSessionClient::class),
            interactiveMode: $this->uninitialized(InteractiveMode::class),
            sessionStore: $this->uninitialized(HatfieldSessionStore::class),
            skillsConfig: new SkillsConfig(),
            promptTemplatesConfig: new PromptTemplatesRuntimeConfig(),
            toolFilterConfig: new ToolFilterRuntimeConfig(),
            logger: new NullLogger(),
            appConfig: $appConfig,
            startupDatabaseMigrator: null,
            controller: null,
            toolRegistry: null,
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function uninitialized(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
