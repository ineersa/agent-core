<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ExtensionsConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Extension\ExtensionToolHandlerAdapter;
use Ineersa\CodingAgent\Extension\ExtensionToolRegistryBridge;
use Ineersa\CodingAgent\Extension\Model\ExtensionModelCaller;
use Ineersa\CodingAgent\Tests\Extension\Support\NoOpExtensionToolHandler;
use Ineersa\CodingAgent\Tests\Extension\Support\RecordingExtensionToolHandler;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\ToolRegistryInterface;
use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;
use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\CommandRegistryInterface;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ExtensionToolHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ExtensionToolRegistryBridge — the adapter that maps public
 * ExtensionApi ToolRegistrationDTOs into the CodingAgent ToolRegistry
 * permanent tool registrations.
 */
final class ExtensionToolRegistryBridgeTest extends TestCase
{
    // ── Basic registration flow ──

    public function testRegisterToolForwardsToRegistry(): void
    {
        $registry = new ToolRegistry();
        $bridge = $this->bridgeFor($registry);

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'ext_tool',
            description: 'An extension-provided tool',
            parametersJsonSchema: ['type' => 'object', 'properties' => ['foo' => ['type' => 'string']]],
            handler: new NoOpExtensionToolHandler(),
            promptSummary: 'ext_tool: do extension stuff',
            promptGuidelines: ['Use ext_tool for extension operations.'],
        ));

        $names = $registry->activeToolNames();
        $this->assertContains('ext_tool', $names);

        $lines = $registry->permanentToolLines();
        $this->assertContains('ext_tool: do extension stuff', $lines);

        $guidelines = $registry->permanentGuidelines();
        $this->assertContains('Use ext_tool for extension operations.', $guidelines);
    }

    public function testRegisterToolDerivesPromptLineFromNameAndDescription(): void
    {
        $registry = new ToolRegistry();
        $bridge = $this->bridgeFor($registry);

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'my_tool',
            description: 'My custom tool',
            parametersJsonSchema: [],
            handler: new NoOpExtensionToolHandler(),
            // promptSummary intentionally omitted
        ));

        $lines = $registry->permanentToolLines();
        $this->assertContains('my_tool: My custom tool', $lines);
    }

    public function testRegisterToolWithoutGuidelines(): void
    {
        $registry = new ToolRegistry();
        $bridge = $this->bridgeFor($registry);

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'simple_tool',
            description: 'A simple tool',
            parametersJsonSchema: [],
            handler: new NoOpExtensionToolHandler(),
        ));

        $this->assertContains('simple_tool', $registry->activeToolNames());
        $this->assertSame([], $registry->permanentGuidelines());
    }

    public function testMultipleRegistrationsOrderPreserved(): void
    {
        $registry = new ToolRegistry();
        $bridge = $this->bridgeFor($registry);

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'tool_a', description: 'First', parametersJsonSchema: [], handler: new NoOpExtensionToolHandler(),
            promptSummary: 'tool_a: first',
        ));
        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'tool_b', description: 'Second', parametersJsonSchema: [], handler: new NoOpExtensionToolHandler(),
            promptSummary: 'tool_b: second',
        ));
        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'tool_c', description: 'Third', parametersJsonSchema: [], handler: new NoOpExtensionToolHandler(),
            promptSummary: 'tool_c: third',
        ));

        $this->assertSame(['tool_a', 'tool_b', 'tool_c'], $registry->activeToolNames());
        $this->assertSame(
            ['tool_a: first', 'tool_b: second', 'tool_c: third'],
            $registry->permanentToolLines(),
        );
    }

    // ── Duplicate handling via ToolRegistry ──

    public function testDuplicateIsIdempotent(): void
    {
        $registry = new ToolRegistry();
        $bridge = $this->bridgeFor($registry);

        $dto = new ToolRegistrationDTO(
            name: 'dup_tool', description: 'Duplicate', parametersJsonSchema: [], handler: new NoOpExtensionToolHandler(),
            promptSummary: 'dup_tool: description',
        );

        $bridge->registerTool($dto);
        $bridge->registerTool($dto); // identical re-registration

        $this->assertCount(1, $registry->activeToolNames());
        $this->assertCount(1, $registry->permanentToolLines());
    }

    // ── Handler passthrough ──

    public function testHandlerPassthrough(): void
    {
        $registry = new ToolRegistry();
        $bridge = $this->bridgeFor($registry);

        $handler = new RecordingExtensionToolHandler();

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'callable_tool', description: 'Callable handler', parametersJsonSchema: [], handler: $handler,
        ));

        $def = $registry->toolDefinition('callable_tool');
        $this->assertNotNull($def);
        $this->assertInstanceOf(ExtensionToolHandlerAdapter::class, $def->handler);

        $result = ($def->handler)(['foo' => 'bar']);
        $this->assertSame('extension handler result', $result);
        $this->assertSame([['foo' => 'bar']], $handler->invocations);
    }

    // ── Handler validation ──

    public function testHandlerMustImplementExtensionToolHandlerInterface(): void
    {
        $this->expectException(\TypeError::class);

        new ToolRegistrationDTO(
            name: 'bad_handler_tool', description: 'Bad handler', parametersJsonSchema: [], handler: new \stdClass(),
        );
    }

    public function testNullHandlerIsRejectedByDtoType(): void
    {
        $this->expectException(\TypeError::class);

        new ToolRegistrationDTO(
            name: 'null_handler_tool', description: 'Null handler', parametersJsonSchema: [], handler: null,
        );
    }

    // ── Guideline deduplication via ToolRegistry ──

    public function testGuidelineDeduplication(): void
    {
        $registry = new ToolRegistry();
        $bridge = $this->bridgeFor($registry);

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'tool_x', description: 'X', parametersJsonSchema: [], handler: new NoOpExtensionToolHandler(),
            promptGuidelines: ['Guideline A', 'Guideline B'],
            promptSummary: 'tool_x: X',
        ));

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'tool_y', description: 'Y', parametersJsonSchema: [], handler: new NoOpExtensionToolHandler(),
            promptGuidelines: ['Guideline B', 'Guideline C'],
            promptSummary: 'tool_y: Y',
        ));

        // Deduped, first occurrence position preserved
        $this->assertSame(
            ['Guideline A', 'Guideline B', 'Guideline C'],
            $registry->permanentGuidelines(),
        );
    }

    // ── Error propagation from ToolRegistry ──

    public function testEmptyNameThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tool name and description must be non-empty strings');

        $registry = new ToolRegistry();
        $bridge = $this->bridgeFor($registry);

        $bridge->registerTool(new ToolRegistrationDTO(
            name: '', description: 'Has name but empty', parametersJsonSchema: [], handler: new NoOpExtensionToolHandler(),
        ));
    }

    public function testEmptyDescriptionThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tool name and description must be non-empty strings');

        $registry = new ToolRegistry();
        $bridge = $this->bridgeFor($registry);

        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'some_tool', description: '', parametersJsonSchema: [], handler: new NoOpExtensionToolHandler(),
        ));
    }

    // ── ToolRegistryInterface contract adherence ──

    public function testAcceptsAnyToolRegistryImplementation(): void
    {
        $mockHandler = $this->createStub(ExtensionToolHandlerInterface::class);

        $mock = $this->createMock(ToolRegistryInterface::class);
        $mock->expects($this->once())
            ->method('registerTool')
            ->with(
                'mocked_tool',
                'Mocked description',
                ['type' => 'object'],
                $this->isInstanceOf(ExtensionToolHandlerAdapter::class),
                'mocked_tool: Mocked description',
                ['Guideline'],
            );

        $bridge = $this->bridgeFor($mock);
        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'mocked_tool',
            description: 'Mocked description',
            parametersJsonSchema: ['type' => 'object'],
            handler: $mockHandler,
            // promptSummary omitted → derived from name + description
            promptGuidelines: ['Guideline'],
        ));
    }

    // ── Hook registration via ExtensionToolRegistryBridge ──

    public function testRegisterToolCallHookForwardsToRegistry(): void
    {
        $hookRegistry = new ExtensionHookRegistry();
        $bridge = $this->bridgeFor(new ToolRegistry(), hookRegistry: $hookRegistry);

        $hook = $this->dummyToolCallHook();
        $bridge->registerToolCallHook($hook);

        $this->assertSame([$hook], $hookRegistry->toolCallHooks());
    }

    public function testRegisterToolResultHookForwardsToRegistry(): void
    {
        $hookRegistry = new ExtensionHookRegistry();
        $bridge = $this->bridgeFor(new ToolRegistry(), hookRegistry: $hookRegistry);

        $hook = $this->dummyToolResultHook();
        $bridge->registerToolResultHook($hook);

        $this->assertSame([$hook], $hookRegistry->toolResultHooks());
    }

    public function testHookRegistrationOrderPreserved(): void
    {
        $hookRegistry = new ExtensionHookRegistry();
        $bridge = $this->bridgeFor(new ToolRegistry(), hookRegistry: $hookRegistry);

        $hookA = $this->dummyToolCallHook('hook_a');
        $hookB = $this->dummyToolCallHook('hook_b');
        $hookC = $this->dummyToolCallHook('hook_c');

        $bridge->registerToolCallHook($hookA);
        $bridge->registerToolCallHook($hookB);
        $bridge->registerToolCallHook($hookC);

        $this->assertSame([$hookA, $hookB, $hookC], $hookRegistry->toolCallHooks());
    }

    public function testToolResultHookRegistrationOrderPreserved(): void
    {
        $hookRegistry = new ExtensionHookRegistry();
        $bridge = $this->bridgeFor(new ToolRegistry(), hookRegistry: $hookRegistry);

        $hookA = $this->dummyToolResultHook('result_a');
        $hookB = $this->dummyToolResultHook('result_b');

        $bridge->registerToolResultHook($hookA);
        $bridge->registerToolResultHook($hookB);

        $this->assertSame([$hookA, $hookB], $hookRegistry->toolResultHooks());
    }

    public function testHooksCoexistWithToolRegistration(): void
    {
        $registry = new ToolRegistry();
        $hookRegistry = new ExtensionHookRegistry();
        $bridge = $this->bridgeFor($registry, $hookRegistry);

        // Register a tool
        $bridge->registerTool(new ToolRegistrationDTO(
            name: 'coexist_tool',
            description: 'Tool that coexists with hooks',
            parametersJsonSchema: [],
            handler: new NoOpExtensionToolHandler(),
        ));

        // Register hooks
        $callHook = $this->dummyToolCallHook('coexist_call');
        $resultHook = $this->dummyToolResultHook('coexist_result');
        $bridge->registerToolCallHook($callHook);
        $bridge->registerToolResultHook($resultHook);

        // Verify tools work
        $this->assertContains('coexist_tool', $registry->activeToolNames());

        // Verify hooks are stored
        $this->assertSame([$callHook], $hookRegistry->toolCallHooks());
        $this->assertSame([$resultHook], $hookRegistry->toolResultHooks());
    }

    public function testSharedHookRegistryAcrossMultipleExtensions(): void
    {
        $hookRegistry = new ExtensionHookRegistry();
        $bridge = $this->bridgeFor(new ToolRegistry(), hookRegistry: $hookRegistry);

        // Simulate two extensions each registering hooks
        $ext1Hook = $this->dummyToolCallHook('ext1');
        $ext2Hook = $this->dummyToolCallHook('ext2');

        $bridge->registerToolCallHook($ext1Hook);
        $bridge->registerToolCallHook($ext2Hook);

        $this->assertCount(2, $hookRegistry->toolCallHooks());
        $this->assertSame($ext1Hook, $hookRegistry->toolCallHooks()[0]);
        $this->assertSame($ext2Hook, $hookRegistry->toolCallHooks()[1]);
    }

    // ── getSettings / getCwd via AppConfig ──

    public function testGetSettingsReturnsExtensionSettingsByKey(): void
    {
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            extensions: new ExtensionsConfig(
                settings: ['safe_guard' => ['allow_command_patterns' => ['ls -la']]],
            ),
            cwd: '/home/project',
        );

        $bridge = $this->bridgeFor(new ToolRegistry(), appConfig: $appConfig);

        $settings = $bridge->getSettings('safe_guard');
        $this->assertSame(['allow_command_patterns' => ['ls -la']], $settings);
    }

    public function testGetSettingsReturnsEmptyForMissingKey(): void
    {
        $bridge = $this->bridgeFor(new ToolRegistry());

        $this->assertSame([], $bridge->getSettings('nonexistent'));
    }

    public function testGetCwdReturnsFromAppConfig(): void
    {
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            cwd: '/home/some-project',
        );

        $bridge = $this->bridgeFor(new ToolRegistry(), appConfig: $appConfig);

        $this->assertSame('/home/some-project', $bridge->getCwd());
    }

    // ── NEW: exec() ──

    public function testExecReturnsExecInterface(): void
    {
        $execResult = new ExecResultDTO(stdout: 'hello', stderr: '', exitCode: 0);
        $execBridge = $this->dummyExecBridge($execResult);

        $bridge = $this->bridgeFor(new ToolRegistry(), execBridge: $execBridge);
        $execApi = $bridge->exec();

        $result = $execApi->exec('echo', ['hello']);
        $this->assertSame('hello', $result->stdout);
        $this->assertSame(0, $result->exitCode);
    }

    // ── NEW: registerPromptContributor() ──

    public function testRegisterPromptContributorStoresInHookRegistry(): void
    {
        $hookRegistry = new ExtensionHookRegistry();
        $bridge = $this->bridgeFor(new ToolRegistry(), hookRegistry: $hookRegistry);

        $contributor = new readonly class implements PromptContributorInterface {
            public function contribute(): string
            {
                return '# Task workflow rules';
            }
        };

        $bridge->registerPromptContributor($contributor);

        $this->assertSame([$contributor], $hookRegistry->promptContributors());
    }

    public function testRegisterMultiplePromptContributorsOrderPreserved(): void
    {
        $hookRegistry = new ExtensionHookRegistry();
        $bridge = $this->bridgeFor(new ToolRegistry(), hookRegistry: $hookRegistry);

        $c1 = new readonly class implements PromptContributorInterface {
            public function contribute(): string
            {
                return 'first';
            }
        };
        $c2 = new readonly class implements PromptContributorInterface {
            public function contribute(): string
            {
                return 'second';
            }
        };

        $bridge->registerPromptContributor($c1);
        $bridge->registerPromptContributor($c2);

        $this->assertSame([$c1, $c2], $hookRegistry->promptContributors());
    }

    // ── NEW: registerCommand() ──

    public function testRegisterCommandForwardsToCommandRegistry(): void
    {
        $cmdRegistry = $this->dummyCommandRegistry();
        $bridge = $this->bridgeFor(new ToolRegistry(), commandRegistry: $cmdRegistry);

        $definition = new CommandDefinitionDTO(
            name: 'tasks',
            aliases: ['t'],
            description: 'List tasks',
            usage: '/tasks [filter]',
            acceptsArguments: true,
        );

        $handler = new readonly class implements ExtensionCommandHandlerInterface {
            public function handle(string $args, CommandContextInterface $context): void
            {
            }
        };

        $bridge->registerCommand($definition, $handler);

        $this->assertCount(1, $cmdRegistry->registered);
        $this->assertSame($definition, $cmdRegistry->registered[0]['definition']);
        $this->assertSame($handler, $cmdRegistry->registered[0]['handler']);
    }

    // ── NEW: registerToolCallRewriteHook() ──

    public function testRegisterToolCallRewriteHookStoresInHookRegistry(): void
    {
        $hookRegistry = new ExtensionHookRegistry();
        $bridge = $this->bridgeFor(new ToolRegistry(), hookRegistry: $hookRegistry);

        $hook = new readonly class implements ToolCallRewriteHookInterface {
            public function rewriteArguments(ToolCallContextDTO $context): ?array
            {
                $args = $context->arguments;
                $args['rewritten'] = true;

                return $args;
            }
        };

        $bridge->registerToolCallRewriteHook('bash', $hook);

        $hooks = $hookRegistry->rewriteHooksForTool('bash');
        $this->assertCount(1, $hooks);
        $this->assertSame($hook, $hooks[0]);
    }

    public function testRegisterToolCallRewriteHookWildcard(): void
    {
        $hookRegistry = new ExtensionHookRegistry();
        $bridge = $this->bridgeFor(new ToolRegistry(), hookRegistry: $hookRegistry);

        $hook = new readonly class implements ToolCallRewriteHookInterface {
            public function rewriteArguments(ToolCallContextDTO $context): ?array
            {
                return $context->arguments;
            }
        };

        $bridge->registerToolCallRewriteHook('*', $hook);

        $hooks = $hookRegistry->rewriteHooksForTool('bash');
        $this->assertCount(1, $hooks);
        $this->assertSame($hook, $hooks[0]);
    }

    public function testRegisterToolCallRewriteHookSpecificAndWildcardBothReturned(): void
    {
        $hookRegistry = new ExtensionHookRegistry();
        $bridge = $this->bridgeFor(new ToolRegistry(), hookRegistry: $hookRegistry);

        $specific = new readonly class implements ToolCallRewriteHookInterface {
            public function rewriteArguments(ToolCallContextDTO $context): ?array
            {
                $args = $context->arguments;
                $args['from'] = 'specific';

                return $args;
            }
        };

        $wildcard = new readonly class implements ToolCallRewriteHookInterface {
            public function rewriteArguments(ToolCallContextDTO $context): ?array
            {
                $args = $context->arguments;
                $args['from'] = 'wildcard';

                return $args;
            }
        };

        $bridge->registerToolCallRewriteHook('bash', $specific);
        $bridge->registerToolCallRewriteHook('*', $wildcard);

        $hooks = $hookRegistry->rewriteHooksForTool('bash');
        $this->assertCount(2, $hooks);
        // Specific hooks first, then wildcard
        $this->assertSame($specific, $hooks[0]);
        $this->assertSame($wildcard, $hooks[1]);
    }

    /* ───────── Private helpers ───────── */

    private function testAppConfig(): AppConfig
    {
        return new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            cwd: getcwd() ?: '/',
        );
    }

    private function bridgeFor(
        ToolRegistryInterface $toolRegistry,
        ?ExtensionHookRegistry $hookRegistry = null,
        ?AppConfig $appConfig = null,
        ?ExecInterface $execBridge = null,
        ?CommandRegistryInterface $commandRegistry = null,
        ?SessionEventReaderInterface $sessionEventReader = null,
        ?ExtensionModelCaller $modelCaller = null,
    ): ExtensionToolRegistryBridge {
        return new ExtensionToolRegistryBridge(
            $toolRegistry,
            $hookRegistry ?? new ExtensionHookRegistry(),
            $appConfig ?? $this->testAppConfig(),
            $execBridge ?? $this->dummyExecBridge(),
            $commandRegistry ?? $this->dummyCommandRegistry(),
            $sessionEventReader ?? $this->createStub(SessionEventReaderInterface::class),
            $modelCaller ?? new ExtensionModelCaller(
                $this->createStub(\Symfony\AI\Platform\PlatformInterface::class),
                new \Psr\Log\NullLogger(),
            ),
        );
    }

    private function dummyToolCallHook(string $label = 'test'): ToolCallHookInterface
    {
        return new class($label) implements ToolCallHookInterface {
            public function __construct(private readonly string $label)
            {
            }

            public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO
            {
                return ToolCallDecisionDTO::allow();
            }

            public function label(): string
            {
                return $this->label;
            }
        };
    }

    private function dummyToolResultHook(string $label = 'test'): ToolResultHookInterface
    {
        return new class($label) implements ToolResultHookInterface {
            public function __construct(private readonly string $label)
            {
            }

            public function onToolResult(ToolResultContextDTO $context): ToolResultDecisionDTO
            {
                return ToolResultDecisionDTO::keep();
            }

            public function label(): string
            {
                return $this->label;
            }
        };
    }

    private function dummyExecBridge(?ExecResultDTO $result = null): ExecInterface
    {
        return new readonly class($result) implements ExecInterface {
            public function __construct(
                private ?ExecResultDTO $result,
            ) {
            }

            public function exec(string $command, array $args = [], ?ExecOptionsDTO $options = null): ExecResultDTO
            {
                return $this->result ?? new ExecResultDTO('', '', 0);
            }
        };
    }

    private function dummyCommandRegistry(): CommandRegistryInterface
    {
        return new class implements CommandRegistryInterface {
            /** @var list<array{definition: CommandDefinitionDTO, handler: ExtensionCommandHandlerInterface}> */
            public array $registered;

            public function __construct()
            {
                $this->registered = [];
            }

            public function register(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void
            {
                $this->registered[] = ['definition' => $definition, 'handler' => $handler];
            }
        };
    }
}
