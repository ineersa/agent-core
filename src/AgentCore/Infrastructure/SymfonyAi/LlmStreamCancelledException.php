<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\SymfonyAi;

/**
 * Marks run cancellation from HTTP progress callbacks and transport
 * cancellation subscriptions during a silent or in-flight stream wait.
 *
 * HTTP or Amp transport layers may wrap this exception;
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
