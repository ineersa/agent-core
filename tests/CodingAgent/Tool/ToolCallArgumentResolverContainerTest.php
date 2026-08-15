<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Agent\Artifact\AgentRetrieveArgumentsDTO;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
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
}

final class SnakeCaseResolutionProbe
{
    public function __invoke(AgentRetrieveArgumentsDTO $arguments): string
    {
        return 'ok';
    }
}
