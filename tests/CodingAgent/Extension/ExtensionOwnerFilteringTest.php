<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobDispatcher;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobRegistry;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Extension\ExtensionRegistrationContext;
use Ineersa\CodingAgent\Extension\ExtensionToolRegistryBridge;
use Ineersa\CodingAgent\Tests\Extension\Support\NoOpExtensionToolHandler;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Command\CommandRegistryInterface;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface;
use Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookResultDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Test thesis: owner tagging + allowlist filter covers tools, prompt contributors,
 * tool-call/result/rewrite/after-turn/before-compaction hooks, and extension-agent jobs.
 */
final class ExtensionOwnerFilteringTest extends TestCase
{
    private const OWNER_A = 'Ext\\OwnerA';
    private const OWNER_B = 'Ext\\OwnerB';

    public function testOwnerFilteringAcrossRegistrationSurfaces(): void
    {
        $hooks = new ExtensionHookRegistry();
        $tools = new ToolRegistry();
        $jobs = new ExtensionAgentJobRegistry();
        $bridge = $this->bridge($tools, $hooks, $jobs);

        ExtensionRegistrationContext::withOwner(self::OWNER_A, function () use ($bridge): void {
            $bridge->registerTool(new ToolRegistrationDTO(
                name: 'tool_a',
                description: 'A',
                parametersJsonSchema: [],
                handler: new NoOpExtensionToolHandler(),
            ));
            $bridge->registerToolCallHook($this->allowHook());
            $bridge->registerToolResultHook($this->resultHook());
            $bridge->registerPromptContributor($this->prompt('from-a'));
            $bridge->registerToolCallRewriteHook('bash', $this->rewriteHook());
            $bridge->registerAfterTurnCommitHook($this->afterTurnHook());
            $bridge->registerBeforeCompactionHook($this->beforeCompactionHook());
            $bridge->registerExtensionAgentJobHandler('job.a', $this->jobHandler());
        });

        ExtensionRegistrationContext::withOwner(self::OWNER_B, function () use ($bridge): void {
            $bridge->registerTool(new ToolRegistrationDTO(
                name: 'tool_b',
                description: 'B',
                parametersJsonSchema: [],
                handler: new NoOpExtensionToolHandler(),
            ));
            $bridge->registerToolCallHook($this->allowHook());
            $bridge->registerToolResultHook($this->resultHook());
            $bridge->registerPromptContributor($this->prompt('from-b'));
            $bridge->registerToolCallRewriteHook('bash', $this->rewriteHook());
            $bridge->registerAfterTurnCommitHook($this->afterTurnHook());
            $bridge->registerBeforeCompactionHook($this->beforeCompactionHook());
            $bridge->registerExtensionAgentJobHandler('job.b', $this->jobHandler());
        });

        $allowed = [self::OWNER_A];

        $this->assertSame(self::OWNER_A, $tools->toolDefinition('tool_a')?->extensionOwnerClass);
        $this->assertSame(self::OWNER_B, $tools->toolDefinition('tool_b')?->extensionOwnerClass);

        $this->assertCount(1, $hooks->toolCallHooks($allowed));
        $this->assertCount(1, $hooks->toolResultHooks($allowed));
        $this->assertCount(1, $hooks->promptContributors($allowed));
        $this->assertCount(1, $hooks->rewriteHooksForTool('bash', $allowed));
        $this->assertCount(1, $hooks->afterTurnCommitHooks($allowed));
        $this->assertCount(1, $hooks->beforeCompactionHooks($allowed));

        // Unfiltered parent path still sees both owners.
        $this->assertCount(2, $hooks->toolCallHooks(null));
        $this->assertCount(2, $hooks->promptContributors());

        $this->assertSame(self::OWNER_A, $jobs->ownerClass('job.a'));
        $this->assertSame(self::OWNER_B, $jobs->ownerClass('job.b'));
        $this->assertNotNull($jobs->get('job.a'));
        $this->assertNotNull($jobs->get('job.b'));
    }

    public function testCastorRewriteRemainsBeforePolicyOrderingWhenBothSelected(): void
    {
        $hooks = new ExtensionHookRegistry();
        $order = [];

        ExtensionRegistrationContext::withOwner('Castor', static function () use ($hooks, &$order): void {
            $hooks->addToolCallRewriteHook('bash', new class($order) implements ToolCallRewriteHookInterface {
                /** @param list<string> $order */
                public function __construct(private array &$order)
                {
                }

                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    $this->order[] = 'castor';

                    return $context->arguments;
                }
            });
        });

        ExtensionRegistrationContext::withOwner('SafeGuard', static function () use ($hooks): void {
            $hooks->addToolCallHook(new class implements ToolCallHookInterface {
                public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO
                {
                    return ToolCallDecisionDTO::allow();
                }
            });
        });

        $allowed = ['Castor', 'SafeGuard'];
        $rewrites = $hooks->rewriteHooksForTool('bash', $allowed);
        $this->assertCount(1, $rewrites);
        $rewrites[0]->rewriteArguments(new ToolCallContextDTO(
            toolCallId: 'tc',
            toolName: 'bash',
            arguments: ['command' => 'castor test'],
            orderIndex: 0,
        ));
        $this->assertSame(['castor'], $order);

        // Policy hooks are a separate list; registry keeps registration order for each surface.
        $this->assertCount(1, $hooks->toolCallHooks($allowed));
    }

    private function bridge(ToolRegistry $tools, ExtensionHookRegistry $hooks, ExtensionAgentJobRegistry $jobs): ExtensionToolRegistryBridge
    {
        return new ExtensionToolRegistryBridge(
            toolRegistry: $tools,
            hookRegistry: $hooks,
            appConfig: new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig()),
            execBridge: $this->createStub(ExecInterface::class),
            commandRegistry: $this->createStub(CommandRegistryInterface::class),
            agentRunner: $this->createStub(AgentRunnerInterface::class),
            sessionEventReader: $this->createStub(SessionEventReaderInterface::class),
            extensionAgentJobRegistry: $jobs,
            extensionAgentJobDispatcher: new ExtensionAgentJobDispatcher(
                $this->createStub(MessageBusInterface::class),
                new NullLogger(),
                'in-memory://',
            ),
            toolContextAccessor: new StackToolExecutionContextAccessor(),
        );
    }

    private function allowHook(): ToolCallHookInterface
    {
        return new class implements ToolCallHookInterface {
            public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO
            {
                return ToolCallDecisionDTO::allow();
            }
        };
    }

    private function resultHook(): ToolResultHookInterface
    {
        return new class implements ToolResultHookInterface {
            public function onToolResult(ToolResultContextDTO $context): ToolResultDecisionDTO
            {
                return ToolResultDecisionDTO::keep();
            }
        };
    }

    private function prompt(string $text): PromptContributorInterface
    {
        return new class($text) implements PromptContributorInterface {
            public function __construct(private string $text)
            {
            }

            public function contribute(): string
            {
                return $this->text;
            }
        };
    }

    private function rewriteHook(): ToolCallRewriteHookInterface
    {
        return new class implements ToolCallRewriteHookInterface {
            public function rewriteArguments(ToolCallContextDTO $context): ?array
            {
                return null;
            }
        };
    }

    private function afterTurnHook(): AfterTurnCommitHookInterface
    {
        return new class implements AfterTurnCommitHookInterface {
            public function onAfterTurnCommit(AfterTurnCommitHookContextDTO $context): void
            {
            }
        };
    }

    private function beforeCompactionHook(): BeforeCompactionHookInterface
    {
        return new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(BeforeCompactionHookContextDTO $context): BeforeCompactionHookResultDTO
            {
                return BeforeCompactionHookResultDTO::continue();
            }
        };
    }

    private function jobHandler(): ExtensionAgentJobHandlerInterface
    {
        return new class implements ExtensionAgentJobHandlerInterface {
            public function handle(
                \Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface $api,
                array $payload,
                ?string $jobId,
                ?string $correlationId,
            ): void {
            }
        };
    }
}
