<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

/**
 * Data transfer object for registering a permanent tool via the extension API.
 *
 * Extensions construct this DTO and pass it to ExtensionApiInterface::registerTool().
 * The Hatfield registry maps these fields to its internal permanent tool metadata
 * for provider schema exposure, execution allowlist, and system prompt summary.
 *
 * This DTO is immutable. All properties are readonly.
 * Dynamic tool management uses separate ToolRegistry APIs and is not exposed
 * through the public Extension API in v1.
 *
 * Handler may be an argument-only handler or a contextual handler that
 * receives {@see ToolInvocationContextDTO}.
 */
final readonly class ToolRegistrationDTO
{
    /**
     * @param string                                                                $name                 unique tool name exposed to the LLM
     * @param string                                                                $description          short description for the provider schema
     * @param array<string, mixed>                                                  $parametersJsonSchema JSON Schema describing tool parameters
     * @param ExtensionToolHandlerInterface|ContextualExtensionToolHandlerInterface $handler              extension-facing tool execution handler
     * @param string|null                                                           $promptSummary        optional one-line summary for the system prompt available-tools section
     * @param string[]                                                              $promptGuidelines     optional bullet-point guidelines for the system prompt guidelines section
     * @param int|null                                                              $timeoutSeconds       optional cooperative timeout budget for this tool; null means no ambient deadline
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parametersJsonSchema,
        public readonly ExtensionToolHandlerInterface|ContextualExtensionToolHandlerInterface $handler,
        public readonly ?string $promptSummary = null,
        public readonly array $promptGuidelines = [],
        public readonly ?int $timeoutSeconds = null,
    ) {
        if (null !== $this->timeoutSeconds && $this->timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('ToolRegistrationDTO timeoutSeconds must be null or a positive integer.');
        }
    }
}
