<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Agent\Fork\ForkExecutionServiceInterface;
use Ineersa\CodingAgent\Agent\Fork\ForkRuntimeConfigResolver;
use Ineersa\CodingAgent\Agent\Tool\ForkToolDefinitionBuilder;
use Ineersa\CodingAgent\Agent\Tool\ForkToolHandler;
use Ineersa\CodingAgent\Config\ForksConfigDTO;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

#[CoversClass(ForkToolHandler::class)]
#[CoversClass(ForkToolDefinitionBuilder::class)]
final class ForkToolContractTest extends TestCase
{
    public function testToolSchemaRequiresTaskAndOptionalModelThinking(): void
    {
        $handler = new ForkToolHandler(
            new StackToolExecutionContextAccessor(),
            new ToolRuntime(new StackToolExecutionContextAccessor()),
            new NarrowExecutionServiceLocator(new FakeForkExecutionService(new DeferredToolCompletionOutcome('x'))),
        );
        $definition = ForkToolDefinitionBuilder::build($handler);
        $schema = $definition->parametersJsonSchema;

        $this->assertSame(['task'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(ModelResolver::LEVELS, $schema['properties']['thinking']['enum']);
    }

    public function testInvokeReturnsDeferredOutcomeAndDelegatesParameters(): void
    {
        $fake = new FakeForkExecutionService(new DeferredToolCompletionOutcome('deferred-fork-1'));
        $accessor = new StackToolExecutionContextAccessor();
        $handler = new ForkToolHandler($accessor, new ToolRuntime($accessor), new NarrowExecutionServiceLocator($fake));
        $context = new ToolContext(
            runId: 'parent-1',
            turnNo: 2,
            toolCallId: 'call-1',
            toolName: 'fork',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 30,
            orderIndex: 0,
        );

        $outcome = $accessor->with($context, static fn () => $handler->__invoke([
            'task' => '  Do work  ',
            'model' => 'provider/model',
            'thinking' => 'high',
        ]));

        $this->assertSame('deferred-fork-1', $outcome->deferredId);
        $this->assertSame('Do work', $fake->lastTask);
        $this->assertSame('provider/model', $fake->lastModelOverride);
        $this->assertSame('high', $fake->lastReasoningOverride);
    }

    public function testInvalidThinkingThrowsToolCallException(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $handler = new ForkToolHandler(
            $accessor,
            new ToolRuntime($accessor),
            new NarrowExecutionServiceLocator(new FakeForkExecutionService(new DeferredToolCompletionOutcome('x'))),
        );
        $context = new ToolContext(
            runId: 'parent-1',
            turnNo: 1,
            toolCallId: 'call-2',
            toolName: 'fork',
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 30,
            orderIndex: 0,
        );

        try {
            $accessor->with($context, static fn () => $handler->__invoke(['task' => 'ok', 'thinking' => 'invalid']));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('thinking must be one of', $e->getMessage());
        }
    }

    public function testConfigResolverPrecedence(): void
    {
        $resolver = new ForkRuntimeConfigResolver(new ForksConfigDTO(model: 'forks/model', thinkingLevel: 'low'));
        $resolved = $resolver->resolve(
            explicitModel: null,
            explicitThinking: 'high',
            parentModel: 'parent/model',
            parentReasoning: 'medium',
        );
        $this->assertSame('forks/model', $resolved->model);
        $this->assertSame('high', $resolved->thinking);

        $resolved2 = $resolver->resolve('explicit/model', null, 'parent/model', 'medium');
        $this->assertSame('explicit/model', $resolved2->model);
        $this->assertSame('low', $resolved2->thinking);
    }

    public function testPromptGuidelinesAndParallelModeExposeSafetyGuidance(): void
    {
        // Thesis C: fork definition is Parallel and exposes implementation-delegation,
        // no-same-worktree, and max-3 concurrent safety/load guidelines.
        $handler = new ForkToolHandler(
            new StackToolExecutionContextAccessor(),
            new ToolRuntime(new StackToolExecutionContextAccessor()),
            new NarrowExecutionServiceLocator(new FakeForkExecutionService(new DeferredToolCompletionOutcome('x'))),
        );
        $definition = ForkToolDefinitionBuilder::build($handler);
        $joined = implode("\n", $definition->promptGuidelines);

        $this->assertSame(ToolExecutionMode::Parallel, $definition->executionMode);
        $this->assertStringContainsString('implementation delegation', strtolower($joined));
        $this->assertStringContainsString('cannot launch fork or subagent', strtolower($joined));
        $this->assertStringContainsString('never target the same worktree/directory', strtolower($joined));
        $this->assertStringContainsString('never launch more than 3 forks concurrently', strtolower($joined));
        $this->assertStringContainsString('do not set model or thinking', strtolower($joined));
    }
}

final class FakeForkExecutionService implements ForkExecutionServiceInterface
{
    public ?string $lastTask = null;
    public ?string $lastModelOverride = null;
    public ?string $lastReasoningOverride = null;

    public function __construct(private readonly DeferredToolCompletionOutcome $outcome)
    {
    }

    public function execute(
        string $parentRunId,
        string $task,
        ?string $modelOverride = null,
        ?string $reasoningOverride = null,
    ): DeferredToolCompletionOutcome {
        $this->lastTask = $task;
        $this->lastModelOverride = $modelOverride;
        $this->lastReasoningOverride = $reasoningOverride;

        return $this->outcome;
    }
}

final class NarrowExecutionServiceLocator implements ContainerInterface
{
    public function __construct(private readonly FakeForkExecutionService $execution)
    {
    }

    public function get(string $id): FakeForkExecutionService
    {
        return $this->execution;
    }

    public function has(string $id): bool
    {
        return 'execution' === $id;
    }
}
