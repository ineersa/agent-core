<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Agent\Artifact\AgentRetrieveArgumentsDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentArgumentsDTO;
use Ineersa\CodingAgent\Agent\Execution\SubagentTaskDTO;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\BashToolConfig;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolverInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Container wiring of ToolCallArgumentResolver: config/services.yaml injects
 * the app serializer (camel_case_to_snake_case name converter), so provider
 * keys like artifact_id must denormalize onto DTO properties like artifactId.
 */
final class ToolCallArgumentResolverContainerTest extends IsolatedKernelTestCase
{
    public function testContainerResolverDenormalizesSnakeCaseProviderKeys(): void
    {
        $resolver = self::getContainer()->get(ToolCallArgumentResolverInterface::class);

        $arguments = $resolver->resolveArguments(
            new Tool(
                reference: new ExecutionReference(SnakeCaseResolutionProbe::class),
                name: 'probe',
                description: 'Probe',
            ),
            new ToolCall('call-snake-container', 'probe', ['arguments' => ['artifact_id' => 'agent_abc', 'limit' => 5]]),
        );

        $this->assertArrayHasKey('arguments', $arguments);
        $dto = $arguments['arguments'];
        $this->assertInstanceOf(AgentRetrieveArgumentsDTO::class, $dto);
        $this->assertSame('agent_abc', $dto->artifactId);
        $this->assertNull($dto->agentRunId);
        $this->assertSame(5, $dto->limit);
    }

    public function testContainerResolverDenormalizesParallelSubagentTasksIntoDtos(): void
    {
        $resolver = self::getContainer()->get(ToolCallArgumentResolverInterface::class);

        $arguments = $resolver->resolveArguments(
            new Tool(
                reference: new ExecutionReference(SubagentResolutionProbe::class),
                name: 'probe',
                description: 'Probe',
            ),
            new ToolCall('call-subagent-container', 'probe', ['arguments' => ['tasks' => [['agent' => 'scout', 'task' => 'one'], ['agent' => 'reviewer', 'task' => 'two']]]]),
        );

        $this->assertArrayHasKey('arguments', $arguments);
        $dto = $arguments['arguments'];
        $this->assertInstanceOf(SubagentArgumentsDTO::class, $dto);
        $this->assertIsArray($dto->tasks);
        $this->assertCount(2, $dto->tasks);
        $this->assertContainsOnlyInstancesOf(SubagentTaskDTO::class, $dto->tasks);
        $this->assertSame('scout', $dto->tasks[0]->agent);
    }

    public function testContainerSchemaExposesConfiguredBashTimeoutBounds(): void
    {
        $config = self::getContainer()->get(BashToolConfig::class);
        $timeout = $this->toolboxParameterSchema('bash', 'timeout');

        // Settings-derived schema fragment: same BashToolConfig the runtime
        // BashTimeoutMax constraint consumes.
        $this->assertSame($config->maxTimeoutSeconds, $timeout['maximum']);
        $this->assertSame(
            \sprintf('Timeout in seconds (default: %d, max: %d). Use for commands that may hang.', $config->defaultTimeoutSeconds, $config->maxTimeoutSeconds),
            $timeout['description'],
        );
    }

    public function testContainerSchemaExposesConfiguredSubagentTasksLimit(): void
    {
        $config = self::getContainer()->get(AgentsConfig::class);
        $tasks = $this->toolboxParameterSchema('subagent', 'tasks');

        $this->assertSame($config->maxAgents, $tasks['maxItems']);
        $this->assertSame(1, $tasks['minItems']);
        $this->assertSame(
            \sprintf('Parallel tasks (max %d per call). Use instead of agent/task for parallel mode.', $config->maxAgents),
            $tasks['description'],
        );
    }

    public function testContainerValidatorRejectsBashTimeoutAboveConfiguredMax(): void
    {
        $config = self::getContainer()->get(BashToolConfig::class);
        $toolbox = new FaultTolerantToolbox(self::getContainer()->get(ToolboxInterface::class));

        $result = $toolbox->execute(new ToolCall('call-bash-max', 'bash', ['arguments' => ['command' => 'echo hi', 'timeout' => $config->maxTimeoutSeconds + 1]]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString(\sprintf('Timeout must not exceed %d seconds (%d provided).', $config->maxTimeoutSeconds, $config->maxTimeoutSeconds + 1), $message);
    }

    public function testContainerValidatorRejectsParallelSubagentTasksAboveConfiguredMax(): void
    {
        $config = self::getContainer()->get(AgentsConfig::class);
        $toolbox = new FaultTolerantToolbox(self::getContainer()->get(ToolboxInterface::class));

        $tasks = [];
        for ($i = 0; $i < $config->maxAgents + 1; ++$i) {
            $tasks[] = ['agent' => 'scout', 'task' => 't'.$i];
        }

        $result = $toolbox->execute(new ToolCall('call-subagent-max', 'subagent', ['arguments' => ['tasks' => $tasks]]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString(
            \sprintf('Parallel subagent execution supports at most %d agents per tool call, but %d tasks were requested.', $config->maxAgents, $config->maxAgents + 1),
            $message,
        );
    }

    public function testContainerValidatorEnforcesReadFileTargetClassConstraint(): void
    {
        // Production wiring proof: the app container validator resolves the
        // autowired ReadFileTargetValidator/EditFileTargetValidator/
        // ViewImageTargetValidator through the service-aware constraint
        // validator factory, and the real listener turns violations into
        // deterministic fault results.
        $toolbox = new FaultTolerantToolbox(self::getContainer()->get(ToolboxInterface::class));

        $result = $toolbox->execute(new ToolCall('call-read-missing', 'read', ['arguments' => ['path' => '/definitely/not/here.txt']]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('does not exist', $message);
        $this->assertStringContainsString('Check the file path and try again.', $message);
    }

    public function testContainerValidatorEnforcesViewImageTargetClassConstraint(): void
    {
        $toolbox = new FaultTolerantToolbox(self::getContainer()->get(ToolboxInterface::class));

        $result = $toolbox->execute(new ToolCall('call-view-missing', 'view_image', ['arguments' => ['path' => '/definitely/not/here.png']]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('does not exist or is not readable', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function toolboxParameterSchema(string $toolName, string $property): array
    {
        $toolbox = self::getContainer()->get(ToolboxInterface::class);
        foreach ($toolbox->getTools() as $tool) {
            if ($tool->getName() === $toolName) {
                $parameters = $tool->getParameters() ?? [];
                $propertySchema = $parameters['properties']['arguments']['properties'][$property] ?? null;
                $this->assertIsArray($propertySchema, \sprintf('Tool %s must expose a %s property schema.', $toolName, $property));

                return $propertySchema;
            }
        }

        $this->fail(\sprintf('Tool %s not registered in the container toolbox.', $toolName));
    }
}

final class SnakeCaseResolutionProbe
{
    public function __invoke(AgentRetrieveArgumentsDTO $arguments): string
    {
        return 'ok';
    }
}

final class SubagentResolutionProbe
{
    public function __invoke(SubagentArgumentsDTO $arguments): string
    {
        return 'ok';
    }
}
