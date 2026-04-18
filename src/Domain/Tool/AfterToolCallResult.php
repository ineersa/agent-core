<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

final readonly class AfterToolCallResult
{
    /**
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
     * @param array<int, array<string, mixed>> $content
     */
    public static function withContent(array $content): self
    {
        return new self(
            hasContentOverride: true,
            content: $content,
        );
    }

    public static function withDetails(mixed $details): self
    {
        return new self(
            hasDetailsOverride: true,
            details: $details,
        );
    }

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
