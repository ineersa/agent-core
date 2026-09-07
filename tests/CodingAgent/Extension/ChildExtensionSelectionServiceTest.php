<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\CodingAgent\Agent\ChildExtensionSelectionService;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ChildExtensionsConfigDTO;
use Ineersa\CodingAgent\Config\ExtensionsConfig;
use Ineersa\CodingAgent\Config\ForksConfigDTO;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Hatfield\ExtensionApi\HatfieldExtensionInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Test thesis: child extension selection unions always_on with optional lists,
 * never leaks global extensions.enabled optionals, and fails closed when a
 * selected class is unavailable or failed to register.
 */
final class ChildExtensionSelectionServiceTest extends TestCase
{
    public function testSubagentUnionOrderDedupAndNoGlobalLeak(): void
    {
        $always = 'Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension';
        $optional = 'Ineersa\\HatfieldExt\\TaskWorkflow\\TaskWorkflowExtension';
        $globalOnly = 'Ineersa\\HatfieldExt\\FileRewind\\FileRewindExtension';

        $service = $this->service(
            agentsAlwaysOn: [$always, $optional],
            forksAlwaysOn: [$always],
            forksEnabled: [],
            globallyEnabled: [$always, $optional, $globalOnly],
        );

        $definition = $this->definition(extensions: [$optional, $always]);
        $effective = $service->resolveForSubagent($definition);

        $this->assertSame([$always, $optional], $effective);
        $this->assertNotContains($globalOnly, $effective);

        $omitted = $service->resolveForSubagent($this->definition(extensions: null));
        $this->assertSame([$always, $optional], $omitted);
    }

    public function testForkUnionUsesEnabledNotGlobal(): void
    {
        $always = 'Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension';
        $castor = 'Ineersa\\HatfieldExt\\CastorLlmMode\\CastorLlmModeExtension';
        $globalOnly = 'Ineersa\\HatfieldExt\\ObservationalMemory\\ObservationalMemoryExtension';

        $service = $this->service(
            agentsAlwaysOn: [$always],
            forksAlwaysOn: [$always],
            forksEnabled: [$castor],
            globallyEnabled: [$always, $castor, $globalOnly],
        );

        $this->assertSame([$always, $castor], $service->resolveForFork());
    }

    public function testAssertSelectedAvailableFailsWhenNotGloballyEnabled(): void
    {
        $always = 'Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension';
        $service = $this->service(
            agentsAlwaysOn: [$always],
            forksAlwaysOn: [$always],
            forksEnabled: [],
            globallyEnabled: [],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not in extensions.enabled');
        $service->assertSelectedAvailable([$always], 'test');
    }

    public function testAssertSelectedAvailableFailsWhenRegistrationFailed(): void
    {
        $failing = ChildExtensionSelectionFailingExtension::class;
        $service = $this->service(
            agentsAlwaysOn: [$failing],
            forksAlwaysOn: [$failing],
            forksEnabled: [],
            globallyEnabled: [$failing],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('failed to register');
        $service->assertSelectedAvailable([$failing], 'test');
    }

    public function testAssertSelectedAvailableSucceedsWhenLoaded(): void
    {
        $ok = ChildExtensionSelectionOkExtension::class;
        $service = $this->service(
            agentsAlwaysOn: [$ok],
            forksAlwaysOn: [$ok],
            forksEnabled: [],
            globallyEnabled: [$ok],
        );

        $service->assertSelectedAvailable([$ok], 'test');
        $this->addToAssertionCount(1);
    }

    /**
     * @param list<string> $agentsAlwaysOn
     * @param list<string> $forksAlwaysOn
     * @param list<string> $forksEnabled
     * @param list<string> $globallyEnabled
     */
    private function service(
        array $agentsAlwaysOn,
        array $forksAlwaysOn,
        array $forksEnabled,
        array $globallyEnabled,
    ): ChildExtensionSelectionService {
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            extensions: new ExtensionsConfig(enabled: $globallyEnabled),
            forks: new ForksConfigDTO(
                extensions: new ChildExtensionsConfigDTO(alwaysOn: $forksAlwaysOn, enabled: $forksEnabled),
            ),
            agents: new AgentsConfig(
                extensions: new ChildExtensionsConfigDTO(alwaysOn: $agentsAlwaysOn),
            ),
        );

        $manager = new ExtensionManager(
            $appConfig,
            new class implements ExtensionApiInterface {
                public function registerTool(\Ineersa\Hatfield\ExtensionApi\Tool\ToolRegistrationDTO $tool): void
                {
                }

                public function registerToolCallHook(\Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface $hook): void
                {
                }

                public function registerToolResultHook(\Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface $hook): void
                {
                }

                public function getSettings(string $key): array
                {
                    return [];
                }

                public function getCwd(): string
                {
                    return '/tmp';
                }

                public function exec(): \Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface
                {
                    throw new \LogicException('unused');
                }

                public function registerPromptContributor(\Ineersa\Hatfield\ExtensionApi\Prompt\PromptContributorInterface $contributor): void
                {
                }

                public function registerSkill(string $skillDirectory): void
                {
                }

                public function registerCommand(\Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO $definition, \Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface $handler): void
                {
                }

                public function registerToolCallRewriteHook(string $toolName, \Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface $hook): void
                {
                }

                public function registerAfterTurnCommitHook(\Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface $hook): void
                {
                }

                public function registerSessionStartHook(\Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookInterface $hook): void
                {
                }

                public function registerBeforeCompactionHook(\Ineersa\Hatfield\ExtensionApi\Compaction\BeforeCompactionHookInterface $hook): void
                {
                }

                public function agent(): \Ineersa\Hatfield\ExtensionApi\Agent\AgentRunnerInterface
                {
                    throw new \LogicException('unused');
                }

                public function sessionEvents(): \Ineersa\Hatfield\ExtensionApi\Session\SessionEventReaderInterface
                {
                    throw new \LogicException('unused');
                }

                public function registerExtensionAgentJobHandler(string $handlerId, \Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobHandlerInterface $handler): void
                {
                }

                public function dispatchExtensionAgentJob(\Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO $request): void
                {
                }
            },
            new NullLogger(),
            new EventDispatcher(),
        );

        return new ChildExtensionSelectionService($appConfig, $manager);
    }

    /** @param list<string>|null $extensions */
    private function definition(?array $extensions): AgentDefinitionDTO
    {
        return new AgentDefinitionDTO(
            name: 'scout',
            description: 'scout',
            tools: ['read'],
            extensions: $extensions,
        );
    }
}

final class ChildExtensionSelectionOkExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
    }
}

final class ChildExtensionSelectionFailingExtension implements HatfieldExtensionInterface
{
    public function register(ExtensionApiInterface $api): void
    {
        throw new \RuntimeException('boom');
    }
}
