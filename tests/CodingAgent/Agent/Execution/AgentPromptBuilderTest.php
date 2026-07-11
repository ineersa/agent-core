<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Definition\McpAgentModeEnum;
use Ineersa\CodingAgent\Agent\Definition\McpPolicyDTO;
use Ineersa\CodingAgent\Agent\Definition\SystemPromptModeEnum;
use Ineersa\CodingAgent\Agent\Execution\AgentPromptBuilder;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\TemplateRenderer\StringTemplateRenderer;

#[CoversClass(AgentPromptBuilder::class)]
final class AgentPromptBuilderTest extends TestCase
{
    private string $tmpDir;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('agent-prompt-builder');
        $this->projectDir = \dirname(__DIR__, 4);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testBuildIncludesChildHarnessToolsGuidelinesDateAndCwd(): void
    {
        $builder = $this->createPromptBuilder(['read', 'bash']);
        $def = $this->definition();

        $result = $builder->build(
            definition: $def,
            task: 'Run task',
            artifactId: 'agent_abc',
            allowedTools: ['read', 'bash'],
            agentsMd: '',
        );

        $this->assertStringContainsString('<available_tools>', $result['systemPrompt']);
        $this->assertStringContainsString('CHILD_READ_LINE_UNIQUE', $result['systemPrompt']);
        $this->assertStringContainsString('CHILD_BASH_LINE_UNIQUE', $result['systemPrompt']);
        $this->assertStringContainsString('<guidelines>', $result['systemPrompt']);
        $this->assertStringContainsString('CHILD_READ_GUIDE_UNIQUE', $result['systemPrompt']);
        $this->assertStringContainsString('Current date:', $result['systemPrompt']);
        $this->assertStringContainsString('Current working directory: '.$this->tmpDir, $result['systemPrompt']);
        $this->assertStringNotContainsString('<available_agents>', $result['systemPrompt']);
        $this->assertStringNotContainsString('PARENT_ONLY_TOOL', $result['systemPrompt']);
        $this->assertStringNotContainsString('PARENT_SYSTEM_UNIQUE', $result['systemPrompt']);
    }

    public function testBuildExcludesUnloadedToolsFromHarness(): void
    {
        $builder = $this->createPromptBuilder(['read']);
        $def = $this->definition();

        $result = $builder->build(
            definition: $def,
            task: 't',
            artifactId: 'agent_x',
            allowedTools: ['read'],
            agentsMd: '',
        );

        $this->assertStringContainsString('CHILD_READ_LINE_UNIQUE', $result['systemPrompt']);
        $this->assertStringNotContainsString('CHILD_BASH_LINE_UNIQUE', $result['systemPrompt']);
        $this->assertStringNotContainsString('- subagent:', $result['systemPrompt']);
    }

    public function testBuildInjectsSkillsContextBeforeContractAndTask(): void
    {
        $builder = $this->createPromptBuilder(['read']);
        $def = $this->definition();

        $result = $builder->build(
            definition: $def,
            task: 'Run task',
            artifactId: 'agent_abc',
            allowedTools: ['read'],
            agentsMd: '',
            skillsContext: '<skill name="testing" location="/x">SKILL_BODY_UNIQUE</skill>',
        );

        $roles = array_map(static fn ($m) => $m->role, $result['messages']);
        $this->assertSame(['system', 'user-context', 'user-context', 'user'], $roles);

        $this->assertSame('skills_context', $result['messages'][1]->metadata['source'] ?? null);
        $this->assertStringContainsString('SKILL_BODY_UNIQUE', (string) $result['messages'][1]->content[0]['text']);
        $this->assertSame('agent_child_contract', $result['messages'][2]->metadata['source'] ?? null);
    }

    public function testBuildIncludesAgentsMdAsUserContextMessage(): void
    {
        $builder = $this->createPromptBuilder(['read']);
        $def = $this->definition(instructions: 'Child instructions.');

        $result = $builder->build(
            definition: $def,
            task: 't',
            artifactId: 'agent_x',
            allowedTools: ['read'],
            agentsMd: '<project_context>AGENTS_MD_UNIQUE</project_context>',
        );

        $this->assertStringNotContainsString('AGENTS_MD_UNIQUE', $result['systemPrompt']);
        $this->assertStringContainsString('Child instructions.', $result['systemPrompt']);
        $this->assertSame('agents_context', $result['messages'][1]->metadata['source'] ?? null);
        $this->assertStringContainsString('AGENTS_MD_UNIQUE', (string) $result['messages'][1]->content[0]['text']);
    }

    public function testAppendModeIncludesAppendSystemWithChildPlaceholders(): void
    {
        TestDirectoryIsolation::ensureDirectory($this->tmpDir.'/.hatfield');
        file_put_contents(
            $this->tmpDir.'/.hatfield/APPEND_SYSTEM.md',
            'APPEND_MARKER tools=[{available_tools_list}] cwd={cwd}',
        );

        $builder = $this->createPromptBuilder(['read']);
        $def = $this->definition(systemPromptMode: SystemPromptModeEnum::Append);

        $result = $builder->build(
            definition: $def,
            task: 't',
            artifactId: 'agent_append',
            allowedTools: ['read'],
            agentsMd: '',
        );

        $this->assertStringContainsString('APPEND_MARKER', $result['systemPrompt']);
        $this->assertStringContainsString('CHILD_READ_LINE_UNIQUE', $result['systemPrompt']);
        $this->assertStringNotContainsString('PARENT_SYSTEM_UNIQUE', $result['systemPrompt']);
        $this->assertStringNotContainsString('{available_tools_list}', $result['systemPrompt']);
    }

    public function testReplaceModeDoesNotIncludeAppendSystem(): void
    {
        TestDirectoryIsolation::ensureDirectory($this->tmpDir.'/.hatfield');
        file_put_contents(
            $this->tmpDir.'/.hatfield/APPEND_SYSTEM.md',
            'APPEND_MARKER_SHOULD_NOT_APPEAR',
        );

        $builder = $this->createPromptBuilder(['read']);
        $def = $this->definition(systemPromptMode: SystemPromptModeEnum::Replace);

        $result = $builder->build(
            definition: $def,
            task: 't',
            artifactId: 'agent_replace',
            allowedTools: ['read'],
            agentsMd: '',
        );

        $this->assertStringNotContainsString('APPEND_MARKER_SHOULD_NOT_APPEAR', $result['systemPrompt']);
    }

    /**
     * @param list<string> $toolNames
     */
    private function createPromptBuilder(array $toolNames): AgentPromptBuilder
    {
        $registry = new ToolRegistry();
        $registry->registerTool(
            name: 'read',
            description: 'Read files',
            parametersJsonSchema: [],
            handler: $this->dummyHandler(),
            promptLine: '- read: CHILD_READ_LINE_UNIQUE',
            promptGuidelines: ['CHILD_READ_GUIDE_UNIQUE'],
        );
        $registry->registerTool(
            name: 'bash',
            description: 'Run shell',
            parametersJsonSchema: [],
            handler: $this->dummyHandler(),
            promptLine: '- bash: CHILD_BASH_LINE_UNIQUE',
            promptGuidelines: ['CHILD_BASH_GUIDE_UNIQUE'],
        );
        $registry->registerTool(
            name: 'subagent',
            description: 'Launch subagents',
            parametersJsonSchema: [],
            handler: $this->dummyHandler(),
            promptLine: '- subagent: PARENT_ONLY_TOOL',
            promptGuidelines: ['Never on child'],
        );

        $systemPromptBuilder = new SystemPromptBuilder(
            toolRegistry: $registry,
            pathResolver: new SettingsPathResolver($this->tmpDir),
            templateRenderer: new StringTemplateRenderer(),
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'test'),
                logging: new LoggingConfig(),
                cwd: $this->tmpDir,
            ),
            projectDir: $this->projectDir,
        );

        return new AgentPromptBuilder($systemPromptBuilder);
    }

    private function definition(
        string $instructions = 'Do the task.',
        SystemPromptModeEnum $systemPromptMode = SystemPromptModeEnum::Replace,
    ): AgentDefinitionDTO {
        return new AgentDefinitionDTO(
            name: 'test-agent',
            description: 'd',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: $instructions,
            systemPromptMode: $systemPromptMode,
        );
    }

    private function dummyHandler(): ToolHandlerInterface
    {
        return new class implements ToolHandlerInterface {
            public function __invoke(array $arguments = []): string
            {
                return 'ok';
            }
        };
    }
}
