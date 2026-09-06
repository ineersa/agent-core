<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Extension\ChildRunExtensionAllowlistReaderInterface;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Tool\Event\ToolCallFailedEvent;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\Exception\ToolException;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionExceptionInterface;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolverInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Contract\JsonSchema\Factory;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
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
 *   rewrite hooks (rewrite the flat ToolCall arguments first)
 *   → native Toolbox::execute()
 *     → ToolCallRequested (policy hooks see rewritten flat args)
 *     → RawAwareToolCallArgumentResolver (typed DTO tools: wraps the flat
 *       provider map under the reflected parameter name, then the native
 *       resolver denormalizes the DTO; raw-array tools pass the flat map
 *       through under their `$arguments` parameter)
 *     → ToolCallArgumentsResolved (ValidateToolCallArgumentsListener)
 *     → handler invoke → ToolCallSucceeded/Failed
 *
 * Typed DTO tools are model-visible with flat arguments: the DTO's object
 * schema is exposed at the Tool root (no `{arguments: ...}` envelope), and
 * the flat provider map is wrapped internally before native resolution.
 *
 * Mutable registry semantics are preserved without a revision counter:
 * provider Tool metadata and the one-definition native Toolbox are memoized
 * per immutable ToolDefinitionDTO instance (WeakMap). Unchanged definitions
 * reuse both; an effective replacement creates a new DTO identity; removal
 * drops the registry's reference so the weak cache entries become
 * collectible; visibility filtering only changes enumeration. execute()
 * re-reads the current definition after rewrite hooks so a mutation can
 * never execute a stale handler through a cached Toolbox.
 */
final readonly class RegistryBackedToolbox implements ToolboxInterface
{
    /**
     * Provider-visible Tool metadata per canonical definition.
     *
     * @var \WeakMap<ToolDefinitionDTO, Tool>
     */
    private \WeakMap $metadataCache;

    /**
     * One-definition native Symfony Toolbox per canonical definition.
     *
     * @var \WeakMap<ToolDefinitionDTO, Toolbox>
     */
    private \WeakMap $toolboxCache;

    public function __construct(
        private ToolRegistryInterface $registry,
        private ToolCallArgumentResolverInterface $argumentResolver,
        private Factory $schemaFactory = new Factory(),
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?ExtensionHookRegistry $rewriteHookProvider = null,
        private ?StackToolExecutionContextAccessor $contextAccessor = null,
        private ?ChildRunExtensionAllowlistReaderInterface $extensionAllowlistReader = null,
        private ?LoggerInterface $logger = null,
    ) {
        $this->metadataCache = new \WeakMap();
        $this->toolboxCache = new \WeakMap();
    }

    /**
     * @return list<Tool>
     */
    public function getTools(): array
    {
        $tools = [];

        foreach ($this->registry->activeToolDefinitions() as $definition) {
            $tools[] = $this->metadataFor($definition);
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

        $rewrittenCall = $this->applyRewrites($toolCall);

        // Re-read the current definition after rewrite hooks: the registry
        // may have mutated since the first lookup, and the cached
        // one-definition Toolbox must never execute a stale handler.
        $definition = $this->registry->toolDefinition($rewrittenCall->getName());

        if (null === $definition) {
            throw ToolNotFoundException::notFoundForToolCall($rewrittenCall);
        }

        try {
            return $this->toolboxFor($definition)->execute($rewrittenCall);
        } catch (ToolExecutionExceptionInterface $e) {
            // The native ToolCallFailed event does not carry the original
            // provider ToolCall; publish the exact rewritten call while it
            // is still in scope so failure result hooks keep the flat
            // rewritten arguments and the tool call id.
            $this->eventDispatcher?->dispatch(new ToolCallFailedEvent(
                toolCall: $rewrittenCall,
                exception: $e instanceof ToolExecutionException && null !== $e->getPrevious() ? $e->getPrevious() : $e,
            ));

            if (!$e instanceof ToolExecutionException) {
                throw $e;
            }

            $previous = $e->getPrevious();

            // Thin outer translation: the native Toolbox wraps every handler
            // throwable in ToolExecutionException. A handler-thrown
            // ToolCallException must survive unchanged so ToolExecutor keeps
            // message/hint/retryable classification.
            if ($previous instanceof ToolCallException) {
                throw $previous;
            }

            // Resolver/denormalization failures (missing mandatory parameters,
            // type mismatches during DTO denormalization) carry
            // actionable correction detail the model can act on; the native
            // wrap would reduce them to a generic fault. Surface them as
            // non-retryable ToolCallException with the native message and
            // exception chain. Handler exceptions are NOT translated here.
            if ($previous instanceof ToolException || $previous instanceof NotNormalizableValueException) {
                throw new ToolCallException($previous->getMessage(), retryable: false, previous: $previous);
            }

            throw $e;
        }
    }

    /**
     * Build or reuse the provider-visible Tool metadata for one canonical
     * definition. Definitions are immutable value objects, so identity is a
     * stable cache key; a replacement registration creates a new definition
     * and therefore new metadata.
     */
    private function metadataFor(ToolDefinitionDTO $definition): Tool
    {
        if (isset($this->metadataCache[$definition])) {
            return $this->metadataCache[$definition];
        }

        return $this->metadataCache[$definition] = $this->buildMetadata($definition);
    }

    /**
     * Build or reuse the one-definition native Toolbox for one canonical
     * definition. The native Toolbox caches its metadata internally, so
     * reusing the instance also keeps the Tool metadata object identity
     * stable across calls for the same definition.
     */
    private function toolboxFor(ToolDefinitionDTO $definition): Toolbox
    {
        if (isset($this->toolboxCache[$definition])) {
            return $this->toolboxCache[$definition];
        }

        return $this->toolboxCache[$definition] = new Toolbox(
            tools: [$definition->handler],
            toolFactory: new SingleToolFactory($this->metadataFor($definition)),
            argumentResolver: $this->argumentResolver,
            logger: $this->logger ?? new NullLogger(),
            eventDispatcher: $this->eventDispatcher,
        );
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
     * Typed DTO handlers (parametersJsonSchema === null) get their provider
     * schema from Symfony AI's JsonSchema Factory directly
     * (buildParameters(handler::class, '__invoke')) so DTO types/constraints
     * and the provider-visible schema cannot drift. The registry definition
     * remains canonical for name/description. The provider-visible schema is
     * the single DTO parameter's object schema hoisted to the Tool root (flat
     * arguments, no parameter envelope); the argument resolver wraps flat
     * payloads back under the parameter name before native resolution.
     *
     * Raw-array handlers (runtime-provided schema) keep their schema and are
     * flagged so the argument resolver passes the flat provider map through.
     */
    private function buildMetadata(ToolDefinitionDTO $definition): Tool
    {
        if (null !== $definition->parametersJsonSchema) {
            return new Tool(
                reference: new ExecutionReference($definition->handler::class, '__invoke'),
                name: $definition->name,
                description: $definition->description,
                parameters: $this->normalizeRawParametersSchema($definition->parametersJsonSchema),
                metadata: ['raw_arguments' => true],
            );
        }

        $parameters = $this->schemaFactory->buildParameters($definition->handler::class, '__invoke');

        return new Tool(
            reference: new ExecutionReference($definition->handler::class, '__invoke'),
            name: $definition->name,
            description: $definition->description,
            parameters: $this->flattenDtoParameters($parameters, $definition->name),
        );
    }

    /**
     * Preserve JSON object shapes that PHP arrays cannot distinguish from
     * empty lists. Providers reject `parameters: []`, and JSON Schema
     * requires every `properties` value to encode as an object.
     *
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function normalizeRawParametersSchema(array $schema, bool $root = true): array
    {
        if ($root && [] === $schema) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }

        foreach ($schema as $keyword => $value) {
            if (!\is_array($value)) {
                continue;
            }

            if ('properties' === $keyword && [] === $value) {
                $schema[$keyword] = new \stdClass();

                continue;
            }

            $schema[$keyword] = $this->normalizeRawParametersSchema($value, false);
        }

        return $schema;
    }

    /**
     * Hoist the single DTO parameter's object schema to the Tool root.
     *
     * Symfony AI generates `{type: object, properties: {<param>: <DTO schema>},
     * required: [<param>], additionalProperties: false}` for a handler declared
     * `__invoke(SomeDto $arguments)`. The model must see the DTO fields flat,
     * so the parameter property's own object schema becomes the Tool's root
     * parameters (raw tools already expose flat schemas).
     *
     * Only the exact single-object-parameter shape is supported; anything else
     * (scalar parameters, multiple parameters) is an internal contract
     * violation and fails fast instead of silently producing a wrong schema.
     *
     * @param array<string, mixed>|null $parameters
     *
     * @return array<string, mixed>
     */
    private function flattenDtoParameters(?array $parameters, string $toolName): array
    {
        $properties = \is_array($parameters) ? ($parameters['properties'] ?? null) : null;

        if (!\is_array($parameters)
            || ($parameters['type'] ?? null) !== 'object'
            || !\is_array($properties)
            || 1 !== \count($properties)
        ) {
            throw new \LogicException(\sprintf('Typed tool "%s" must produce exactly one object parameter schema for flat DTO arguments, got: %s.', $toolName, null === $parameters ? 'null' : json_encode($parameters, \JSON_THROW_ON_ERROR)));
        }

        $dtoSchema = reset($properties);
        if (!\is_array($dtoSchema) || ($dtoSchema['type'] ?? null) !== 'object') {
            throw new \LogicException(\sprintf('Typed tool "%s" must take exactly one DTO (object) parameter for flat DTO arguments, got: %s.', $toolName, json_encode($dtoSchema, \JSON_THROW_ON_ERROR)));
        }

        return $this->normalizeNullableRequired($dtoSchema);
    }

    /**
     * Symfony AI's JsonSchema generation marks every DTO property required,
     * including nullable-with-default optional ones. Nullable properties are
     * optional by definition, so drop them from `required` to keep the
     * provider schema faithful to the DTO contract. Applied recursively so
     * nested object schemas (e.g. subagent task items) get the same treatment.
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
