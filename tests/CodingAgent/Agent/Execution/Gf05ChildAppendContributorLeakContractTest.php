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
            "Static append prologue.\n{available_tools_list}\n{registered_guidelines}",
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testChildAppendModeSelectivelyFiltersDisallowedToolDocumentation(): void
    {
        $registry = new ToolRegistry();
        $registry->registerTool('read', 'Read', ['type' => 'object'], $this->handler(), promptLine: 'ALLOWED_READ_PROMPT_LINE');
        $registry->registerTool(
            'fork',
            'Fork parent tool',
            ['type' => 'object'],
            $this->handler(),
            promptLine: 'DISALLOWED_FORK_PROMPT_LINE',
            promptGuidelines: ['DISALLOWED_FORK_GUIDELINE_BLOCK'],
        );

        $benignContributor = 'GF05_BENIGN_CONTRIBUTOR_PROSE_KEEP_ME';
        $disallowedContributorCatalog = 'GF05_DISALLOWED_FORK_CATALOG_DOC fork: parent-only launch';

        $systemPromptBuilder = new SystemPromptBuilder(
            toolRegistry: $registry,
            pathResolver: new SettingsPathResolver($this->tmpDir),
            templateRenderer: new StringTemplateRenderer(),
            appConfig: new AppConfig(tui: new TuiConfig(theme: 'test'), logging: new LoggingConfig(), cwd: $this->tmpDir),
            projectDir: \dirname(__DIR__, 4),
            promptContributorProvider: new class($benignContributor, $disallowedContributorCatalog) implements PromptContributorProviderInterface {
                public function __construct(
                    private readonly string $benign,
                    private readonly string $disallowedCatalog,
                ) {
                }

                public function promptContributors(): array
                {
                    return [
                        new class($this->benign) implements PromptContributorInterface {
                            public function __construct(private readonly string $benign)
                            {
                            }

                            public function contribute(): string
                            {
                                return $this->benign;
                            }
                        },
                        new class($this->disallowedCatalog) implements PromptContributorInterface {
                            public function __construct(private readonly string $disallowedCatalog)
                            {
                            }

                            public function contribute(): string
                            {
                                return $this->disallowedCatalog;
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

        $systemText = $result['systemPrompt'];
        $this->assertStringContainsString('ALLOWED_READ_PROMPT_LINE', $systemText);
        $this->assertStringContainsString($benignContributor, $systemText, 'Benign contributor prose must remain in child append system text.');
        $this->assertStringNotContainsString('DISALLOWED_FORK_PROMPT_LINE', $systemText);
        $this->assertStringNotContainsString('DISALLOWED_FORK_GUIDELINE_BLOCK', $systemText);
        $this->assertStringNotContainsString($disallowedContributorCatalog, $systemText, 'Disallowed fork catalog documentation must not leak into child system text.');
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
