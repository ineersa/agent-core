<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

/**
 * Thrown from the LLM HTTP progress hook when a run cancellation becomes visible
 * during a silent or in-flight stream wait.
 *
 * Transport layers may wrap this as a {@see \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface};
 * {@see LlmPlatformAdapter} unwraps the chain and treats it as an aborted stream.
 */
final class LlmStreamCancelledException extends \RuntimeException
{
    public const string MESSAGE = 'LLM stream cancelled.';

    public function __construct(string $message = self::MESSAGE, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
