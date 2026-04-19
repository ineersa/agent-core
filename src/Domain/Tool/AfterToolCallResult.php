<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * The AfterToolCallResult class represents the immutable outcome of a tool execution within the agent domain, encapsulating content, details, and error status. It provides a fluent interface for constructing result instances with optional overrides for content and details.
 */
final readonly class AfterToolCallResult
{
    /**
     * Initializes the result with content, details, and error state flags.
     *
     * @param array<int, array<string, mixed>> $content
     */
    public function __construct(
        public bool $hasContentOverride = false,
        public array $content = [],
        public bool $hasDetailsOverride = false,
        public mixed $details = null,
        public ?bool $isError = null,
    ) {
    }

    /**
     * Creates a new instance with the specified content array.
     *
     * @param array<int, array<string, mixed>> $content
     */
    public static function withContent(array $content): self
    {
        return new self(
            hasContentOverride: true,
            content: $content,
        );
    }

    /**
     * Creates a new instance with the specified details payload.
     */
    public static function withDetails(mixed $details): self
    {
        return new self(
            hasDetailsOverride: true,
            details: $details,
        );
    }

    /**
     * Creates a new instance with the specified error status flag.
     */
    public function withIsError(bool $isError): self
    {
        return new self(
            hasContentOverride: $this->hasContentOverride,
            content: $this->content,
            hasDetailsOverride: $this->hasDetailsOverride,
            details: $this->details,
            isError: $isError,
        );
    }
}
