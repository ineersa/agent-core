<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

/**
 * Structured exception for tool call failures.
 *
 * Tools MUST throw this (or a subclass) instead of raw RuntimeException
 * or InvalidArgumentException. ToolExecutor unwraps the structured fields
 * (error message, retryable flag, hint) into the ToolResult for the LLM,
 * enabling the model to decide whether and how to retry.
 *
 * Cancellation and timeout exceptions are NOT ToolCallException — those
 * are runtime concerns handled separately by ToolRuntime.
 */
class ToolCallException extends \RuntimeException
{
    /**
     * @param string          $error     Human-readable error message for the LLM
     * @param bool            $retryable Whether the LLM should retry the same call
     * @param string|null     $hint      Guidance for the LLM on how to fix the call
     * @param \Throwable|null $previous  Previous exception
     */
    public function __construct(
        string $error,
        private readonly bool $retryable = false,
        private readonly ?string $hint = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($error, 0, $previous);
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    public function hint(): ?string
    {
        return $this->hint;
    }
}
