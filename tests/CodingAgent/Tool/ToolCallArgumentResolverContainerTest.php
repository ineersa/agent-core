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
 * the app serializer (camel_case_to_snake_case name converter). The DTO
 * argument boundary is canonical snake_case (artifact_id/agent_run_id), so
 * the reflected provider schema and the serializer accept the same keys.
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
            new ToolCall('call-snake-container', 'probe', ['artifact_id' => 'agent_abc', 'limit' => 5]),
        );

        $this->assertArrayHasKey('arguments', $arguments);
        $dto = $arguments['arguments'];
        $this->assertInstanceOf(AgentRetrieveArgumentsDTO::class, $dto);
        $this->assertSame('agent_abc', $dto->artifact_id);
        $this->assertNull($dto->agent_run_id);
        $this->assertSame(5, $dto->limit);
    }

    public function testContainerAgentRetrieveSchemaUsesSnakeCaseProviderKeys(): void
    {
        $parameters = $this->toolboxParameters('agent_retrieve');

        $properties = $parameters['properties'] ?? [];
        $this->assertIsArray($properties);
        $this->assertArrayHasKey('artifact_id', $properties, 'agent_retrieve provider schema must expose artifact_id.');
        $this->assertArrayHasKey('agent_run_id', $properties, 'agent_retrieve provider schema must expose agent_run_id.');
        $this->assertArrayNotHasKey('artifactId', $properties, 'agent_retrieve provider schema must not expose camelCase artifactId.');
        $this->assertArrayNotHasKey('agentRunId', $properties, 'agent_retrieve provider schema must not expose camelCase agentRunId.');
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
            new ToolCall('call-subagent-container', 'probe', ['tasks' => [['agent' => 'scout', 'task' => 'one'], ['agent' => 'reviewer', 'task' => 'two']]]),
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

        $this->assertSame(30, $config->defaultTimeoutSeconds);

        // Settings-derived schema fragment: same BashToolConfig the runtime
        // BashTimeoutMax constraint consumes.
        $this->assertSame($config->maxTimeoutSeconds, $timeout['maximum']);
        $this->assertSame(
            \sprintf('Timeout in seconds (default: %d, max: %d). Provide an explicit higher value for commands that need more than the default.', $config->defaultTimeoutSeconds, $config->maxTimeoutSeconds),
            $timeout['description'],
        );
    }

    public function testContainerSchemaExposesPositiveIntegerBoundsWithoutExclusiveMinimum(): void
    {
        // Assert\Range(min: 1) must produce modern `minimum: 1` — the draft-04
        // boolean `exclusiveMinimum: true` form (Assert\Positive) is rejected
        // by OpenAI/Codex. Regression for the provider-side tools[1].parameters
        // rejection on the child toolset.
        foreach (['bash' => ['timeout'], 'read' => ['offset', 'limit'], 'bg_status' => ['pid']] as $toolName => $properties) {
            foreach ($properties as $property) {
                $schema = $this->toolboxParameterSchema($toolName, $property);
                $this->assertSame(1, $schema['minimum'], \sprintf('Tool %s.%s must expose minimum: 1.', $toolName, $property));
                $this->assertArrayNotHasKey('exclusiveMinimum', $schema, \sprintf('Tool %s.%s must not expose a boolean exclusiveMinimum.', $toolName, $property));
            }
        }
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

        $result = $toolbox->execute(new ToolCall('call-bash-max', 'bash', ['command' => 'echo hi', 'timeout' => $config->maxTimeoutSeconds + 1]));

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

        $result = $toolbox->execute(new ToolCall('call-subagent-max', 'subagent', ['tasks' => $tasks]));

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

        $result = $toolbox->execute(new ToolCall('call-read-missing', 'read', ['path' => '/definitely/not/here.txt']));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('does not exist', $message);
        $this->assertStringContainsString('Check the file path and try again.', $message);
    }

    public function testContainerValidatorEnforcesViewImageTargetClassConstraint(): void
    {
        $toolbox = new FaultTolerantToolbox(self::getContainer()->get(ToolboxInterface::class));

        $result = $toolbox->execute(new ToolCall('call-view-missing', 'view_image', ['path' => '/definitely/not/here.png']));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('does not exist or is not readable', $message);
    }

    /**
     * Flat provider schema: typed DTO tools expose their DTO properties at the
     * Tool root (no {arguments: ...} envelope).
     *
     * @return array<string, mixed>
     */
    private function toolboxParameterSchema(string $toolName, string $property): array
    {
        $parameters = $this->toolboxParameters($toolName);
        $propertySchema = $parameters['properties'][$property] ?? null;
        $this->assertIsArray($propertySchema, \sprintf('Tool %s must expose a %s property schema.', $toolName, $property));

        return $propertySchema;
    }

    /**
     * @return array<string, mixed>
     */
    private function toolboxParameters(string $toolName): array
    {
        $toolbox = self::getContainer()->get(ToolboxInterface::class);
        foreach ($toolbox->getTools() as $tool) {
            if ($tool->getName() === $toolName) {
                $parameters = $tool->getParameters() ?? [];
                $this->assertIsArray($parameters, \sprintf('Tool %s must expose a parameter schema.', $toolName));

                return $parameters;
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
