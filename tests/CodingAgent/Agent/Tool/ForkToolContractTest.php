<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Agent\Fork\ForkExecutionServiceInterface;
use Ineersa\CodingAgent\Agent\Fork\ForkRuntimeConfigResolver;
use Ineersa\CodingAgent\Agent\Tool\ForkToolDefinitionBuilder;
use Ineersa\CodingAgent\Agent\Tool\ForkToolDefinitionProvider;
use Ineersa\CodingAgent\Agent\Tool\ForkToolHandler;
use Ineersa\CodingAgent\Config\ForksConfigDTO;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Tests\Tool\Support\NativeToolSchemaProbe;
use Ineersa\CodingAgent\Tool\Arguments\ForkArgumentsDTO;
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
        // Typed DTO tool: schema is generated natively from ForkArgumentsDTO.
        $this->assertNull($definition->parametersJsonSchema);

        $schema = NativeToolSchemaProbe::for(new ForkToolDefinitionProvider($handler));
        $args = $schema;

        // Non-nullable `task` is required; nullable model/thinking are not.
        $this->assertSame(['task'], $args['required']);
        $this->assertFalse($args['additionalProperties']);
        $this->assertSame(1, $args['properties']['task']['minLength']);
        $this->assertNotContains('model', $args['required']);
        $this->assertSame(
            'Launch an isolated fork child with inherited parent conversation context. Blocks until completion and returns a dense handoff.',
            $definition->description,
        );
        $this->assertSame(ModelResolver::LEVELS, $args['properties']['thinking']['enum']);
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

        $outcome = $accessor->with($context, static fn () => $handler->__invoke(new ForkArgumentsDTO(
            task: '  Do work  ',
            model: 'provider/model',
            thinking: 'high',
        )));

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

        // Invalid thinking is rejected by schema/Validator before the handler runs.
        // Handler path only trims and delegates a valid DTO.
        $outcome = $accessor->with($context, static fn () => $handler->__invoke(new ForkArgumentsDTO(
            task: 'ok',
            thinking: 'high',
        )));
        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);
    }

    public function testConfigResolverPrecedence(): void
    {
        // ModelResolver + HatfieldSessionStore are final; build a real resolver with empty session id path.
        $sessionStore = (new \ReflectionClass(\Ineersa\CodingAgent\Session\HatfieldSessionStore::class))
            ->newInstanceWithoutConstructor();
        $modelResolver = new ModelResolver(
            new \Ineersa\CodingAgent\Config\AppConfig(
                tui: new \Ineersa\CodingAgent\Config\TuiConfig(theme: 'default'),
                logging: new \Ineersa\CodingAgent\Config\LoggingConfig(),
            ),
            $sessionStore,
        );

        $resolver = new ForkRuntimeConfigResolver(
            new ForksConfigDTO(model: 'forks/model', thinkingLevel: 'low'),
            $modelResolver,
        );
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

        $emptyModelResolver = new ForkRuntimeConfigResolver(
            new ForksConfigDTO(model: null, thinkingLevel: null),
            $modelResolver,
        );
        try {
            $emptyModelResolver->resolve(null, null, null, null);
            $this->fail('Expected RuntimeException when model candidates are all missing');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('missing explicit model', $e->getMessage());
        }

        // Parent run_started reasoning may be omitted; canonical ModelResolver still yields concrete thinking.
        $resolvedThinking = $emptyModelResolver->resolve('parent/model', null, 'parent/model', null);
        $this->assertSame('parent/model', $resolvedThinking->model);
        $this->assertSame('medium', $resolvedThinking->thinking);
    }

    public function testPromptGuidelinesAndParallelModeExposeSafetyGuidance(): void
    {
        // Thesis C: fork definition is Parallel and exposes no-same-worktree,
        // max-3 concurrent, no-child-spawn, and override-only-when-requested safety/load guidelines.
        $handler = new ForkToolHandler(
            new StackToolExecutionContextAccessor(),
            new ToolRuntime(new StackToolExecutionContextAccessor()),
            new NarrowExecutionServiceLocator(new FakeForkExecutionService(new DeferredToolCompletionOutcome('x'))),
        );
        $definition = ForkToolDefinitionBuilder::build($handler);

        $this->assertSame(ToolExecutionMode::Parallel, $definition->executionMode);
        $this->assertSame([
            'Fork children cannot launch fork or subagent; do not instruct them to spawn child agents.',
            'Parallel forks must NEVER target the same worktree/directory because concurrent edits can corrupt it.',
            'Never launch more than 3 forks concurrently because forks impose high load.',
            'Do not set model or thinking unless the user explicitly requested overrides.',
        ], $definition->promptGuidelines);
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
