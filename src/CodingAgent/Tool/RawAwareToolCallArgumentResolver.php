<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolverInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;

/**
 * Native argument-resolver decorator for Hatfield tool shapes.
 *
 * Raw-array handlers (MCP tools, public extension adapters, settings) receive
 * the provider argument map verbatim under their single `$arguments` parameter
 * — Symfony AI's resolver requires tool-call arguments keyed by parameter name,
 * and dynamic runtime schemas cannot be reflected into DTOs.
 *
 * Typed DTO handlers expose flat provider arguments (DTO fields at the Tool
 * root, see RegistryBackedToolbox::metadataFor()). Symfony AI's native
 * resolver expects the DTO value under the reflected method parameter name, so
 * the flat provider map is wrapped into `[<parameterName> => $flat]` before
 * delegation. The reflection here is the same ReflectionMethod the native
 * resolver performs on every call; it doubles as the internal invariant check
 * (exactly one class-typed parameter), not a separate reflection service.
 *
 * No argument validation happens here: missing/unknown/constraint handling
 * for raw-array tools is delegated to the MCP/extension handler or server,
 * and for typed tools to native denormalization +
 * ValidateToolCallArgumentsListener.
 */
final readonly class RawAwareToolCallArgumentResolver implements ToolCallArgumentResolverInterface
{
    public function __construct(
        private ToolCallArgumentResolverInterface $inner,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveArguments(Tool $metadata, ToolCall $toolCall): array
    {
        if (true === $metadata->getMetadataValue('raw_arguments', false)) {
            // Raw handlers are required to declare exactly one parameter named
            // `$arguments` (McpToolHandler, ExtensionToolHandlerAdapter).
            return ['arguments' => $toolCall->getArguments()];
        }

        // Typed DTO handlers: the provider-visible schema is the DTO's object
        // schema at the Tool root (flat), but the native resolver reads the
        // DTO value from the reflected parameter name. Wrap the flat map under
        // that name and let the native resolver denormalize as usual.
        $method = new \ReflectionMethod($metadata->getReference()->getClass(), $metadata->getReference()->getMethod());
        $parameters = $method->getParameters();
        $parameterType = 1 === \count($parameters) ? $parameters[0]->getType() : null;

        if (!$parameterType instanceof \ReflectionNamedType || $parameterType->isBuiltin()) {
            throw new \LogicException(\sprintf('Typed tool "%s" must declare exactly one class-typed parameter to receive flat DTO arguments; %d parameter(s) reflected on %s::%s().', $metadata->getName(), \count($parameters), $method->getDeclaringClass()->getName(), $method->getName()));
        }

        return $this->inner->resolveArguments(
            $metadata,
            new ToolCall(
                $toolCall->getId(),
                $toolCall->getName(),
                [$parameters[0]->getName() => $toolCall->getArguments()],
                $toolCall->getSignature(),
            ),
        );
    }
}
