<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Extension\ChildRunExtensionAllowlistReaderInterface;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Symfony\AI\Agent\Toolbox\Event\ToolCallArgumentsResolved;
use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Agent\Toolbox\Exception\InvalidToolCallArgumentsException;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionExceptionInterface;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolverInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Registry-backed Symfony AI Toolbox.
 *
 * Reads all active tool definitions (permanent, dynamic, and extension-registered)
 * from ToolRegistryInterface and makes them available for execution through the
 * Symfony AI ToolboxInterface contract.
 *
 * Execution lifecycle (rewrites preserved before policy):
 *   rewrite hooks → ToolCallRequested (policy sees rewritten raw args)
 *   → JSON Schema validation against the canonical provider schema
 *   → native ToolCallArgumentResolver (typed DTO for built-ins; flat array for dynamic)
 *   → ToolCallArgumentsResolved (ValidateToolCallArgumentsListener for objects)
 *   → handler invoke → succeeded/failed
 *
 * Mutable registry semantics are preserved: getTools()/toolDefinition() always
 * read the live registry (no long-lived native Toolbox metadata cache).
 *
 * @see ToolDefinitionDTO
 * @see ToolHandlerInterface
 */
final readonly class RegistryBackedToolbox implements ToolboxInterface
{
    public function __construct(
        private ToolRegistryInterface $registry,
        private ToolCallArgumentResolverInterface $argumentResolver,
        private ToolCallArgumentsValidator $argumentsValidator,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?ExtensionHookRegistry $rewriteHookProvider = null,
        private ?StackToolExecutionContextAccessor $contextAccessor = null,
        private ?ChildRunExtensionAllowlistReaderInterface $extensionAllowlistReader = null,
    ) {
    }

    /**
     * @return list<Tool>
     */
    public function getTools(): array
    {
        $definitions = $this->registry->activeToolDefinitions();
        $tools = [];

        foreach ($definitions as $definition) {
            $tools[] = $this->toSymfonyTool($definition);
        }

        return $tools;
    }

    /**
     * @throws ToolNotFoundException when the tool name is not in the registry
     */
    public function execute(ToolCall $toolCall): ToolResult
    {
        $definition = $this->registry->toolDefinition($toolCall->getName());

        if (null === $definition) {
            throw ToolNotFoundException::notFoundForToolCall($toolCall);
        }

        $metadata = $this->toSymfonyTool($definition);
        $handler = $definition->handler;

        // ── Pre-event rewrite phase ──
        $arguments = $toolCall->getArguments();

        if (null !== $this->rewriteHookProvider) {
            $rewriteHooks = $this->rewriteHookProvider->rewriteHooksForTool(
                $toolCall->getName(),
                $this->resolveAllowedExtensionsForCurrentRun(),
            );

            $hookIndex = 0;
            foreach ($rewriteHooks as $hook) {
                $context = new ToolCallContextDTO(
                    toolCallId: $toolCall->getId(),
                    toolName: $toolCall->getName(),
                    arguments: $arguments,
                    orderIndex: $hookIndex++,
                );

                $rewritten = $hook->rewriteArguments($context);
                if (null !== $rewritten) {
                    $arguments = $rewritten;
                }
            }
        }

        $eventToolCall = $arguments !== $toolCall->getArguments()
            ? new ToolCall($toolCall->getId(), $toolCall->getName(), $arguments)
            : $toolCall;

        $requestedEvent = new ToolCallRequested($eventToolCall, $metadata);
        $this->eventDispatcher?->dispatch($requestedEvent);

        if ($requestedEvent->isDenied()) {
            return new ToolResult($toolCall, $requestedEvent->getDenialReason() ?? 'Tool execution denied.');
        }

        if ($requestedEvent->hasResult()) {
            return $requestedEvent->getResult() ?? new ToolResult($toolCall, null);
        }

        $resolvedArguments = [];

        try {
            // Schema validation on the final rewritten flat provider args.
            $this->argumentsValidator->assertValid(
                $arguments,
                $definition->parametersJsonSchema,
                $toolCall->getName(),
            );

            $resolutionCall = $this->toolCallForResolution($handler, $eventToolCall, $arguments);
            $resolvedArguments = $this->argumentResolver->resolveArguments($metadata, $resolutionCall);

            $this->eventDispatcher?->dispatch(new ToolCallArgumentsResolved($handler, $metadata, $resolvedArguments));

            $result = new ToolResult($toolCall, $handler(...$resolvedArguments));

            $this->eventDispatcher?->dispatch(new ToolCallSucceeded($handler, $metadata, $resolvedArguments, $result));

            return $result;
        } catch (ToolExecutionExceptionInterface $exception) {
            $this->eventDispatcher?->dispatch(new ToolCallFailed($handler, $metadata, $resolvedArguments, $exception));

            throw $exception;
        } catch (\Throwable $exception) {
            $this->eventDispatcher?->dispatch(new ToolCallFailed($handler, $metadata, $resolvedArguments, $exception));

            throw ToolExecutionException::executionFailed($toolCall, $exception);
        }
    }

    private function toSymfonyTool(ToolDefinitionDTO $definition): Tool
    {
        return new Tool(
            reference: new ExecutionReference(
                class: $definition->handler::class,
                method: '__invoke',
            ),
            name: $definition->name,
            description: $definition->description,
            parameters: $definition->parametersJsonSchema,
        );
    }

    /**
     * Bridge flat provider schemas into native resolver parameter names.
     *
     * Built-in handlers take one aggregate DTO parameter (e.g. $arguments).
     * Provider schemas are flat, so wrap the flat object under that parameter
     * name for denormalization without changing the provider-visible schema.
     *
     * Array-parameter handlers (MCP/extension adapters) already match the flat schema.
     *
     * @param array<string, mixed> $arguments
     */
    private function toolCallForResolution(ToolHandlerInterface $handler, ToolCall $toolCall, array $arguments): ToolCall
    {
        try {
            $method = new \ReflectionMethod($handler, '__invoke');
        } catch (\ReflectionException $e) {
            throw new InvalidToolCallArgumentsException(\sprintf('Tool handler "%s" is not invokable.', $handler::class), 0, $e);
        }

        $parameters = $method->getParameters();
        if (1 !== \count($parameters)) {
            throw new InvalidToolCallArgumentsException(\sprintf('Tool handler "%s::__invoke" must declare exactly one parameter.', $handler::class));
        }

        // Provider schemas are flat; handlers take one aggregate parameter (DTO or array).
        // Always nest under that parameter name so ToolCallArgumentResolver can resolve it.
        return new ToolCall(
            $toolCall->getId(),
            $toolCall->getName(),
            [$parameters[0]->getName() => $arguments],
        );
    }

    /**
     * @return list<string>|null null = parent/global (no filter)
     */
    private function resolveAllowedExtensionsForCurrentRun(): ?array
    {
        if (null === $this->extensionAllowlistReader || null === $this->contextAccessor) {
            return null;
        }

        $runId = $this->contextAccessor->current()?->runId();
        if (null === $runId || '' === $runId) {
            return null;
        }

        return $this->extensionAllowlistReader->readAllowedExtensions($runId);
    }
}
