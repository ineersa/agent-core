<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Skills\SkillContextRenderer;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextDiscovery;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextRenderer;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\TemplateRenderer\StringTemplateRenderer;

/**
 * Integration tests for AGENTS.md context injection in InProcessAgentSessionClient.
 *
 * Covers:
 * - Context injected on start when AGENTS.md files exist
 * - No context injected when no AGENTS.md files
 * - Context is NOT injected on resume
 *
 * @group system-prompt
 */
final class AgentsContextInjectionTest extends TestCase
{
    /* ───────── Private helpers ───────── */

    public ?StartRunInput $capturedInput = null;
    public bool $capturedContinue = false;
    private string $projectDir;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->projectDir = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();

        $this->tmpDir = sys_get_temp_dir().'/agents_injection_test_'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir.'/.hatfield', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    public function testAgentsContextInjectedOnStart(): void
    {
        // Create AGENTS.md in the project temp dir
        file_put_contents($this->tmpDir.'/.hatfield/AGENTS.md', 'Test project instructions');

        $client = $this->createClient();
        $request = new StartRunRequest(prompt: 'Hello');
        $client->start($request);

        // The runner should have been called with messages that include
        // the context message between system and user.
        $startRunInput = $this->capturedInput;

        $this->assertNotNull($startRunInput);

        $messages = $startRunInput->messages;
        $roles = array_map(static fn (AgentMessage $m) => $m->role, $messages);

        // Expected order: system, user-context, user
        $this->assertSame(['system', 'user-context', 'user'], $roles);

        // user-context message should have metadata
        $contextMessage = $messages[1];
        $this->assertSame('user-context', $contextMessage->role);
        $this->assertArrayHasKey('source', $contextMessage->metadata);
        $this->assertSame('agents_context', $contextMessage->metadata['source']);
        $this->assertArrayHasKey('files', $contextMessage->metadata);
        $this->assertCount(1, $contextMessage->metadata['files']);

        // Content should contain the XML structure
        $contextText = $contextMessage->content[0]['text'];
        $this->assertStringContainsString('<project_context>', $contextText);
        $this->assertStringContainsString('Test project instructions', $contextText);
        $this->assertStringContainsString('<project_instructions', $contextText);
    }

    public function testNoAgentsContextWhenNoFiles(): void
    {
        $client = $this->createClient();
        $request = new StartRunRequest(prompt: 'Hello');
        $client->start($request);

        $this->assertNotNull($this->capturedInput);

        $messages = $this->capturedInput->messages;
        $roles = array_map(static fn (AgentMessage $m) => $m->role, $messages);

        // Expected order: system, user (no user-context since no AGENTS.md)
        $this->assertSame(['system', 'user'], $roles);
    }

    public function testAgentsContextInjectedWithEmptyPrompt(): void
    {
        // Create AGENTS.md in the project temp dir
        file_put_contents($this->tmpDir.'/.hatfield/AGENTS.md', 'Empty prompt instructions');

        $client = $this->createClient();
        $request = new StartRunRequest(prompt: '');
        $client->start($request);

        $this->assertNotNull($this->capturedInput);

        $messages = $this->capturedInput->messages;
        $roles = array_map(static fn (AgentMessage $m) => $m->role, $messages);

        // Expected order: system, user-context (no user message since prompt is empty)
        $this->assertSame(['system', 'user-context'], $roles);

        // Verify user-context message has the AGENTS.md content
        $contextMessage = $messages[1];
        $this->assertSame('user-context', $contextMessage->role);
        $this->assertStringContainsString('Empty prompt instructions', $contextMessage->content[0]['text']);
    }

    public function testAgentsContextNotInjectedOnResume(): void
    {
        // Even with AGENTS.md present, resume should NOT inject context
        file_put_contents($this->tmpDir.'/.hatfield/AGENTS.md', 'Test project instructions');

        $client = $this->createClient();

        // Start once (triggers injection)
        $request = new StartRunRequest(prompt: 'Hello');
        $client->start($request);

        // Reset captured input to verify resume doesn't call start
        $this->capturedInput = null;
        $this->capturedContinue = false;

        // Resume — should NOT inject context
        $client->resume('test-run-123');

        // resume() calls runner->continue(), not start()
        // Captured input should be null (start() never called again)
        $this->assertNull($this->capturedInput);
        $this->assertTrue($this->capturedContinue);
    }

    private function createClient(): InProcessAgentSessionClient
    {
        // Use a mock runner that captures StartRunInput
        $homeDir = $this->tmpDir.'/home';
        mkdir($homeDir, 0777, true);

        $runner = new class($this) implements AgentRunnerInterface {
            public function __construct(private readonly AgentsContextInjectionTest $test)
            {
            }

            public function start(StartRunInput $input): string
            {
                $this->test->capturedInput = $input;

                return 'test-run-'.bin2hex(random_bytes(4));
            }

            public function continue(string $runId): void
            {
                $this->test->capturedContinue = true;
            }

            public function steer(string $runId, AgentMessage $message): void
            {
            }

            public function followUp(string $runId, AgentMessage $message): void
            {
            }

            public function answerHuman(string $runId, string $questionId, mixed $answer): void
            {
            }

            public function cancel(string $runId, ?string $reason = null): void
            {
            }
        };

        $toolRegistry = new ToolRegistry();

        $pathResolver = new SettingsPathResolver($this->projectDir, $homeDir);
        $templateRenderer = new StringTemplateRenderer();
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'test'),
            logging: new LoggingConfig(),
            cwd: $this->tmpDir,
        );

        $systemPromptBuilder = new SystemPromptBuilder(
            toolRegistry: $toolRegistry,
            pathResolver: $pathResolver,
            templateRenderer: $templateRenderer,
            appConfig: $appConfig,
            projectDir: $this->projectDir,
        );

        $discovery = new AgentsContextDiscovery(
            pathResolver: $pathResolver,
            appConfig: $appConfig,
        );

        $renderer = new AgentsContextRenderer();

        // Skills context builder (no-op for these tests)
        $skillsConfig = new SkillsConfig();
        $skillDiscovery = new SkillDiscovery(
            config: $skillsConfig,
            pathResolver: $pathResolver,
            appConfig: $appConfig,
        );
        $skillContextRenderer = new SkillContextRenderer();
        $skillsContextBuilder = new SkillsContextBuilder(
            discovery: $skillDiscovery,
            config: $skillsConfig,
            renderer: $skillContextRenderer,
        );

        return new InProcessAgentSessionClient(
            runner: $runner,
            eventStore: new class implements EventStoreInterface {
                public function append(RunEvent $event): void
                {
                }

                public function appendMany(array $events): void
                {
                }

                public function allFor(string $runId): array
                {
                    return [];
                }
            },
            mapper: new RuntimeEventMapper(),
            systemPromptBuilder: $systemPromptBuilder,
            agentsContextDiscovery: $discovery,
            agentsContextRenderer: $renderer,
            skillsContextBuilder: $skillsContextBuilder,
        );
    }

    private function rmdirRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            if ($entry->isDir()) {
                @rmdir((string) $entry);
            } else {
                @unlink((string) $entry);
            }
        }

        @rmdir($path);
    }
}
