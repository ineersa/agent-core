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
final class Gf05ChildAppendStructuralPermanentSubsetContractTest extends \PHPUnit\Framework\TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('gf05-append-structural');
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

    public function testChildAppendRendersPermanentSubsetPlaceholdersWithoutTextScrubbing(): void
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
        $registry->addDynamicTool(
            'mcp_dynamic',
            'GF05_DYNAMIC_PROVIDER_DESCRIPTION_MUST_NOT_LEAK',
            ['type' => 'object'],
            $this->handler(),
        );

        $benignContributor = 'GF05_BENIGN_CONTRIBUTOR_PROSE_KEEP_ME';
        $opaqueContributorCatalog = 'GF05_OPAQUE_FORK_CATALOG_DOC fork: parent-only launch';

        $systemPromptBuilder = new SystemPromptBuilder(
            toolRegistry: $registry,
            pathResolver: new SettingsPathResolver($this->tmpDir),
            templateRenderer: new StringTemplateRenderer(),
            appConfig: new AppConfig(tui: new TuiConfig(theme: 'test'), logging: new LoggingConfig(), cwd: $this->tmpDir),
            projectDir: \dirname(__DIR__, 4),
            promptContributorProvider: new class($benignContributor, $opaqueContributorCatalog) implements PromptContributorProviderInterface {
                public function __construct(
                    private readonly string $benign,
                    private readonly string $opaqueCatalog,
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
                        new class($this->opaqueCatalog) implements PromptContributorInterface {
                            public function __construct(private readonly string $opaqueCatalog)
                            {
                            }

                            public function contribute(): string
                            {
                                return $this->opaqueCatalog;
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
        $this->assertStringContainsString($benignContributor, $systemText);
        $this->assertStringContainsString($opaqueContributorCatalog, $systemText, 'Opaque contributor markdown must pass through unchanged.');
        $this->assertStringNotContainsString('DISALLOWED_FORK_PROMPT_LINE', $systemText);
        $this->assertStringNotContainsString('DISALLOWED_FORK_GUIDELINE_BLOCK', $systemText);
        $this->assertStringNotContainsString('GF05_DYNAMIC_PROVIDER_DESCRIPTION_MUST_NOT_LEAK', $systemText);
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
