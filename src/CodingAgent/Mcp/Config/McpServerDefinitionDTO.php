<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Mcp\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Typed MCP server definition.
 *
 * Represents a single server entry from .hatfield/mcp.json.
 * Hydrated via Symfony Serializer + Validator from raw JSON fields.
 * {@see $transportType} is derived from command/url and is not a user config field.
 */
final readonly class McpServerDefinitionDTO
{
    public ?McpTransportTypeEnum $transportType;

    /**
     * @param list<string>              $args
     * @param array<string, string>     $env
     * @param array<string, string>     $headers
     * @param list<string>              $excludeTools
     * @param McpTransportTypeEnum|null $transportType Explicit transport for direct construction; derived when null
     */
    public function __construct(
        #[Assert\NotBlank(message: 'server name must be a non-empty string.')]
        public string $name,

        #[Assert\Type('bool', message: '"enabled" must be a boolean.')]
        public bool $enabled = true,

        #[Assert\When(
            expression: 'this.command !== null',
            constraints: [
                new Assert\NotBlank(message: '"command" must be a non-empty string.'),
            ],
        )]
        public ?string $command = null,

        #[Assert\All([
            new Assert\Type('string', message: '"args" entries must be strings.'),
        ])]
        public array $args = [],

        #[Assert\All([
            new Assert\Type('string', message: '"env" values must be strings.'),
        ])]
        public array $env = [],

        #[Assert\When(
            expression: 'this.cwd !== null',
            constraints: [
                new Assert\NotBlank(message: '"cwd" must be a non-empty string.'),
            ],
        )]
        public ?string $cwd = null,

        #[Assert\When(
            expression: 'this.url !== null',
            constraints: [
                new Assert\NotBlank(message: '"url" must be a non-empty string.'),
            ],
        )]
        public ?string $url = null,

        #[Assert\All([
            new Assert\Type('string', message: '"headers" values must be strings.'),
        ])]
        public array $headers = [],

        #[SerializedName('timeoutMs')]
        #[Assert\Positive(message: '"timeoutMs" must be a positive integer.')]
        public int $timeoutMs = 30000,

        #[SerializedName('startupTimeoutMs')]
        #[Assert\Positive(message: '"startupTimeoutMs" must be a positive integer.')]
        public int $startupTimeoutMs = 30000,

        public McpServerAvailabilityEnum $availability = McpServerAvailabilityEnum::All,

        #[SerializedName('excludeTools')]
        #[Assert\All([
            new Assert\Type('string', message: '"excludeTools" entries must be strings.'),
        ])]
        public array $excludeTools = [],

        ?McpTransportTypeEnum $transportType = null,
    ) {
        $hasCommand = null !== $this->command && '' !== $this->command;
        $hasUrl = null !== $this->url && '' !== $this->url;

        $this->transportType = $transportType ?? (
            $hasCommand
                ? McpTransportTypeEnum::STDIO
                : ($hasUrl ? McpTransportTypeEnum::HTTP : null)
        );
    }

    #[Assert\Callback]
    public function validateShape(ExecutionContextInterface $context): void
    {
        $hasCommand = null !== $this->command && '' !== $this->command;
        $hasUrl = null !== $this->url && '' !== $this->url;

        if ($hasCommand && $hasUrl) {
            $context->buildViolation('cannot define both "command" (STDIO) and "url" (HTTP). Choose exactly one transport.')
                ->addViolation();
        }

        if ([] !== $this->args && !array_is_list($this->args)) {
            $context->buildViolation('"args" must be a list (sequential array).')
                ->atPath('args')
                ->addViolation();
        }

        if ([] !== $this->excludeTools && !array_is_list($this->excludeTools)) {
            $context->buildViolation('"excludeTools" must be a list (sequential array).')
                ->atPath('excludeTools')
                ->addViolation();
        }
    }
}
