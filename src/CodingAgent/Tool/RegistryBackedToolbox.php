<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Extension\ChildRunExtensionAllowlistReaderInterface;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\Exception\ToolException;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolverInterface;
use Symfony\AI\Agent\Toolbox\ToolFactory\ReflectionToolFactory;
use Symfony\AI\Agent\Toolbox\ToolFactoryInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Registry-backed Symfony AI Toolbox.
 *
 * Reads all active tool definitions (permanent, dynamic, and extension-registered)
 * from ToolRegistryInterface and delegates execution to the native Symfony AI
 * Toolbox: native ToolCallArgumentResolver, ToolCallArgumentsResolved with
 * ValidateToolCallArgumentsListener, and native lifecycle events.
 *
 * Execution lifecycle:
 *   rewrite hooks (rewrite the ToolCall arguments first)
 *   → native Toolbox::execute()
 *     → ToolCallRequested (policy hooks see rewritten args)
 *     → native ToolCallArgumentResolver (DTO for typed tools; raw map for
 *       raw-array tools via RawAwareToolCallArgumentResolver)
 *     → ToolCallArgumentsResolved (ValidateToolCallArgumentsListener)
 *     → handler invoke → ToolCallSucceeded/Failed
 *
 * Mutable registry semantics are preserved: getTools() and execute() always
 * read the live registry, and a fresh native Toolbox is built per execution
 * so dynamic tool changes are never served from a stale metadata cache.
 */
final readonly class RegistryBackedToolbox implements ToolboxInterface
{
    public function __construct(
        private ToolRegistryInterface $registry,
        private ToolCallArgumentResolverInterface $argumentResolver,
        private ToolFactoryInterface $nativeToolFactory = new ReflectionToolFactory(),
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?ExtensionHookRegistry $rewriteHookProvider = null,
        private ?StackToolExecutionContextAccessor $contextAccessor = null,
        private ?ChildRunExtensionAllowlistReaderInterface $extensionAllowlistReader = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return list<Tool>
     */
    public function getTools(): array
    {
        return array_map(
            fn (ToolDefinitionDTO $definition): Tool => $this->metadataFor($definition),
            $this->registry->activeToolDefinitions(),
        );
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

        $rewrittenCall = $this->applyRewrites($toolCall);

        $toolbox = new Toolbox(
            tools: [$definition->handler],
            toolFactory: new DefinitionToolFactory($this->metadataFor($definition)),
            argumentResolver: $this->argumentResolver,
            logger: $this->logger ?? new NullLogger(),
            eventDispatcher: $this->eventDispatcher,
        );

        try {
            return $toolbox->execute($rewrittenCall);
        } catch (ToolExecutionException $e) {
            // Thin outer translation: the native Toolbox wraps every handler
            // throwable in ToolExecutionException. A handler-thrown
            // ToolCallException must survive unchanged so ToolExecutor keeps
            // message/hint/retryable classification. All other failures keep
            // their native wrapping (FaultTolerantToolbox makes them
            // deterministic model-visible results).
            if ($e->getPrevious() instanceof ToolCallException) {
                throw $e->getPrevious();
            }

            throw $e;
        }
    }

    private function applyRewrites(ToolCall $toolCall): ToolCall
    {
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

        if ($arguments === $toolCall->getArguments()) {
            return $toolCall;
        }

        return new ToolCall($toolCall->getId(), $toolCall->getName(), $arguments);
    }

    /**
     * Build the native Symfony AI Tool metadata for one registered definition.
     *
     * Typed DTO handlers (parametersJsonSchema === null) get their metadata
     * from Symfony AI's native ReflectionToolFactory (AsTool + JsonSchema
     * Factory) so DTO types/constraints and the provider-visible schema cannot
     * drift. The registry definition remains canonical for name/description.
     *
     * Raw-array handlers (runtime-provided schema) keep their schema and are
     * flagged so the argument resolver passes the flat provider map through.
     */
    private function metadataFor(ToolDefinitionDTO $definition): Tool
    {
        if (null !== $definition->parametersJsonSchema) {
            return new Tool(
                reference: new ExecutionReference($definition->handler::class, '__invoke'),
                name: $definition->name,
                description: $definition->description,
                parameters: $definition->parametersJsonSchema,
                metadata: ['raw_arguments' => true],
            );
        }

        $native = $this->nativeMetadataFor($definition);

        return new Tool(
            reference: $native->getReference(),
            name: $definition->name,
            description: $definition->description,
            parameters: $this->normalizeNullableRequired($native->getParameters() ?? []),
        );
    }

    private function nativeMetadataFor(ToolDefinitionDTO $definition): Tool
    {
        $metadata = iterator_to_array($this->nativeToolFactory->getTool($definition->handler), false);

        if ([] === $metadata) {
            throw ToolException::missingAttribute($definition->handler::class);
        }

        return $metadata[0];
    }

    /**
     * Symfony AI's JsonSchema generation marks every DTO property required,
     * including nullable-with-default optional ones. Nullable properties are
     * optional by definition, so drop them from `required` to keep the
     * provider schema faithful to the DTO contract. Applied recursively so
     * nested object schemas (e.g. the {arguments} envelope or subagent task
     * items) get the same treatment.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function normalizeNullableRequired(array $schema): array
    {
        if (isset($schema['required'], $schema['properties']) && \is_array($schema['properties'])) {
            $schema['required'] = array_values(array_filter(
                $schema['required'],
                static fn (mixed $name): bool => !\is_string($name) || !self::isNullableProperty($schema['properties'][$name] ?? null),
            ));

            if ([] === $schema['required']) {
                unset($schema['required']);
            }
        }

        foreach ($schema as $key => $value) {
            // Recurse into nested object schemas (including `properties`);
            // `required` is a flat list of names and needs no recursion.
            if (\is_array($value) && 'required' !== $key) {
                $schema[$key] = $this->normalizeNullableRequired($value);
            }
        }

        return $schema;
    }

    private static function isNullableProperty(mixed $propertySchema): bool
    {
        if (!\is_array($propertySchema)) {
            return false;
        }

        // A NotNull/NotBlank constraint marks the property semantically
        // required (`nullable: false`) even when the PHP type admits null;
        // such properties must stay in `required`.
        if (false === ($propertySchema['nullable'] ?? null)) {
            return false;
        }

        $type = $propertySchema['type'] ?? null;

        return \is_array($type) && \in_array('null', $type, true);
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
