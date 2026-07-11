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
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorProviderInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\AI\Platform\Message\TemplateRenderer\StringTemplateRenderer;

#[Group('gf-05-prompt-contract')]
final class Gf05ChildAppendContributorLeakContractTest extends \PHPUnit\Framework\TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('gf05-append-leak');
        TestDirectoryIsolation::ensureDirectory($this->tmpDir.'/.hatfield');
        file_put_contents(
            $this->tmpDir.'/.hatfield/APPEND_SYSTEM.md',
            'Static append {available_tools_list}',
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testChildAppendModeOmitsDisallowedForkFromSystemText(): void
    {
        $registry = new ToolRegistry();
        $registry->registerTool('read', 'Read', ['type' => 'object'], $this->handler(), promptLine: 'ALLOWED_READ_PROMPT_LINE');
        $registry->registerTool('fork', 'Fork parent tool', ['type' => 'object'], $this->handler(), promptLine: 'DISALLOWED_FORK_PROMPT_LINE');

        $systemPromptBuilder = new SystemPromptBuilder(
            toolRegistry: $registry,
            pathResolver: new SettingsPathResolver($this->tmpDir),
            templateRenderer: new StringTemplateRenderer(),
            appConfig: new AppConfig(tui: new TuiConfig(theme: 'test'), logging: new LoggingConfig(), cwd: $this->tmpDir),
            projectDir: \dirname(__DIR__, 4),
            promptContributorProvider: new class implements PromptContributorProviderInterface {
                public function promptContributors(): array
                {
                    return [
                        new class implements PromptContributorInterface {
                            public function contribute(): string
                            {
                                return 'GF05_CONTRIBUTOR_FORK_LEAK_MARKER';
                            }
                        },
                    ];
                }
            },
        );

        $builder = new AgentPromptBuilder($systemPromptBuilder);
        $def = new AgentDefinitionDTO(
            name: 'append-child',
            description: 'append',
            tools: ['read'],
            mcp: new McpPolicyDTO(mode: McpAgentModeEnum::None),
            instructions: 'Child.',
            systemPromptMode: SystemPromptModeEnum::Append,
        );

        $result = $builder->build($def, 'task', 'agent_x', ['read'], agentsMd: '');

        $this->assertStringContainsString('ALLOWED_READ_PROMPT_LINE', $result['systemPrompt']);
        $this->assertStringNotContainsString('DISALLOWED_FORK_PROMPT_LINE', $result['systemPrompt']);
        $this->assertStringNotContainsString('GF05_CONTRIBUTOR_FORK_LEAK_MARKER', $result['systemPrompt']);
    }

    private function handler(): ToolHandlerInterface
    {
        return new class implements ToolHandlerInterface {
            public function __invoke(array $arguments): string
            {
                return 'ok';
            }
        };
    }
}
