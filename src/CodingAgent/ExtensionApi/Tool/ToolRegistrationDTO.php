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
 */
final readonly class ToolRegistrationDTO
{
    /**
     * @param string                        $name                 unique tool name exposed to the LLM
     * @param string                        $description          short description for the provider schema
     * @param array<string, mixed>          $parametersJsonSchema JSON Schema describing tool parameters
     * @param ExtensionToolHandlerInterface $handler              extension-facing tool execution handler
     * @param string|null                   $promptSummary        optional one-line summary for the system prompt available-tools section
     * @param string[]                      $promptGuidelines     optional bullet-point guidelines for the system prompt guidelines section
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parametersJsonSchema,
        public readonly ExtensionToolHandlerInterface $handler,
        public readonly ?string $promptSummary = null,
        public readonly array $promptGuidelines = [],
    ) {
    }
}
