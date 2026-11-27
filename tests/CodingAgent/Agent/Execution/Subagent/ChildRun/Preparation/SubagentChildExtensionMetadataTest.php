<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Preparation;

use Ineersa\AgentCore\Application\Handler\RunTracer;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Tests\Support\Builder\ToolCallBuilder;
use Ineersa\AgentCore\Tests\Support\PlatformCollaborators;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDTO;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Preparation\SubagentChildLaunchInputFactory;
use Ineersa\CodingAgent\Agent\Fork\ForkChildLaunchInputBuilder;
use Ineersa\CodingAgent\Agent\Fork\ForkLaunchTaskDTO;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\RawAwareToolCallArgumentResolver;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\MapToolArgumentsDescriber;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolver;
use Symfony\AI\Platform\Contract\JsonSchema\Factory;
use Symfony\Component\Clock\MonotonicClock;

/**
 * Test thesis: child launch persists effective extension allowlist in RunMetadata
 * and never leaks globally enabled optional extensions (OM isolation).
 */
#[Group('db')]
final class SubagentChildExtensionMetadataTest extends IsolatedKernelTestCase
{
    public function testUnavailableExtensionCauseReachesParentToolResult(): void
    {
        $factory = self::getContainer()->get(SubagentChildLaunchInputFactory::class);
        $handler = new class($factory) {
            public function __construct(private SubagentChildLaunchInputFactory $factory)
            {
            }

            public function __invoke(array $arguments): mixed
            {
                return $this->factory->buildPrepared(
                    identity: new ChildRunIdentityDTO('parent-missing', 'child-missing', 'agent_missing', 'scout', 'task', AgentArtifactKindEnum::Subagent),
                    definition: new AgentDefinitionDTO(name: 'scout', description: 'd', tools: ['read'], model: 'llama_cpp_test/test', extensions: ['MissingExtension'], inheritProjectContext: false, instructions: 'work'),
                    allowedTools: ['read'], mcp: ['mode' => 'none', 'tools' => []], parentModel: 'llama_cpp_test/test',
                );
            }
        };
        $registry = new ToolRegistry();
        $registry->registerTool(name: 'subagent', description: 'launch', parametersJsonSchema: [], handler: $handler, promptLine: 'subagent');
        $executor = new ToolExecutor(
            defaultMode: 'sequential', maxParallelism: 1,
            resultStore: new ToolExecutionResultStore(), toolbox: new RegistryBackedToolbox(
                $registry,
                new RawAwareToolCallArgumentResolver(new ToolCallArgumentResolver()),
                new Factory(new MapToolArgumentsDescriber()),
            ), contextAccessor: new StackToolExecutionContextAccessor(), toolSetResolver: PlatformCollaborators::toolSetResolver(), clock: new MonotonicClock(), tracer: new RunTracer(new NullLogger()),
        );
        $result = $executor->execute(ToolCallBuilder::create('missing-extension-call')->withToolName('subagent')->withArguments([])->build());
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('MissingExtension', $result->content[0]['text']);
        $this->assertStringContainsString('extensions.enabled', $result->content[0]['text']);
        $this->assertStringNotContainsString('An error occurred while executing tool', $result->content[0]['text']);
    }

    public function testSubagentMetadataPersistsAlwaysOnOnlyWhenFrontmatterOmitsExtensions(): void
    {
        $factory = self::getContainer()->get(SubagentChildLaunchInputFactory::class);
        \assert($factory instanceof SubagentChildLaunchInputFactory);

        $prepared = $factory->buildPrepared(
            identity: new ChildRunIdentityDTO(
                parentRunId: 'parent-ext-1',
                childRunId: 'child-ext-1',
                artifactId: 'agent_ext1',
                displayName: 'scout',
                taskSummary: 'task',
                artifactKind: AgentArtifactKindEnum::Subagent),
            definition: new AgentDefinitionDTO(
                name: 'scout',
                description: 'd',
                tools: ['read'],
                model: 'llama_cpp_test/test',
                extensions: null,
                inheritProjectContext: false,
                instructions: 'do work'),
            allowedTools: ['read'],
            mcp: ['mode' => 'none', 'tools' => []],
            parentModel: 'llama_cpp_test/test',
        );

        $extensions = $prepared->startRunInput->metadata?->extensions;
        $this->assertIsArray($extensions);
        $this->assertSame([SafeGuardExtension::class], $extensions);
        $this->assertNotContains(ObservationalMemoryExtension::class, $extensions);
    }

    public function testForkMetadataUsesAlwaysOnWithoutLeakingOptionalGlobals(): void
    {
        // IsolatedKernelTestCase boots with an isolated cwd that only sees
        // config/hatfield.defaults.yaml (always_on SafeGuard, enabled []).
        // Project .hatfield/settings.yaml Castor enabled is covered by unit
        // selection tests and project settings files, not this kernel cwd.
        $builder = self::getContainer()->get(ForkChildLaunchInputBuilder::class);
        \assert($builder instanceof ForkChildLaunchInputBuilder);

        $prepared = $builder->buildPrepared(
            identity: new ChildRunIdentityDTO(
                parentRunId: 'parent-fork-ext-1',
                childRunId: 'child-fork-ext-1',
                artifactId: 'agent_fork_ext1',
                displayName: 'fork',
                taskSummary: 'fork task',
                artifactKind: AgentArtifactKindEnum::Fork),
            task: new ForkLaunchTaskDTO(
                task: 'fork task',
                inheritedMessages: [],
                modelOverride: 'llama_cpp_test/test'),
            policy: [
                'tools' => ['read', 'bash'],
                'mcp' => ['mode' => 'none', 'tools' => []],
            ],
            parentModel: 'llama_cpp_test/test',
        );

        $extensions = $prepared->startRunInput->metadata?->extensions;
        $this->assertIsArray($extensions);
        $this->assertSame([SafeGuardExtension::class], $extensions);
        $this->assertNotContains(ObservationalMemoryExtension::class, $extensions);
    }
}
