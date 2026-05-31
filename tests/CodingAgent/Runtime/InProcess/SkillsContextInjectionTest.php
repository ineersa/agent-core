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
 * Integration tests for skills context injection in InProcessAgentSessionClient.
 *
 * Covers:
 * - Skills context injected on start when skill files exist
 * - Skills context NOT injected on resume
 * - No skills context when no skill files
 * - Correct message ordering: system, agents_context, skills_context, user
 *
 * @group skills
 */
final class SkillsContextInjectionTest extends TestCase
{
    public ?StartRunInput $capturedInput = null;
    public bool $capturedContinue = false;
    private string $projectDir;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->projectDir = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();

        $this->tmpDir = sys_get_temp_dir().'/skills_injection_test_'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir.'/.hatfield', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tmpDir);
    }

    public function testSkillsContextInjectedOnStart(): void
    {
        // Create a skill with a description (modelInvocable=true)
        $skillDir = $this->tmpDir.'/.hatfield/skills/castor';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: castor\ndescription: Runs Castor tasks\n---\n\n# Castor body");

        $client = $this->createClient();

        $request = new StartRunRequest(prompt: 'Hello');
        $client->start($request);

        $this->assertNotNull($this->capturedInput);

        $messages = $this->capturedInput->messages;
        $roles = array_map(static fn (AgentMessage $m) => $m->role, $messages);

        // Expected order: system, agents_context (none), skills_context, user
        $this->assertContains('user-context', $roles);

        // Find the user-context message with skills source
        $skillsMessage = null;
        foreach ($messages as $msg) {
            if ('user-context' === $msg->role && ($msg->metadata['source'] ?? '') === 'skills_context') {
                $skillsMessage = $msg;
                break;
            }
        }

        $this->assertNotNull($skillsMessage, 'Skills context message should exist');

        $contextText = $skillsMessage->content[0]['text'];
        $this->assertStringContainsString('<skills_instructions>', $contextText);
        $this->assertStringContainsString('<available_skills>', $contextText);
        $this->assertStringContainsString('<name>castor</name>', $contextText);
        $this->assertStringContainsString('<description>Runs Castor tasks</description>', $contextText);
    }

    public function testSkillsContextInjectedWithPreload(): void
    {
        // Create a skill
        $skillDir = $this->tmpDir.'/.hatfield/skills/castor';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: castor\ndescription: Runs Castor tasks\n---\n\n# Castor body\n\nReal content");

        $client = $this->createClient(preloadSkills: ['castor']);

        $request = new StartRunRequest(prompt: 'Hello');
        $client->start($request);

        $this->assertNotNull($this->capturedInput);

        $messages = $this->capturedInput->messages;

        // Find the skills context message
        $skillsMessage = null;
        foreach ($messages as $msg) {
            if ('user-context' === $msg->role && ($msg->metadata['source'] ?? '') === 'skills_context') {
                $skillsMessage = $msg;
                break;
            }
        }

        $this->assertNotNull($skillsMessage);

        $contextText = $skillsMessage->content[0]['text'];
        // Should have both available skills and preloaded skill block
        $this->assertStringContainsString('<available_skills>', $contextText);
        $this->assertStringContainsString('<skill name="castor"', $contextText);
        $this->assertStringContainsString('Real content', $contextText);
    }

    public function testNoSkillsContextWhenNoSkillFiles(): void
    {
        $client = $this->createClient();

        $request = new StartRunRequest(prompt: 'Hello');
        $client->start($request);

        $this->assertNotNull($this->capturedInput);

        $messages = $this->capturedInput->messages;

        // No skills context message should exist
        foreach ($messages as $msg) {
            if ('user-context' === $msg->role && ($msg->metadata['source'] ?? '') === 'skills_context') {
                $this->fail('Skills context should not be present when no skill files exist');
            }
        }

        // But there should still be system and user messages
        $roles = array_map(static fn (AgentMessage $m) => $m->role, $messages);
        $this->assertContains('system', $roles);
        $this->assertContains('user', $roles);
    }

    public function testSkillsContextNotInjectedOnResume(): void
    {
        $skillDir = $this->tmpDir.'/.hatfield/skills/castor';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: castor\ndescription: Runs Castor tasks\n---\n\nBody");

        $client = $this->createClient();

        // Start once (triggers injection)
        $client->start(new StartRunRequest(prompt: 'Hello'));

        // Reset captured state
        $this->capturedInput = null;
        $this->capturedContinue = false;

        // Resume — should NOT inject skills context
        $client->resume('test-run-456');

        $this->assertNull($this->capturedInput);
        $this->assertTrue($this->capturedContinue);
    }

    public function testContextOrderingSystemAgentsSkills(): void
    {
        // Create both AGENTS.md and a skill
        file_put_contents($this->tmpDir.'/.hatfield/AGENTS.md', 'Project instructions');

        $skillDir = $this->tmpDir.'/.hatfield/skills/castor';
        mkdir($skillDir, 0777, true);
        file_put_contents($skillDir.'/SKILL.md', "---\nname: castor\ndescription: Runs Castor tasks\n---\n\nBody");

        $client = $this->createClient();

        $request = new StartRunRequest(prompt: 'Hello');
        $client->start($request);

        $this->assertNotNull($this->capturedInput);

        $messages = $this->capturedInput->messages;

        // Determine all messages and their roles
        $roles = [];
        foreach ($messages as $msg) {
            $role = $msg->role;
            $source = $msg->metadata['source'] ?? '';
            if ('user-context' === $role && '' !== $source) {
                $roles[] = $role.'['.$source.']';
            } else {
                $roles[] = $role;
            }
        }

        // Expected order: system, user-context[agents_context], user-context[skills_context], user
        $this->assertSame(
            ['system', 'user-context[agents_context]', 'user-context[skills_context]', 'user'],
            $roles,
        );
    }

    private function createClient(
        array $preloadSkills = [],
    ): InProcessAgentSessionClient {
        $homeDir = $this->tmpDir.'/home';
        mkdir($homeDir, 0777, true);

        $runner = new class($this) implements AgentRunnerInterface {
            public function __construct(private readonly SkillsContextInjectionTest $test)
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

        $agentsDiscovery = new AgentsContextDiscovery(
            pathResolver: $pathResolver,
            appConfig: $appConfig,
        );

        $agentsRenderer = new AgentsContextRenderer();

        // Skills wiring
        $skillsConfig = new SkillsConfig(
            noSkills: false,
            skillsPaths: [],
            preloadSkills: $preloadSkills,
        );
        $skillDiscovery = new SkillDiscovery(
            config: $skillsConfig,
            pathResolver: $pathResolver,
            appConfig: $appConfig,
        );
        $skillRenderer = new SkillContextRenderer();
        $skillsContextBuilder = new SkillsContextBuilder(
            discovery: $skillDiscovery,
            config: $skillsConfig,
            renderer: $skillRenderer,
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
            agentsContextDiscovery: $agentsDiscovery,
            agentsContextRenderer: $agentsRenderer,
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
